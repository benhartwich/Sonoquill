<?php
declare(strict_types=1);

namespace Sonoquill\Storage;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for the run log and e-mails; they are escaped where they are displayed.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Checking the plugin's own storage directory, also from background jobs.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removing the plugin's own episode directory.
// phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing files in the plugin's own storage directory.

/**
 * Storage for the segment audio files, transcripts and the local
 * pronunciation dictionary.
 *
 * By default a folder in uploads, `uploads/sonoquill/data-<random>`,
 * protected by an .htaccess and an index.php. The random part is generated
 * once, so nobody can guess the folder. Under Apache the .htaccess blocks
 * direct access; nginx ignores it — the storage health check tests this with
 * a probe file and says what to do.
 *
 * A site that keeps private files elsewhere (for example above the web
 * root) can move the storage with the filter `sonoquill_storage_dir`.
 *
 * The database stores relative paths. Moving the root directory therefore
 * does not invalidate any row.
 */
final class EpisodeStorage
{
    /** Name of the plugin's folder in uploads. */
    public const UPLOADS_FOLDER = 'sonoquill';

    /**
     * The plugin's folder in uploads, as path and URL. Public files (music)
     * live here; the protected data folder is inside it.
     *
     * @return array{dir:string,url:string}
     */
    public static function uploads(): array
    {
        $uploads = wp_upload_dir(null, false);

        return [
            'dir' => untrailingslashit((string) $uploads['basedir']) . '/' . self::UPLOADS_FOLDER,
            'url' => untrailingslashit((string) $uploads['baseurl']) . '/' . self::UPLOADS_FOLDER,
        ];
    }

    public static function defaultDir(): string
    {
        $suffix = (string) get_option('aaspf_storage_suffix', '');
        if ($suffix === '') {
            $suffix = strtolower(wp_generate_password(16, false));
            add_option('aaspf_storage_suffix', $suffix, '', false);
        }

        return self::uploads()['dir'] . '/data-' . $suffix;
    }

    public static function baseDir(): string
    {
        $default = self::defaultDir();

        /**
         * Filters the directory for episode audio, transcripts and the local
         * pronunciation dictionary.
         *
         * @param string $dir Absolute path without trailing slash.
         */
        $dir = apply_filters('sonoquill_storage_dir', $default);

        return untrailingslashit(is_string($dir) && trim($dir) !== '' ? trim($dir) : $default);
    }

    /**
     * URL of the storage folder, if it lies in uploads — for the health check.
     */
    public static function url(): ?string
    {
        $uploads = wp_upload_dir(null, false);
        $basedir = untrailingslashit((string) $uploads['basedir']);
        $base = self::baseDir();

        if (!str_starts_with($base . '/', $basedir . '/')) {
            return null;
        }

        return untrailingslashit((string) $uploads['baseurl']) . substr($base, strlen($basedir));
    }

    public static function episodeDir(int $episodeId): string
    {
        return self::baseDir() . '/' . $episodeId;
    }

    /** Blocks direct access under Apache 2.2 and 2.4 alike. */
    private const HTACCESS = "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n";

    /**
     * Creates the storage directory with its protection files.
     *
     * The protection files block direct access under Apache; nginx ignores
     * them (see the storage health check).
     *
     * @throws \RuntimeException
     */
    public static function ensureBaseDir(): string
    {
        $base = self::baseDir();

        if (!is_dir($base) && !wp_mkdir_p($base)) {
            /* translators: %s: path of the storage directory */
            throw new \RuntimeException(sprintf(__('Directory could not be created: %s', 'sonoquill'), $base));
        }

        self::protectDirectory($base, true);

        return $base;
    }

    /**
     * Writes the protection files into a directory, if they are missing:
     * index.php and index.html against directory listings, and an
     * .htaccess — denying all access, or only the listing ($denyAll false,
     * for files that must stay playable in the browser).
     */
    public static function protectDirectory(string $dir, bool $denyAll): void
    {
        $files = [
            $dir . '/index.php'  => "<?php\n// Silence is golden.\n",
            $dir . '/index.html' => "<!DOCTYPE html><title></title>\n",
            $dir . '/.htaccess'  => $denyAll ? self::HTACCESS : "Options -Indexes\n",
        ];

        foreach ($files as $file => $content) {
            if (!file_exists($file)) {
                @file_put_contents($file, $content);
            }
        }
    }

