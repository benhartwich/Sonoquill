<?php
declare(strict_types=1);

namespace Sonoquill\Admin;

// phpcs:disable WordPress.Security.NonceVerification -- The HMAC signature of the link is the credential; the recipient may not be logged in.

use Sonoquill\Db\EpisodeRepository;
use Sonoquill\Pipeline\Approval;
use Sonoquill\Pipeline\ApprovalLink;
use Sonoquill\Settings\Options;

/**
 * The page behind the one-click link in the "text awaiting approval" mail.
 *
 * GET shows what is about to be approved and a button; only the POST from
 * that button approves. Opt-in via the setting `mail_approval_links`.
 */
final class MailApproval
{
    public static function register(): void
    {
        add_action('admin_post_nopriv_' . ApprovalLink::ACTION, [self::class, 'handle']);
        add_action('admin_post_' . ApprovalLink::ACTION, [self::class, 'handle']);
    }

    public static function enabled(): bool
    {
        return Options::flag('mail_approval_links');
    }

    public static function handle(): void
    {
        $title = __('Text approval', 'sonoquill');

        if (!self::enabled()) {
            wp_die(esc_html__('Approval by e-mail link is switched off.', 'sonoquill'), esc_html($title), ['response' => 403]);
        }

        $post = isset($_SERVER['REQUEST_METHOD']) && strtoupper(sanitize_text_field(wp_unslash((string) $_SERVER['REQUEST_METHOD']))) === 'POST';
        $source = $post ? $_POST : $_GET;

        $id = isset($source['e']) ? absint(wp_unslash($source['e'])) : 0;
        $hash = isset($source['h']) ? sanitize_key(wp_unslash((string) $source['h'])) : '';
        $expires = isset($source['x']) ? absint(wp_unslash($source['x'])) : 0;
        $signature = isset($source['s']) ? sanitize_key(wp_unslash((string) $source['s'])) : '';

        $episode = $id > 0 ? EpisodeRepository::find($id) : null;
        $error = $episode === null
            ? __('This approval link is invalid.', 'sonoquill')
            : ApprovalLink::verify($id, $hash, $expires, $signature, (string) ($episode['script_text'] ?? ''));

        if ($error !== '') {
            wp_die(esc_html($error), esc_html($title), ['response' => 403]);
        }

        if (!$post) {
            wp_die(self::confirmationPage($episode, ['e' => $id, 'h' => $hash, 'x' => $expires, 's' => $signature]), esc_html($title), ['response' => 200]);
        }

        if (!Approval::approveText($id, __('e-mail link', 'sonoquill'))) {
            wp_die(esc_html(Approval::lastError()), esc_html($title), ['response' => 409]);
        }

        wp_die(
            '<p>' . esc_html__('The text is approved. Everything else runs on its own; the audio approval comes as a separate mail.', 'sonoquill') . '</p>'
            . (Approval::lastNotice() !== '' ? '<p>' . esc_html(Approval::lastNotice()) . '</p>' : ''),
            esc_html($title),
            ['response' => 200]
        );
    }

    /**
     * @param array<string,mixed>      $episode
     * @param array<string,int|string> $params
     */
    public static function confirmationPage(array $episode, array $params): string
    {
        $html = '<h1>' . esc_html((string) ($episode['episode_title'] ?? '')) . '</h1>';
        $html .= '<p>' . esc_html(sprintf(
            /* translators: %s: number of characters */
            __('Spoken script with %s characters. Approving starts the audio production.', 'sonoquill'),
            number_format_i18n(mb_strlen((string) ($episode['script_text'] ?? '')))
        )) . '</p>';
        $html .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        $html .= '<input type="hidden" name="action" value="' . esc_attr(ApprovalLink::ACTION) . '">';
        foreach ($params as $name => $value) {
            $html .= '<input type="hidden" name="' . esc_attr((string) $name) . '" value="' . esc_attr((string) $value) . '">';
        }
        $html .= '<p><button type="submit" class="button button-primary">' . esc_html__('Approve text', 'sonoquill') . '</button></p>';
        $html .= '</form>';
        $html .= '<p><a href="' . esc_url(Urls::episode((int) $episode['id'], 'skript')) . '">' . esc_html__('Read the script in the backend first', 'sonoquill') . '</a></p>';

        return $html;
    }
}
