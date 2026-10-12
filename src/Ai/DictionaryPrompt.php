<?php
declare(strict_types=1);

namespace Sonoquill\Ai;

/**
 * Suggests pronunciation rules for the terms that the edited script flagged
 * as tricky.
 *
 * Both forms are requested: the phonetic transcription and a German
 * respelling. The one that is stored is the one that works on the configured
 * model — phonetic transcription on Eleven v3 or v4, otherwise the respelling. The
 * prompt must therefore deliver both and must not declare either form to be
 * secondary.
 */
final class DictionaryPrompt
{
    /**
     * The system prompt, see Prompts: edited, from the prompt directory
     * or bundled (prompts/<language>/dictionary.md).
     *
     * @throws AnthropicException
     */
    public static function system(): string
    {
        return Prompts::get('dictionary');
    }

    /**
     * @param list<string> $candidates
     * @param list<string> $existing
     */
    public static function user(array $candidates, array $existing): string
    {
        // German wording for German podcasts, English for every other language.
        $en = !\Sonoquill\Settings\Options::isGerman();

        return ($en ? "Already in the dictionary, do not add again:\n\n" : "Bereits im Wörterbuch, nicht noch einmal aufnehmen:\n\n")
            . ($existing === [] ? ($en ? '(nothing yet)' : '(noch nichts)') : implode(', ', $existing))
            . ($en ? "\n\n---\n\nTo assess:\n\n" : "\n\n---\n\nZu beurteilen:\n\n")
            . implode("\n", array_map(static fn (string $c): string => '- ' . $c, $candidates));
    }

    /**
     * @return array<string,mixed>
     */
    public static function outputConfig(): array
    {
        return [
            'format' => [
                'type'   => 'json_schema',
                'schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'regeln' => [
                            'type'  => 'array',
                            'items' => [
                                'type'       => 'object',
                                'properties' => [
                                    'begriff'     => ['type' => 'string'],
                                    'ipa'         => ['type' => 'string'],
                                    'aussprache'  => ['type' => 'string'],
                                    'begruendung' => ['type' => 'string'],
                                ],
                                'required'             => ['begriff', 'ipa', 'aussprache', 'begruendung'],
                                'additionalProperties' => false,
                            ],
                        ],
                        'ohne_regel' => [
                            'type'  => 'array',
                            'items' => ['type' => 'string'],
                        ],
                    ],
                    'required'             => ['regeln', 'ohne_regel'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }
}
