<?php
declare(strict_types=1);

namespace Sonoquill\Health\Checks;

use Sonoquill\Health\CheckInterface;
use Sonoquill\Health\Result;

/**
 * Checks whether Podlove is active and reachable.
 *
 * This checks not only the activation state but the PHP class itself —
 * because that is exactly what the publishing step uses to create the draft.
 * Direct writes to the Podlove tables are ruled out,
 * which is why the class, not the table, is the criterion.
 */
final class PodloveCheck implements CheckInterface
{
    private const PLUGIN_FILE = 'podlove-podcasting-plugin-for-wordpress/podlove.php';
    private const EPISODE_CLASS = '\\Podlove\\Model\\Episode';
    private const PODCAST_CLASS = '\\Podlove\\Model\\Podcast';

    public function id(): string
    {
        return 'podlove';
    }

    public function label(): string
    {
        return 'Podlove';
    }

    public function run(): Result
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (!is_plugin_active(self::PLUGIN_FILE)) {
            return Result::fail(__('Podlove Podcast Publisher is not active.', 'sonoquill'));
        }

        if (!class_exists(self::EPISODE_CLASS) || !class_exists(self::PODCAST_CLASS)) {
            return Result::fail(
                __('Podlove is active, but the PHP API is missing.', 'sonoquill'),
                /* translators: 1: Podlove episode class name, 2: Podlove podcast class name */
                sprintf(__('Expected the classes %1$s and %2$s.', 'sonoquill'), self::EPISODE_CLASS, self::PODCAST_CLASS)
            );
        }

        $version = '';
        $file = WP_PLUGIN_DIR . '/' . self::PLUGIN_FILE;
        if (is_readable($file)) {
            $data = get_file_data($file, ['Version' => 'Version']);
            $version = (string) ($data['Version'] ?? '');
        }

        // Can Podlove find the files the plugin writes?
        $location = \Sonoquill\Podlove\MediaStore::location();
        if (!$location['matches_podlove']) {
            return Result::warn(
                __('Podlove\'s media file base URL does not point to this site\'s uploads.', 'sonoquill'),
                /* translators: %s: URL to enter in Podlove */
                sprintf(__('Finished episodes are stored in uploads/podcasts. Set the media file base URL in Podlove to %s.', 'sonoquill'), trailingslashit($location['url']))
            );
        }
        if (\Sonoquill\Podlove\MediaStore::asset('mp3') === null) {
            return Result::warn(__('Podlove has no episode asset for MP3.', 'sonoquill'), __('Create an asset with the file type MP3 in Podlove, otherwise the audio file cannot be linked.', 'sonoquill'));
        }

        $details = $this->episodeSummary();
        if (\Sonoquill\Podlove\MediaStore::asset('vtt') === null) {
            $details .= ' ' . __('No asset for WebVTT transcripts — transcripts are not linked.', 'sonoquill');
        }

        $protection = self::feedProtectionWarning(self::protectedFeeds(), has_filter('podlove_pre_media_file_download') !== false);
        if ($protection !== null) {
            return $protection;
        }

        return Result::ok(
            /* translators: %s: Podlove plugin version number */
            $version !== '' ? sprintf(__('Active (version %s).', 'sonoquill'), $version) : __('Active.', 'sonoquill'),
            trim($details)
        );
    }

    /**
     * Names of the Podlove feeds that are password-protected.
     *
     * @return list<string>
     */
    public static function protectedFeeds(): array
    {
        if (!class_exists('\\Podlove\\Model\\Feed') || !method_exists('\\Podlove\\Model\\Feed', 'all')) {
            return [];
        }

        $names = [];
        foreach ((array) \Podlove\Model\Feed::all() as $feed) {
            if (is_object($feed) && !empty($feed->protected)) {
                $names[] = (string) ($feed->name ?? $feed->slug ?? $feed->id ?? '?');
            }
        }

        return $names;
    }

    /**
     * A protected feed only protects the feed: the media files stay
     * reachable through Podlove's download redirects. Unless something hooks
     * into `podlove_pre_media_file_download`, that is worth a warning.
     *
     * @param list<string> $protectedFeeds
     */
    public static function feedProtectionWarning(array $protectedFeeds, bool $hasDownloadFilter): ?Result
    {
        if ($protectedFeeds === [] || $hasDownloadFilter) {
            return null;
        }

        return Result::warn(
            /* translators: %s: comma-separated feed names */
            sprintf(__('Protected feed (%s): the audio files are still publicly downloadable.', 'sonoquill'), implode(', ', $protectedFeeds)),
            __('Feed protection only covers the feed. Podlove\'s download redirects /podlove/file/<id>/… and ?download_media_file=<id> hand out every media file without a password. To close them, check access in the filter podlove_pre_media_file_download (or block the URLs in the server configuration); also keep the media directory from being listed.', 'sonoquill')
        );
    }

    /**
     * Counts via Podlove's own API, not via the table.
     *
     * The `podlove_episode` table contains rows for many post types —
     * more than a thousand on this installation, including astrophotos,
     * events and drafts. Only a fraction of them are published episodes.
     * `count_published()` correctly filters on post_type "podcast" and
     * post_status "publish".
     */
    private function episodeSummary(): string
    {
        if (!method_exists(self::EPISODE_CLASS, 'count_published')) {
            return '';
        }

        $published = (int) \Podlove\Model\Episode::count_published();
        /* translators: %s: formatted number of published episodes */
        $summary = sprintf(__('%s published episodes.', 'sonoquill'), number_format_i18n($published));

        if (method_exists(self::EPISODE_CLASS, 'get_next_episode_number')) {
            $next = \Podlove\Model\Episode::get_next_episode_number();
            if (is_numeric($next)) {
                /* translators: %d: next episode number */
                $summary .= sprintf(__(' Next episode number: %d.', 'sonoquill'), (int) $next);
            }
        }

        return $summary;
    }
}
