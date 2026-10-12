<?php
declare(strict_types=1);

namespace Sonoquill\Source;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for the run log and e-mails; they are escaped where they are displayed.

/**
 * The registered sources.
 *
 * Add-ons register their source via the `sonoquill_register_sources` hook:
 *
 *     add_action('sonoquill_register_sources', static function (): void {
 *         \Sonoquill\Source\Sources::register(new MySource());
 *     });
 *
 * The hook runs on first access, i.e. not before `init`.
 */
final class Sources
{
    /** Identifier for episodes without a known source, e.g. when an add-on has been deactivated. */
    public const FALLBACK = UploadSource::ID;

    /** @var array<string,Source> */
    private static array $sources = [];

    private static bool $loaded = false;

    public static function register(Source $source): void
    {
        $id = $source->id();
        if (preg_match('/^[a-z0-9-]{1,32}$/', $id) !== 1) {
            /* translators: %s: the rejected source identifier */
            throw new \InvalidArgumentException(sprintf(__('Invalid source identifier "%s".', 'sonoquill'), $id));
        }

        self::$sources[$id] = $source;
    }

    /**
     * @return array<string,Source>
     */
    public static function all(): array
    {
        self::load();

        return self::$sources;
    }

    public static function get(string $id): ?Source
    {
        self::load();

        return self::$sources[$id] ?? null;
    }

    /**
     * The source of an episode. If it is not (or no longer) registered, the default applies —
     * the episode remains usable, just without the extras of its source.
     *
     * @param array<string,mixed> $episode
     */
    public static function forEpisode(array $episode): Source
    {
        self::load();

        $id = (string) ($episode['source_type'] ?? '');

        return self::$sources[$id] ?? self::$sources[self::FALLBACK];
    }

    /**
     * Does the source of this episode want the number gate? Sources that do
     * not implement SourcePreferences: yes.
     *
     * @param array<string,mixed> $episode
     */
    public static function usesNumberGate(array $episode): bool
    {
        $source = self::forEpisode($episode);

        return !$source instanceof SourcePreferences || $source->usesNumberGate($episode);
    }

    /**
     * Batch preference of the source of this episode, null for "as configured".
     *
     * @param array<string,mixed> $episode
     */
    public static function prefersBatch(array $episode): ?bool
    {
        $source = self::forEpisode($episode);

        return $source instanceof SourcePreferences ? $source->prefersBatch($episode) : null;
    }

    /** For tests only: reset the registration. */
    public static function reset(): void
    {
        self::$sources = [];
        self::$loaded = false;
    }

    private static function load(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        self::register(new UploadSource());
        self::register(new PostSource());

        if (function_exists('do_action')) {
            do_action('sonoquill_register_sources');
        }
    }
}
