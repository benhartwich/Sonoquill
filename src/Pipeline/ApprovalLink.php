<?php
declare(strict_types=1);

namespace Sonoquill\Pipeline;

use Sonoquill\Support\KeyStore;

/**
 * Signed one-click links for the text approval in the notification mail.
 *
 * The link carries the episode, a hash of the spoken script and an expiry
 * time, signed with an HMAC. Editing the script changes the hash, so a link
 * from an older mail stops working; after 48 hours every link expires.
 * The key comes from AASPF_ENCRYPTION_KEY if it is set, otherwise from the
 * WordPress auth salt — neither is in the database.
 *
 * Opening the link only shows a confirmation page; approving needs a
 * POST from that page. Mail scanners that follow links therefore cannot
 * approve anything.
 */
final class ApprovalLink
{
    public const ACTION = 'sonoquill_mail_approve';

    public const TTL = 48 * 3600;

    /**
     * Query parameters of a link for the current script.
     *
     * @return array{action:string,e:int,h:string,x:int,s:string}
     */
    public static function params(int $episodeId, string $script, ?int $now = null): array
    {
        $hash = self::scriptHash($script);
        $expires = ($now ?? time()) + self::TTL;

        return [
            'action' => self::ACTION,
            'e'      => $episodeId,
            'h'      => $hash,
            'x'      => $expires,
            's'      => self::sign($episodeId, $hash, $expires),
        ];
    }

    public static function url(int $episodeId, string $script): string
    {
        return add_query_arg(self::params($episodeId, $script), admin_url('admin-post.php'));
    }

    /**
     * Checks a link against the current script. Returns '' if it is valid,
     * otherwise the reason.
     */
    public static function verify(int $episodeId, string $hash, int $expires, string $signature, string $currentScript, ?int $now = null): string
    {
        if ($episodeId <= 0 || $signature === '' || !hash_equals(self::sign($episodeId, $hash, $expires), $signature)) {
            return __('This approval link is invalid.', 'sonoquill');
        }

        if ($expires < ($now ?? time())) {
            return __('This approval link has expired. Please approve in the backend.', 'sonoquill');
        }

        if (!hash_equals(self::scriptHash($currentScript), $hash)) {
            return __('The spoken script has changed since this mail was sent. Please review it again in the backend.', 'sonoquill');
        }

        return '';
    }

    public static function scriptHash(string $script): string
    {
        return substr(hash('sha256', $script), 0, 20);
    }

    private static function sign(int $episodeId, string $hash, int $expires): string
    {
        return hash_hmac('sha256', 'approve|' . $episodeId . '|' . $hash . '|' . $expires, self::key());
    }

    private static function key(): string
    {
        if (KeyStore::isConfigured()) {
            return hash('sha256', 'sonoquill-mail-approval|' . (string) constant(KeyStore::CONSTANT));
        }

        return hash('sha256', 'sonoquill-mail-approval|' . wp_salt('auth'));
    }
}