    /**
     * Server configuration that blocks a directory, for the health check:
     * nginx ignores .htaccess files.
     */
    public static function blockSnippets(string $urlPath, string $dir): string
    {
        $urlPath = '/' . trim($urlPath, '/') . '/';

        return sprintf(
            /* translators: 1: nginx configuration, 2: Apache configuration */
            __('nginx: %1$s — Apache (if .htaccess files are not allowed): %2$s', 'sonoquill'),
            'location ^~ ' . $urlPath . ' { deny all; }',
            '<Directory "' . $dir . '"> Require all denied </Directory>'
        );
    }

    /**
     * @throws \RuntimeException
     */
    public static function ensureEpisodeDir(int $episodeId): string
    {
        self::ensureBaseDir();
        $dir = self::episodeDir($episodeId);

        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            /* translators: %s: path of the episode directory */
            throw new \RuntimeException(sprintf(__('Directory could not be created: %s', 'sonoquill'), $dir));
        }

        return $dir;
    }

    public static function segmentRelativePath(int $episodeId, int $index, string $extension = 'mp3'): string
    {
        return sprintf('%d/segment-%03d.%s', $episodeId, $index, $extension);
    }

    public static function mixRelativePath(int $episodeId, string $extension = 'mp3'): string
    {
        return sprintf('%d/folge.%s', $episodeId, $extension);
    }

    public static function absolutePath(string $relative): string
    {
        return self::baseDir() . '/' . ltrim($relative, '/');
    }

    public static function exists(string $relative): bool
    {
        return $relative !== '' && is_readable(self::absolutePath($relative));
    }

    public static function size(string $relative): int
    {
        $path = self::absolutePath($relative);

        return is_readable($path) ? (int) filesize($path) : 0;
    }

    /**
     * @throws \RuntimeException
     */
    public static function write(string $relative, string $bytes): void
    {
        self::ensureBaseDir();
        $path = self::absolutePath($relative);
        $dir = dirname($path);

        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            /* translators: %s: path of the directory */
            throw new \RuntimeException(sprintf(__('Directory could not be created: %s', 'sonoquill'), $dir));
        }

        if (file_put_contents($path, $bytes) === false) {
            /* translators: %s: absolute path of the file */
            throw new \RuntimeException(sprintf(__('File could not be written: %s', 'sonoquill'), $path));
        }
    }

    /**
     * Size of all files of an episode.
     */
    public static function sizeOfEpisode(int $episodeId): int
    {
        $total = 0;
        foreach ((array) glob(self::episodeDir($episodeId) . '/*') as $file) {
            if (is_string($file) && is_file($file)) {
                $total += (int) filesize($file);
            }
        }

        return $total;
    }

    /**
     * Removes only the raw segment recordings.
     *
     * They can be cleaned up after successful publication, but not
     * automatically — you want to still have them when the next error occurs.
     * The finished episode and the transcript are therefore kept.
     */
    public static function deleteSegments(int $episodeId): int
    {
        $removed = 0;
        foreach ((array) glob(self::episodeDir($episodeId) . '/segment-*') as $file) {
            if (is_string($file) && is_file($file) && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    public static function deleteEpisode(int $episodeId): void
    {
        $dir = self::episodeDir($episodeId);
        if (!is_dir($dir)) {
            return;
        }

        foreach ((array) glob($dir . '/*') as $file) {
            if (is_string($file) && is_file($file)) {
                @unlink($file);
            }
        }

        @rmdir($dir);
    }

    /**
     * @return array{writable:bool,path:string,message:string}
     */
    public static function status(): array
    {
        $base = self::baseDir();

        if (!is_dir($base)) {
            // The nearest folder that exists decides whether it can be created.
            $parent = dirname($base);
            while (!is_dir($parent) && dirname($parent) !== $parent) {
                $parent = dirname($parent);
            }

            return [
                'writable' => is_writable($parent),
                'path'     => $base,
                'message'  => is_writable($parent)
                    ? __('Will be created on the first run.', 'sonoquill')
                    /* translators: %s: path of the parent directory of the storage */
                    : sprintf(__('The parent directory %s is not writable.', 'sonoquill'), $parent),
            ];
        }

        return [
            'writable' => is_writable($base),
            'path'     => $base,
            'message'  => is_writable($base) ? __('Present and writable.', 'sonoquill') : __('Present, but not writable.', 'sonoquill'),
        ];
    }

    /**
     * Is the storage located inside what the web server serves?
     *
     * This question is the whole reason for this class, so it is answered
     * rather than assumed.
     */
    public static function isInsideDocroot(): bool
    {
        $base = realpath(self::baseDir()) ?: self::baseDir();
        $root = realpath(untrailingslashit(ABSPATH)) ?: untrailingslashit(ABSPATH);

        return str_starts_with($base . '/', $root . '/');
    }
}
