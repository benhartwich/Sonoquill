<?php
declare(strict_types=1);

namespace Sonoquill\Admin;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for the run log and e-mails; they are escaped where they are displayed.
// phpcs:disable WordPress.Security.NonceVerification.Missing -- Every handler verifies its nonce first (guard() / check_ajax_referer()).
// phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing files in the plugin's own storage directory.

use Sonoquill\Db\EpisodeRepository;
use Sonoquill\Db\EpisodeStatus;
use Sonoquill\Pipeline\Gate;
use Sonoquill\Pipeline\Scheduler as PipelineScheduler;
use Sonoquill\Settings\SettingsPage;
use Sonoquill\Text\DocxParser;
use Sonoquill\Text\PlainTextParser;
use Sonoquill\Text\SourceDocument;
use Sonoquill\Text\Sanitizer;

/**
 * The form actions of the episode view.
 *
 * Each one checks capabilities and the nonce, writes feedback into a transient and
 * redirects back — no state in the URL, no repetition on reload.
 */
final class EpisodeActions
{
    public const ACTION_CREATE      = 'aaspf_episode_create';
    public const ACTION_FROM_POST   = 'aaspf_episode_from_post';
    public const ACTION_REDIGAT     = 'aaspf_episode_redigat';
    public const ACTION_SAVE_SCRIPT = 'aaspf_episode_save_script';
    public const ACTION_APPROVE     = 'aaspf_episode_approve';
    public const ACTION_DELETE      = 'aaspf_episode_delete';
    public const ACTION_AUDIO       = 'aaspf_episode_audio';
    public const ACTION_MONTAGE     = 'aaspf_episode_montage';
    public const ACTION_FLAG        = 'aaspf_segment_flag';
    public const ACTION_REGENERATE  = 'aaspf_segment_regenerate';
    public const ACTION_PRODUCE     = 'aaspf_episode_produce';
    public const ACTION_CLEANUP     = 'aaspf_episode_cleanup';
    public const ACTION_RESUME      = 'aaspf_episode_resume';
    public const ACTION_APPROVE_AUDIO = 'aaspf_episode_approve_audio';
    public const ACTION_HIDE        = 'aaspf_episode_ausblenden';
    public const ACTION_MUSIC       = 'aaspf_musik_upload';

    private const NOTICE_TRANSIENT = 'aaspf_episode_notice_';

    public static function register(): void
    {
        foreach ([
            self::ACTION_CREATE      => 'handleCreate',
            self::ACTION_FROM_POST   => 'handleFromPost',
            self::ACTION_REDIGAT     => 'handleRedigat',
            self::ACTION_SAVE_SCRIPT => 'handleSaveScript',
            self::ACTION_APPROVE     => 'handleApprove',
            self::ACTION_DELETE      => 'handleDelete',
            self::ACTION_AUDIO       => 'handleAudio',
            self::ACTION_MONTAGE     => 'handleMontage',
            self::ACTION_FLAG        => 'handleFlag',
            self::ACTION_REGENERATE  => 'handleRegenerate',
            self::ACTION_PRODUCE     => 'handleProduce',
            self::ACTION_CLEANUP     => 'handleCleanup',
            self::ACTION_RESUME      => 'handleResume',
            self::ACTION_APPROVE_AUDIO => 'handleApproveAudio',
            self::ACTION_HIDE        => 'handleHide',
            self::ACTION_MUSIC       => 'handleMusicUpload',
        ] as $action => $method) {
            add_action('admin_post_' . $action, [self::class, $method]);
        }
    }

    public static function handleCreate(): void
    {
        self::guard(self::ACTION_CREATE);

        try {
            $document = self::readSubmittedDocument();
        } catch (\Throwable $e) {
            self::notice('error', $e->getMessage());
            self::back();
        }

        if ($document->isEmpty()) {
            self::notice('error', __('The source document contains no text.', 'sonoquill'));
            self::back();
        }

        $filename = isset($_FILES['docx']['name'])
            ? sanitize_file_name(wp_unslash((string) $_FILES['docx']['name']))
            : '';

        $id = \Sonoquill\Source\EpisodeFactory::fromDocument(
            $document,
            \Sonoquill\Source\UploadSource::ID,
            '',
            $filename,
            !empty($_POST['auto_chain'])
        );

        self::notice('success', __('Source document imported.', 'sonoquill'));
        self::back($id);
    }

