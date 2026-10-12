<?php
declare(strict_types=1);

namespace Sonoquill\Ai;

use Sonoquill\Voice\PronunciationDictionary;

/**
 * The second call: title, descriptions, keywords, chapters.
 *
 * Kept separate from the spoken script because the system prompt for the
 * script explicitly demands "plain running text, nothing else". The reasoning
 * for a separate call still holds — the model knows the full text here.
 *
 * Chapters are not invented freely; they come from the break tags the model
 * already placed in the script. This way it decides the count itself, without
 * being able to invent positions that won't exist later.
 */
final class MetadataPrompt
{
    /**
     * The system prompt, see Prompts: edited, from the prompt directory,
     * or bundled (prompts/<language>/metadata.md).
     *
     * @throws AnthropicException
     */
    public static function system(): string
    {
        return Prompts::get('metadata');
    }

    /**
     * @param list<string> $sections
     */
    public static function user(array $sections): string
    {
        // German wording for German podcasts, English for every other language.
        $en = !\Sonoquill\Settings\Options::isGerman();
        $parts = [];
        foreach ($sections as $index => $section) {
            $parts[] = sprintf($en ? "### Section %d\n\n%s" : "### Abschnitt %d\n\n%s", $index + 1, trim($section));
        }

        return ($en ? "Already in the pronunciation dictionary:\n\n" : "Bereits im Aussprachewörterbuch hinterlegt:\n\n")
            . (PronunciationDictionary::graphemes() === []
                ? ($en ? '(no dictionary connected)' : '(kein Wörterbuch eingebunden)')
                : implode(', ', PronunciationDictionary::graphemes()))
            . ($en ? "\n\n---\n\nThe spoken script in " : "\n\n---\n\nDas Sprechskript in ")
            . count($sections)
            . ($en ? " sections:\n\n" : " Abschnitten:\n\n")
            . implode("\n\n", $parts);
    }

    /**
     * Enforced output format. Without it, running text would come back that
     * would then have to be guessed at.
     *
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
                        'episode_title'     => ['type' => 'string'],
                        'description_short' => ['type' => 'string'],
                        'description_long'  => ['type' => 'string'],
                        'keywords'          => [
                            'type'  => 'array',
                            'items' => ['type' => 'string'],
                        ],
                        'chapters' => [
                            'type'  => 'array',
                            'items' => ['type' => 'string'],
                        ],
                        'pronunciation_candidates' => [
                            'type'  => 'array',
                            'items' => ['type' => 'string'],
                        ],
                    ],
                    'required' => [
                        'episode_title',
                        'description_short',
                        'description_long',
                        'keywords',
                        'chapters',
                        'pronunciation_candidates',
                    ],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /**
     * Splits the spoken script into sections at the break tags.
     *
     * @return list<string>
     */
    public static function sections(string $script): array
    {
        $parts = preg_split('/<break\b[^>]*>/iu', $script) ?: [];

        $sections = [];
        foreach ($parts as $part) {
            $trimmed = trim($part);
            if ($trimmed !== '') {
                $sections[] = $trimmed;
            }
        }

        return $sections === [] ? [trim($script)] : $sections;
    }
}
