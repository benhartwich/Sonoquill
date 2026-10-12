<?php
declare(strict_types=1);

namespace Sonoquill\Health\Checks;

use Sonoquill\Health\CheckInterface;
use Sonoquill\Health\Result;
use Sonoquill\Storage\EpisodeStorage;

/**
 * Checks the storage location for the segment audio files.
 *
 * Two questions: Is it writable, and can the raw recordings be fetched from
 * the web? The second is answered, not assumed: a probe file is written into
 * the storage folder and requested over HTTP (HEAD). Apache honours the
 * .htaccess in the folder, nginx does not — the warning carries the nginx
 * and Apache configuration to block it. The music folder is checked too.
 */
final class StorageCheck implements CheckInterface
{
    public function id(): string
    {
        return 'ablage';
    }

    public function label(): string
    {
        return __('Audio storage', 'sonoquill');
    }

    public function run(): Result
    {
        $status = EpisodeStorage::status();
        /* translators: %s: filesystem path of the audio storage directory */
        $detail = sprintf(__('Path: %s', 'sonoquill'), $status['path']);

        if (!$status['writable']) {
            return Result::fail($status['message'], $detail);
        }

        $music = self::musicNote();

        $url = EpisodeStorage::url();
        if ($url === null) {
            return EpisodeStorage::isInsideDocroot()
                ? Result::warn(__('Writable, but located inside the WordPress directory.', 'sonoquill'), $detail . __(' Make sure the web server does not serve this folder.', 'sonoquill') . $music)
                : Result::ok(__('Writable and outside the web directory.', 'sonoquill'), $detail . $music);
        }

        try {
            EpisodeStorage::ensureBaseDir();
        } catch (\RuntimeException $e) {
            return Result::warn(__('Writable; whether it is reachable from the web could not be tested.', 'sonoquill'), $detail . $music);
        }

        $reachable = self::probe(EpisodeStorage::baseDir(), $url);
        if ($reachable === null) {
            return Result::warn(__('Writable; whether it is reachable from the web could not be tested.', 'sonoquill'), $detail . $music);
        }

        if ($reachable) {
            $path = (string) wp_parse_url($url, PHP_URL_PATH);

            return Result::warn(
                __('Writable, but the files can be downloaded from the web.', 'sonoquill'),
                $detail . ' ' . sprintf(
                    /* translators: 1: URL path of the storage folder, 2: name of the filter */
                    __('The web server ignores the .htaccess in this folder (nginx does). Block the path %1$s in the server configuration, or move the storage outside the web directory with the filter %2$s.', 'sonoquill'),
                    $path,
                    'sonoquill_storage_dir'
                ) . ' ' . EpisodeStorage::blockSnippets($path, EpisodeStorage::baseDir()) . $music
            );
        }

        return Result::ok(__('Writable and protected from direct access.', 'sonoquill'), $detail . $music);
    }

    /**
     * The music folder is public by design (the settings page plays the
     * files). Said anyway, with the configuration to close it, for anyone
     * whose opener is not meant to be heard before the first episode.
     */
    private static function musicNote(): string
    {
        $dir = \Sonoquill\Audio\MusicBed::dir();
        if (!is_dir($dir)) {
            return '';
        }

        \Sonoquill\Storage\EpisodeStorage::protectDirectory($dir, false);
        $url = EpisodeStorage::uploads()['url'] . '/music';
        if (self::probe($dir, $url) !== true) {
            return '';
        }

        $path = (string) wp_parse_url($url, PHP_URL_PATH);

        return ' ' . __('The music files (opener, outro, separators) can be downloaded from the web — the settings page plays them from there. To close the folder anyway (the players in the settings then stay silent): ', 'sonoquill')
            . EpisodeStorage::blockSnippets($path, $dir);
    }

    /**
     * Writes a probe file into $dir and sends a HEAD request for it.
     * True: served, false: blocked, null: could not be tested — also when
     * the server answers 200 for a file that does not exist (catch-all).
     */
    public static function probe(string $dir, string $url): ?bool
    {
        $name = 'probe-' . strtolower(wp_generate_password(12, false)) . '.txt';
        $path = $dir . '/' . $name;

        if (@file_put_contents($path, wp_generate_password(24, false)) === false) {
            return null;
        }

        $args = ['timeout' => 10, 'redirection' => 0];
        $response = wp_remote_head(untrailingslashit($url) . '/' . $name, $args);
        wp_delete_file($path);

        if (is_wp_error($response)) {
            return null;
        }

        if ((int) wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }

        // A server that answers 200 for everything says nothing about the folder.
        $missing = wp_remote_head(untrailingslashit($url) . '/missing-' . strtolower(wp_generate_password(12, false)) . '.txt', $args);
        if (is_wp_error($missing) || (int) wp_remote_retrieve_response_code($missing) === 200) {
            return null;
        }

        return true;
    }
}
