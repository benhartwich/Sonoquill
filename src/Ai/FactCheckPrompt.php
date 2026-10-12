<?php
declare(strict_types=1);

namespace Sonoquill\Ai;

/**
 * Content-level verification of the spoken script against the source.
 *
 * Complements the number diff, does not replace it. The number diff is
 * deterministic and blocks hard — deliberately so, because a
 * safeguard based on a rule is preferable to one that relies on the model
 * behaving well. But it only sees numbers. Whether Venus moves through
 * Cancer instead of Gemini is something it does not notice.
 *
 * This check catches exactly that. Because it comes from a model itself,
 * it does not block on its own: serious findings must be confirmed, and
 * the decision is made by hand.
 */
final class FactCheckPrompt
{
    /**
     * The system prompt, see Prompts: edited, from the prompt directory
     * or bundled (prompts/<language>/factcheck.md).
     *
     * @throws AnthropicException
     */
    public static function system(): string
    {
        return Prompts::get('factcheck');
    }

    public static function user(string $source, string $script): string
    {
        // German wording for German podcasts, English for every other language.
        $en = !\Sonoquill\Settings\Options::isGerman();

        return ($en ? "## Source, the fact script\n\n" : "## Vorlage, das Faktenskript\n\n") . trim($source)
            . ($en ? "\n\n---\n\n## Spoken script, to check\n\n" : "\n\n---\n\n## Sprechskript, zu prüfen\n\n") . trim($script);
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
                        'befunde' => [
                            'type'  => 'array',
                            'items' => [
                                'type'       => 'object',
                                'properties' => [
                                    'art'        => ['type' => 'string', 'enum' => ['verändert', 'fehlt', 'erfunden']],
                                    'schwere'    => ['type' => 'string', 'enum' => ['hoch', 'mittel', 'niedrig']],
                                    'vorlage'    => ['type' => 'string'],
                                    'skript'     => ['type' => 'string'],
                                    'begruendung'=> ['type' => 'string'],
                                ],
                                'required'             => ['art', 'schwere', 'vorlage', 'skript', 'begruendung'],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                    'required'             => ['befunde'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }
}
