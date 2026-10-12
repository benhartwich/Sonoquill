<?php
declare(strict_types=1);

namespace Sonoquill\Ai;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for the run log and e-mails; they are escaped where they are displayed.

use Sonoquill\Settings\Options;
use Sonoquill\Text\Encoding;

/**
 * The core prompts, per language.
 *
 * Which version applies, in this order:
 *
 * 1. the one edited in the backend (option `aaspf_prompts`, per language),
 * 2. a file `<language>/<name>.md` in the configured prompt directory,
 * 3. a file `<name>.md` directly in the configured prompt directory — for
 *    anyone who keeps their prompts under version control alongside the
 *    website,
 * 4. the bundled file `prompts/<language>/<name>.md`,
 * 5. the bundled English file `prompts/en/<name>.md`.
 *
 * The podcast language can be any language code ("uk", "fr", "pt-br").
 * Bundled prompts exist only for German and English; for every other
 * language the English prompt applies until a version for that language is
 * saved in the backend or placed in the prompt directory.
 *
 * The placeholders {podcast}, {host}, {editor} and {sign_off} are filled in
 * on retrieval (Options::fill), in each of the three versions.
 */
final class Prompts
{
    public const OPTION = 'aaspf_prompts';

    /** Name => short description for the backend. */
    public const NAMES = ['script', 'metadata', 'factcheck', 'dictionary'];

    /** Languages with bundled prompts. Others fall back to English. */
    public const LANGUAGES = ['de', 'en'];

    /**
     * The languages offered in the prompt editor: the bundled ones plus the
     * podcast language.
     *
     * @return list<string>
     */
    public static function editableLanguages(): array
    {
        return array_values(array_unique(array_merge(self::LANGUAGES, [Options::language()])));
    }

    /**
     * The prompt as it is sent to the model.
     *
     * @throws AnthropicException if no version exists.
     */
    public static function get(string $name): string
    {
        $text = trim(self::raw($name)['text']);
        if ($text === '') {
            /* translators: %s: name of the prompt */
            throw new AnthropicException(sprintf(__('The prompt "%s" is missing or empty.', 'sonoquill'), $name));
        }

        return Options::fill($text);
    }

    /**
     * The applicable version without filled-in placeholders, along with its source.
     *
     * @return array{text:string,source:string,path:string}
     */
    public static function raw(string $name, ?string $language = null): array
    {
        self::assertName($name);
        $language = $language ?? Options::language();

        $override = self::override($name, $language);
        if ($override !== '') {
            return ['text' => $override, 'source' => 'override', 'path' => ''];
        }

        $custom = trim(Options::get('prompt_dir'));
        if ($custom !== '') {
            foreach (self::directoryCandidates(untrailingslashit($custom), $name, $language) as $path) {
                if (is_readable($path)) {
                    return ['text' => Encoding::readFile($path), 'source' => 'directory', 'path' => $path];
                }
            }
        }

        $path = self::builtinPath($name, $language);

        return ['text' => is_readable($path) ? Encoding::readFile($path) : '', 'source' => 'builtin', 'path' => $path];
    }

    public static function builtin(string $name, ?string $language = null): string
    {
        $path = self::builtinPath($name, $language ?? Options::language());

        return is_readable($path) ? Encoding::readFile($path) : '';
    }

    /**
     * Files looked for in the configured prompt directory, in order: the
     * language subfolder first, then the directory itself.
     *
     * @return list<string>
     */
    public static function directoryCandidates(string $dir, string $name, string $language): array
    {
        $candidates = [];
        if (self::isSafeLanguage($language)) {
            $candidates[] = $dir . '/' . $language . '/' . $name . '.md';
        }
        $candidates[] = $dir . '/' . $name . '.md';

        return $candidates;
    }

    public static function builtinPath(string $name, string $language): string
    {
        self::assertName($name);
        $base = untrailingslashit(AASPF_PLUGIN_DIR) . '/prompts/';

        // Bundled language folder if there is one (also for "de-at" → "de"), otherwise English.
        foreach ([$language, explode('-', $language)[0]] as $candidate) {
            if (self::isSafeLanguage($candidate) && is_readable($base . $candidate . '/' . $name . '.md')) {
                return $base . $candidate . '/' . $name . '.md';
            }
        }

        return $base . 'en/' . $name . '.md';
    }

    /** Only plain language codes become part of a path. */
    private static function isSafeLanguage(string $language): bool
    {
        return preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8}){0,2}$/', $language) === 1;
    }

    public static function override(string $name, string $language): string
    {
        $all = get_option(self::OPTION, []);

        return is_array($all) ? trim((string) ($all[$language][$name] ?? '')) : '';
    }

    /**
     * Saves an edited version; empty or identical to the default means: revert to the default.
     */
    public static function saveOverride(string $name, string $language, string $text): void
    {
        self::assertName($name);
        $all = get_option(self::OPTION, []);
        $all = is_array($all) ? $all : [];

        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($text === '' || $text === trim(self::builtin($name, $language))) {
            unset($all[$language][$name]);
        } else {
            $all[$language][$name] = $text;
        }

        update_option(self::OPTION, $all, false);
    }

    private static function assertName(string $name): void
    {
        if (!in_array($name, self::NAMES, true)) {
            /* translators: %s: requested prompt name */
            throw new \InvalidArgumentException(sprintf(__('Unknown prompt "%s".', 'sonoquill'), $name));
        }
    }
}
