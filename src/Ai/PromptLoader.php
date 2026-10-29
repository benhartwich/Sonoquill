<?php
declare(strict_types=1);

namespace Sonoquill\Ai;

use Sonoquill\Settings\Options;
use Sonoquill\Voice\PronunciationDictionary;

/**
 * Assembles the prompt for the spoken script together with the dictionary list
 * and provides the prompt directory. Which version of a prompt applies is
 * handled by Prompts.
 */
final class PromptLoader
{
    /**
     * The configured prompt directory, otherwise the one bundled with the plugin.
     */
    public static function directory(): string
    {
        $configured = trim(Options::get('prompt_dir'));
        if ($configured !== '') {
            return untrailingslashit($configured);
        }

        return untrailingslashit(AASPF_PLUGIN_DIR) . '/prompts';
    }

    /**
     * Prompt for the spoken script including the dictionary section, exactly as it is sent to the model.
     *
     * @throws AnthropicException
     */
    public static function scriptSystemPrompt(): string
    {
        $style = self::styleSection(Options::get('style_guide'), Options::get('podcast_concept'), Options::isGerman());

        return Prompts::get('script')
            . ($style !== '' ? "\n\n---\n\n" . $style : '')
            . "\n\n---\n\n" . PronunciationDictionary::promptSection();
    }

    /**
     * Style and concept of the podcast as a prompt section (setting
     * style_guide and podcast_concept, e.g. from the platform import).
     * Empty when neither is set. The prompt's own rules take precedence.
     */
    public static function styleSection(string $style, string $concept, bool $german): string
    {
        $style = trim($style);
        $concept = trim($concept);
        if ($style === '' && $concept === '') {
            return '';
        }

        $out = $german
            ? "## STIL UND KONZEPT DES PODCASTS\n\nSo klingt dieser Podcast. Halte dich an Anrede, Ton, typische Wendungen und Aufbau, "
                . "soweit die Vorlage das zulässt. Die Regeln oben (Fakten, Zahlen, Ausgabeformat, Pausen) haben immer Vorrang. "
                . "Erfinde keine Inhalte, um eine Wendung unterzubringen.\n"
            : "## STYLE AND CONCEPT OF THE PODCAST\n\nThis is how the podcast sounds. Follow its form of address, tone, typical phrases and "
                . "structure as far as the source allows. The rules above (facts, numbers, output format, pauses) always take precedence. "
                . "Do not invent content to fit a phrase in.\n";

        if ($style !== '') {
            $out .= "\n<stil>\n" . $style . "\n</stil>\n";
        }
        if ($concept !== '') {
            $out .= "\n<konzept>\n" . $concept . "\n</konzept>\n";
        }

        return $out;
    }
}
