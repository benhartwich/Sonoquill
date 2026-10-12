<?php
declare(strict_types=1);

namespace Sonoquill\Admin;

use Sonoquill\Db\EpisodeRepository;
use Sonoquill\Segments\SegmentRepository;
use Sonoquill\Settings\SettingsPage;
use Sonoquill\Voice\DictionaryWriter;
use Sonoquill\Voice\PronunciationDictionary;

/**
 * Path B: create a dictionary rule directly from the segment row.
 *
 * The two paths are deliberately ordered: the dictionary rule comes first.
 * For a recurring proper name, re-recording is the wrong answer — it fixes
 * the problem in this one episode and in no other.
 */
final class DictionaryController
{
    public const ACTION = 'aaspf_add_rule';
    public const NONCE  = 'aaspf_rule';

    public const ACTION_PIN = 'aaspf_pin_dictionary';

    public static function register(): void
    {
        add_action('wp_ajax_' . self::ACTION, [self::class, 'handle']);
        add_action('admin_post_' . self::ACTION_PIN, [self::class, 'handlePin']);
    }

    /** Link for the "Pin latest version" button (nonce-protected). */
    public static function pinUrl(): string
    {
        return wp_nonce_url(add_query_arg('action', self::ACTION_PIN, admin_url('admin-post.php')), self::ACTION_PIN);
    }

    /**
     * Resolves the latest dictionary version at ElevenLabs and pins it.
     */
    public static function handlePin(): void
    {
        if (!current_user_can(SettingsPage::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to do this.', 'sonoquill'), '', ['response' => 403]);
        }
        check_admin_referer(self::ACTION_PIN);

        try {
            $version = \Sonoquill\Voice\DictionaryPin::pinLatest();
            /* translators: %s: dictionary version ID */
            EpisodeActions::notice('success', sprintf(__('Dictionary version %s pinned.', 'sonoquill'), $version));
        } catch (\Throwable $e) {
            EpisodeActions::notice('error', $e->getMessage());
        }

        wp_safe_redirect(\Sonoquill\Admin\Urls::settings());
        exit;
    }

    public static function handle(): void
    {
        if (!current_user_can(SettingsPage::CAPABILITY)) {
            wp_send_json_error(['message' => __('You do not have permission to do this.', 'sonoquill')], 403);
        }

        check_ajax_referer(self::NONCE, 'nonce');

        $grapheme = isset($_POST['grapheme']) ? sanitize_text_field(wp_unslash((string) $_POST['grapheme'])) : '';
        $alias = isset($_POST['alias']) ? sanitize_text_field(wp_unslash((string) $_POST['alias'])) : '';
        $ipa = isset($_POST['ipa']) ? sanitize_text_field(wp_unslash((string) $_POST['ipa'])) : '';
        $segmentId = isset($_POST['segment']) ? (int) $_POST['segment'] : 0;

        try {
            $result = DictionaryWriter::addRule($grapheme, $ipa, $alias);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()], 400);
        }

        // The cache is keyed to the version identifier and therefore expires
        // on its own. Clear it anyway, just to be safe.
        PronunciationDictionary::forget();

        $segment = SegmentRepository::find($segmentId);
        if ($segment !== null) {
            EpisodeRepository::log((int) $segment['episode_id'], 'woerterbuch', sprintf(
                /* translators: 1: created or replaced, 2: rule type (phoneme or alias), 3: term, 4: IPA or alias pronunciation, 5: dictionary version ID, 6: number of rules */
                __('Rule %1$s (%2$s): "%3$s" is spoken as "%4$s". New version %5$s with %6$d rules.', 'sonoquill'),
                !empty($result['ersetzt']) ? __('replaced', 'sonoquill') : __('created', 'sonoquill'),
                (string) ($result['art'] ?? ''),
                $grapheme,
                $result['art'] === 'phoneme' ? $ipa : $alias,
                $result['version_id'],
                $result['rules']
            ));
        }

        wp_send_json_success([
            'message' => sprintf(
                /* translators: 1: created or replaced, 2: term, 3: number of rules */
                __('Rule for "%2$s" %1$s. The dictionary now has %3$d rules and is bound. Regenerate the affected segments for it to take effect.', 'sonoquill'),
                !empty($result['ersetzt']) ? __('replaced', 'sonoquill') : __('created', 'sonoquill'),
                $grapheme,
                $result['rules']
            ),
            'version' => $result['version_id'],
            'datei'   => $result['datei'],
        ]);
    }
}