    /**
     * Creates an episode from a post or a page.
     */
    public static function handleFromPost(): void
    {
        self::guard(self::ACTION_FROM_POST);

        $postId = !empty($_POST['post_id_manual'])
            ? absint(wp_unslash($_POST['post_id_manual']))
            : absint(wp_unslash($_POST['post_id'] ?? 0));
        $post = $postId > 0 ? get_post($postId) : null;

        if (!$post instanceof \WP_Post || !in_array($post->post_type, \Sonoquill\Source\PostSource::postTypes(), true)) {
            self::notice('error', __('This post does not exist, or it cannot become an episode.', 'sonoquill'));
            self::backToNew();
        }
        if (!current_user_can('read_post', $post->ID)) {
            self::notice('error', __('You are not allowed to read this post.', 'sonoquill'));
            self::backToNew();
        }

        $document = \Sonoquill\Source\PostSource::document($post);
        if ($document->isEmpty()) {
            self::notice('error', __('The post contains no text that can be read aloud.', 'sonoquill'));
            self::backToNew();
        }

        $id = \Sonoquill\Source\EpisodeFactory::fromDocument(
            $document,
            \Sonoquill\Source\PostSource::ID,
            (string) $post->ID,
            sprintf('%s (#%d)', html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8'), $post->ID),
            !empty($_POST['auto_chain'])
        );

        self::notice('success', __('Post imported.', 'sonoquill'));
        self::back($id);
    }

    public static function handleRedigat(): void
    {
        self::guard(self::ACTION_REDIGAT);
        $id = self::episodeId();

        if (PipelineScheduler::queueRedigat($id)) {
            EpisodeRepository::log($id, 'redigat', __('Scheduled.', 'sonoquill'));
            self::notice('success', __('The edit is scheduled and runs in the background.', 'sonoquill'));
        } else {
            self::notice('error', __('The job was not accepted by Action Scheduler.', 'sonoquill'));
        }

        self::back($id);
    }

    public static function handleSaveScript(): void
    {
        self::guard(self::ACTION_SAVE_SCRIPT);
        $id = self::episodeId();

        // Allowlist sanitiser: plain text plus the <break> tags the speech
        // synthesis needs (see Sanitizer).
        $script = isset($_POST['script_text'])
            ? Sanitizer::script((string) wp_unslash($_POST['script_text'])) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by Sanitizer::script()
            : '';

        // Overridden deviations: only those that can be overridden at all.
        $submitted = isset($_POST['acknowledged']) && is_array($_POST['acknowledged'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['acknowledged']))
            : [];
        $allowed = Gate::acknowledgeableKeys($id);
        $acknowledged = array_values(array_intersect($submitted, $allowed));

        EpisodeRepository::update($id, [
            'script_text'       => $script,
            'diff_acknowledged' => (string) wp_json_encode($acknowledged),
        ]);

        $result = Gate::evaluate($id);

        EpisodeRepository::log($id, 'freigabe', sprintf(
            /* translators: 1: number of characters in the script, 2: comma-separated list of confirmed deviations or "none" */
            __('Script saved (%1$d characters). Confirmed deviations: %2$s.', 'sonoquill'),
            mb_strlen($script),
            $acknowledged === [] ? __('none', 'sonoquill') : implode(', ', $acknowledged)
        ));

        self::notice(
            $result['blocking'] ? 'warning' : 'success',
            $result['blocking']
                ? __('Saved. There are still open deviations.', 'sonoquill')
                : __('Saved. The check is clean.', 'sonoquill')
        );

        self::back($id);
    }

    public static function handleApprove(): void
    {
        self::guard(self::ACTION_APPROVE);
        $id = self::episodeId();

        if (!\Sonoquill\Pipeline\Approval::approveText($id, wp_get_current_user()->display_name)) {
            self::notice('error', \Sonoquill\Pipeline\Approval::lastError());
            self::back($id);
        }

        if (\Sonoquill\Pipeline\Approval::lastNotice() !== '') {
            self::notice('warning', \Sonoquill\Pipeline\Approval::lastNotice());
            self::back($id);
        }

        self::notice('success', \Sonoquill\Pipeline\Approval::lastAutomatic() !== null
            ? __('The text is approved. Synthesis, montage and Auphonic now run on their own; when the Podlove draft is ready, an e-mail will be sent.', 'sonoquill')
            : __('The text is approved. Next up is the audio.', 'sonoquill'));
        self::back($id);
    }

    public static function handleAudio(): void
    {
        self::guard(self::ACTION_AUDIO);
        $id = self::episodeId();

        $episode = EpisodeRepository::find($id);
        if (($episode['status'] ?? '') !== EpisodeStatus::TEXT_APPROVED) {
            self::notice('error', __('The audio is only created after the text approval. That is the first of the two human gates.', 'sonoquill'));
            self::back($id);
        }

        try {
            $result = \Sonoquill\Segments\Segmenter::rebuild($id);
        } catch (\Throwable $e) {
            self::notice('error', $e->getMessage());
            self::back($id);
        }

        EpisodeRepository::log($id, 'segmentierung', sprintf(
            /* translators: 1: total number of segments, 2: number of new segments, 3: number of unchanged segments, 4: number of removed segments */
            __('%1$d segments: %2$d new, %3$d kept unchanged, %4$d removed.', 'sonoquill'),
            $result['gesamt'],
            $result['angelegt'],
            $result['erhalten'],
            $result['entfernt']
        ));

        PipelineScheduler::queueSynthesis($id);

        self::notice('success', sprintf(
            /* translators: 1: new segments, 2: kept segments */
            __('%1$d segments are being generated, %2$d stay unchanged. The synthesis runs in the background.', 'sonoquill'),
            $result['angelegt'],
            $result['erhalten']
        ));
        self::back($id);
    }

    public static function handleMontage(): void
    {
        self::guard(self::ACTION_MONTAGE);
        $id = self::episodeId();

        PipelineScheduler::queueMontage($id);
        self::notice('success', __('The montage is scheduled.', 'sonoquill'));
        self::back($id);
    }

    public static function handleFlag(): void
    {
        self::guard(self::ACTION_FLAG);
        $id = self::episodeId();

        $segmentId = isset($_POST['segment']) ? (int) $_POST['segment'] : 0;
        $segment = \Sonoquill\Segments\SegmentRepository::find($segmentId);

        if ($segment === null || (int) $segment['episode_id'] !== $id) {
            self::notice('error', __('This segment does not belong to this episode.', 'sonoquill'));
            self::back($id);
        }

        $note = isset($_POST['note']) ? sanitize_textarea_field(wp_unslash((string) $_POST['note'])) : '';
        $flagged = (string) $segment['status'] !== \Sonoquill\Segments\SegmentStatus::FLAGGED;

        \Sonoquill\Segments\SegmentRepository::update($segmentId, [
            'status' => $flagged
                ? \Sonoquill\Segments\SegmentStatus::FLAGGED
                : \Sonoquill\Segments\SegmentStatus::GENERATED,
            'note'   => $flagged ? $note : '',
        ]);

        EpisodeRepository::log($id, 'abhoeren', sprintf(
            'Segment %d %s%s',
            (int) $segment['idx'],
            $flagged ? __('flagged', 'sonoquill') : __('approved again', 'sonoquill'),
            $flagged && $note !== '' ? ': ' . $note : '.'
        ));

        self::notice('success', $flagged
            ? __('Segment flagged. Regenerate it or re-record it with your own voice.', 'sonoquill')
            : __('Flag withdrawn.', 'sonoquill'));
        self::back($id);
    }

    public static function handleRegenerate(): void
    {
        self::guard(self::ACTION_REGENERATE);
        $id = self::episodeId();

        $segmentId = isset($_POST['segment']) ? (int) $_POST['segment'] : 0;
        $segment = \Sonoquill\Segments\SegmentRepository::find($segmentId);

        if ($segment === null || (int) $segment['episode_id'] !== $id) {
            self::notice('error', __('This segment does not belong to this episode.', 'sonoquill'));
            self::back($id);
        }

        // New seed: the same segment again with the same seed would sound
        // identical, and that is exactly what you do not want when regenerating.
        \Sonoquill\Segments\SegmentRepository::update($segmentId, [
            'seed'        => random_int(0, 4294967295),
            'audio_path'  => '',
            'duration_ms' => null,
            'status'      => \Sonoquill\Segments\SegmentStatus::PENDING,
        ]);

        PipelineScheduler::queueSynthesis($id);

        /* translators: %d: index of the segment */
        EpisodeRepository::log($id, 'abhoeren', sprintf(__('Segment %d is being regenerated with a new seed.', 'sonoquill'), (int) $segment['idx']));
        self::notice('success', __('The segment is being regenerated with a new seed.', 'sonoquill'));
        self::back($id);
    }

    public static function handleProduce(): void
    {
        self::guard(self::ACTION_PRODUCE);
        $id = self::episodeId();

        $slug = isset($_POST['slug']) ? sanitize_text_field(wp_unslash((string) $_POST['slug'])) : '';
        $slug = preg_replace('/[^A-Za-z0-9]/', '', $slug) ?? '';

        if ($slug !== '') {
            EpisodeRepository::update($id, ['podlove_slug' => $slug]);
        }

        PipelineScheduler::queueProduction($id);
        EpisodeRepository::log($id, 'auphonic', __('Production scheduled.', 'sonoquill'));

        self::notice('success', __('The episode is going to Auphonic. The callback then creates the Podlove draft.', 'sonoquill'));
        self::back($id);
    }

    public static function handleCleanup(): void
    {
        self::guard(self::ACTION_CLEANUP);
        $id = self::episodeId();

        $freed = \Sonoquill\Storage\EpisodeStorage::sizeOfEpisode($id);
        \Sonoquill\Storage\EpisodeStorage::deleteSegments($id);

        foreach (\Sonoquill\Segments\SegmentRepository::forEpisode($id) as $segment) {
            \Sonoquill\Segments\SegmentRepository::update((int) $segment['id'], [
                'audio_path' => '',
                'status'     => \Sonoquill\Segments\SegmentStatus::PENDING,
            ]);
        }

        EpisodeRepository::log($id, 'aufraeumen', sprintf(
            /* translators: %s: formatted size of the freed storage */
            __('Segment audio files removed, %s freed. The finished episode and the transcript remain.', 'sonoquill'),
            size_format($freed)
        ));

        self::notice('success', sprintf(
            /* translators: %s: freed storage */
            __('Segment audio files removed, %s freed.', 'sonoquill'),
            size_format($freed)
        ));
        self::back($id);
    }

    public static function handleResume(): void
    {
        self::guard(self::ACTION_RESUME);
        $id = self::episodeId();

        $message = \Sonoquill\Pipeline\Recovery::resume($id);
        EpisodeRepository::log($id, 'wiederaufnahme', $message);

        self::notice('success', $message);
        self::back($id);
    }

    /**
     * The second human gate (audio approval).
     *
     * It publishes nothing. It records that the episode has been listened to and
     * found good — publishing happens afterwards by hand in Podlove.
     */
    public static function handleApproveAudio(): void
    {
        self::guard(self::ACTION_APPROVE_AUDIO);
        $id = self::episodeId();

        $episode = EpisodeRepository::find($id);
        $mix = (string) ($episode['mixed_audio_path'] ?? '');

        if ($mix === '' || !\Sonoquill\Storage\EpisodeStorage::exists($mix)) {
            self::notice('error', __('There is no assembled version to listen to yet.', 'sonoquill'));
            self::back($id);
        }

        // The audio approval comes after mastering by Auphonic. What gets approved
        // is the mastered version — signing off on the raw montage would mean
        // approving something other than what ends up in the feed.
        if ((string) ($episode['status'] ?? '') !== EpisodeStatus::AWAITING_AUDIO) {
            self::notice('error', __('The episode has not been to Auphonic yet. The mastered version is approved, not the raw montage.', 'sonoquill'));
            self::back($id);
        }

        EpisodeRepository::update($id, [
            'status'            => EpisodeStatus::DONE,
            'audio_approved_at' => current_time('mysql'),
        ]);

        EpisodeRepository::log($id, 'freigabe', sprintf(
            /* translators: %s: display name of the approving user */
            __('Audio approved by %s.', 'sonoquill'),
            wp_get_current_user()->display_name
        ));

        self::notice('success', __('Audio approved. Publishing is done by hand in Podlove.', 'sonoquill'));
        self::back($id);
    }

    public static function handleDelete(): void
    {
        self::guard(self::ACTION_DELETE);
        $id = self::episodeId();

        \Sonoquill\Storage\EpisodeStorage::deleteEpisode($id);
        EpisodeRepository::delete($id);
        self::notice('success', __('Episode and its audio files deleted.', 'sonoquill'));
        self::back();
    }


    /**
     * Accepts the opener and outro as MP3.
     */
    public static function handleMusicUpload(): void
    {
        self::guard(self::ACTION_MUSIC);

        $done = [];
        foreach (\Sonoquill\Audio\MusicBed::allSlots() as $slot) {
            if (!empty($_POST['entfernen_' . $slot])) {
                $file = \Sonoquill\Audio\MusicBed::file($slot);
                if (is_file($file) && @unlink($file)) {
                    /* translators: %s: name of the music slot (e.g. opener, outro) */
                    $done[] = sprintf(__('%s removed', 'sonoquill'), $slot);
                }
                continue;
            }

            $tmp = isset($_FILES[$slot]['tmp_name']) && is_string($_FILES[$slot]['tmp_name']) ? $_FILES[$slot]['tmp_name'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- temporary upload path, checked with is_uploaded_file()
            if ($tmp === '' || absint($_FILES[$slot]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
                continue;
            }

            // Readable audio? Without ffmpeg only MP3 can be checked (and used).
            $readable = \Sonoquill\Audio\AudioEngine::mode() === \Sonoquill\Audio\AudioEngine::MODE_FFMPEG
                ? \Sonoquill\Audio\Ffmpeg::probe($tmp) !== null
                : \Sonoquill\Audio\Mp3::durationMs($tmp) !== null;
            if (!$readable) {
                /* translators: %s: name of the music slot (e.g. opener, outro) */
                self::notice('error', sprintf(__('The file for “%s” is not readable audio.', 'sonoquill'), $slot));
                wp_safe_redirect(Urls::settings());
                exit;
            }

            // Through WordPress's upload handling (type check, permissions),
            // then to its fixed place — the montage looks for opener.mp3 etc.
            require_once ABSPATH . 'wp-admin/includes/file.php';
            $handled = wp_handle_upload($_FILES[$slot], ['test_form' => false, 'mimes' => ['mp3' => 'audio/mpeg']]); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the upload array is validated by wp_handle_upload()
            wp_mkdir_p(\Sonoquill\Audio\MusicBed::dir());
            // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- move within the uploads directory
            if (!empty($handled['error']) || empty($handled['file']) || !@rename($handled['file'], \Sonoquill\Audio\MusicBed::file($slot))) {
                self::notice('error', __('The file could not be stored.', 'sonoquill') . (!empty($handled['error']) ? ' ' . (string) $handled['error'] : ''));
                wp_safe_redirect(Urls::settings());
                exit;
            }
            $done[] = $slot;
        }

        self::notice($done === [] ? 'warning' : 'success', $done === []
            ? __('No file arrived.', 'sonoquill')
            /* translators: %s: comma-separated list of applied music changes */
            : sprintf(__('Applied: %s. Takes effect from the next montage.', 'sonoquill'), implode(', ', $done)));
        wp_safe_redirect(Urls::settings());
        exit;
    }

    /**
     * Hides an episode in the overview or shows it again.
     */
    public static function handleHide(): void
    {
        self::guard(self::ACTION_HIDE);
        $id = self::episodeId();

        $hidden = Overview::toggleHidden($id);
        self::notice('success', $hidden
            /* translators: %d: episode ID */
            ? sprintf(__('Episode %d is hidden.', 'sonoquill'), $id)
            /* translators: %d: episode ID */
            : sprintf(__('Episode %d is visible again.', 'sonoquill'), $id));

        wp_safe_redirect(Urls::episodes($hidden ? [] : ['alle' => 1]));
        exit;
    }


    /**
     * @throws \RuntimeException
     */
    private static function readSubmittedDocument(): SourceDocument
    {
        $hasUpload = isset($_FILES['docx']['tmp_name'])
            && is_string($_FILES['docx']['tmp_name'])
            && $_FILES['docx']['tmp_name'] !== ''
            && absint($_FILES['docx']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;

        if ($hasUpload) {
            $tmp = (string) $_FILES['docx']['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- temporary upload path, checked below

            if (!is_uploaded_file($tmp)) {
                throw new \RuntimeException(__('The uploaded file is not valid.', 'sonoquill'));
            }

            $name = sanitize_file_name(wp_unslash((string) ($_FILES['docx']['name'] ?? '')));
            if (strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) !== 'docx') {
                throw new \RuntimeException(__('Only DOCX files are read.', 'sonoquill'));
            }

            // The file is only read and never stored anywhere.
            return DocxParser::parse($tmp);
        }

        // Plain text that is parsed, never output unescaped.
        $pasted = isset($_POST['source_text']) ? Sanitizer::plain((string) wp_unslash($_POST['source_text'])) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by Sanitizer::plain()
        if (trim($pasted) === '') {
            throw new \RuntimeException(__('Neither a file nor pasted text.', 'sonoquill'));
        }

        return PlainTextParser::parse($pasted);
    }

    /**
     * Check capabilities and nonce. Public so that add-ons can secure their forms the same way.
     */
    public static function guard(string $action): void
    {
        if (!current_user_can(SettingsPage::CAPABILITY)) {
            wp_die(esc_html__('You do not have the permissions for this.', 'sonoquill'), '', ['response' => 403]);
        }

        check_admin_referer($action);
    }

    private static function episodeId(): int
    {
        $id = isset($_POST['episode']) ? (int) $_POST['episode'] : 0;

        if ($id <= 0 || EpisodeRepository::find($id) === null) {
            wp_die(esc_html__('This episode does not exist.', 'sonoquill'), '', ['response' => 404]);
        }

        return $id;
    }

    public static function notice(string $type, string $message): void
    {
        set_transient(self::NOTICE_TRANSIENT . get_current_user_id(), [
            'type'    => $type,
            'message' => $message,
        ], 60);
    }

    public static function renderNotice(): void
    {
        $key = self::NOTICE_TRANSIENT . get_current_user_id();
        $notice = get_transient($key);

        if (!is_array($notice) || !isset($notice['type'], $notice['message'])) {
            return;
        }

        delete_transient($key);

        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            esc_attr((string) $notice['type']),
            esc_html((string) $notice['message'])
        );
    }


    private static function back(int $episodeId = 0): void
    {
        wp_safe_redirect($episodeId > 0 ? Urls::episode($episodeId) : Urls::episodes());
        exit;
    }

    private static function backToNew(): void
    {
        wp_safe_redirect(Urls::episodes(['neu' => 1]));
        exit;
    }
}
