<?php
declare(strict_types=1);

namespace Sonoquill\Pipeline;

use Sonoquill\Ai\AnthropicClient;
use Sonoquill\Ai\MetadataPrompt;
use Sonoquill\Db\EpisodeRepository;

/**
 * The second call: title, descriptions, keywords, chapters and
 * pronunciation candidates from the finished spoken script.
 *
 * If it fails, the episode's state stays untouched. The script is the
 * expensive part; the publishing metadata can be regenerated at any time.
 */
final class Metadata
{
    public static function run(int $episodeId): void
    {
        $episode = EpisodeRepository::find($episodeId);
        if ($episode === null) {
            return;
        }

        $script = trim((string) ($episode['script_text'] ?? ''));
        if ($script === '') {
            return;
        }

        try {
            $client = AnthropicClient::fromSettings('metadaten');
            $sections = MetadataPrompt::sections($script);

            $response = \Sonoquill\Ai\BatchGate::complete(
                $client,
                $episodeId,
                'metadaten',
                Scheduler::HOOK_METADATA,
                MetadataPrompt::system(),
                MetadataPrompt::user($sections),
                [
                    'output_config' => MetadataPrompt::outputConfig() + ['effort' => 'low'],
                    'max_tokens'    => 8000,
                ]
            );

            $data = $response->json();
            if ($data === null) {
                throw new \RuntimeException(__('The response was not JSON.', 'sonoquill'));
            }

            EpisodeRepository::update($episodeId, [
                'episode_title'            => mb_substr((string) ($data['episode_title'] ?? ''), 0, 255),
                'description_short'        => (string) ($data['description_short'] ?? ''),
                'description_long'         => (string) ($data['description_long'] ?? ''),
                'keywords'                 => (string) wp_json_encode(array_values((array) ($data['keywords'] ?? []))),
                'chapters'                 => (string) wp_json_encode(self::chapters($data, $sections)),
                'pronunciation_candidates' => (string) wp_json_encode(array_values((array) ($data['pronunciation_candidates'] ?? []))),
            ]);

            // The candidates are now settled — the dictionary is maintained
            // before any segment is generated.
            Scheduler::queueDictionary($episodeId);

            EpisodeRepository::addCost($episodeId, $response->centsToBook());

            EpisodeRepository::log($episodeId, 'metadaten', sprintf(
                /* translators: 1: number of chapters, 2: number of keywords, 3: input tokens, 4: output tokens, 5: estimated cost in US cents */
                __('Generated: %1$d chapters, %2$d keywords. Tokens: %3$d in, %4$d out, estimated %5$s US cents.', 'sonoquill'),
                count((array) ($data['chapters'] ?? [])),
                count((array) ($data['keywords'] ?? [])),
                $response->inputTokens,
                $response->outputTokens,
                number_format_i18n($response->estimatedCents(), 2)
            ));
        } catch (\Sonoquill\Ai\PendingBatch) {
            return;
        } catch (\Throwable $e) {
            EpisodeRepository::log($episodeId, 'metadaten', __('Failed: ', 'sonoquill') . $e->getMessage());
        }

        // The mail goes out only once editing, fact check and metadata are
        // all done; whichever finishes last sends it.
        \Sonoquill\Notify\Notifier::maybeTextReady($episodeId);
    }

    /**
     * Chapters as pairs of heading and section number.
     *
     * The mapping to segments and the start times are only created later,
     * once the audio exists, measured rather than estimated. Here we only
     * record which section carries which heading.
     *
     * @param array<string,mixed> $data
     * @param list<string>        $sections
     *
     * @return list<array{abschnitt:int,titel:string}>
     */
    private static function chapters(array $data, array $sections): array
    {
        $titles = array_values(array_filter((array) ($data['chapters'] ?? []), 'is_string'));
        $chapters = [];

        foreach ($sections as $index => $section) {
            $chapters[] = [
                'abschnitt' => $index + 1,
                'titel'     => isset($titles[$index]) ? trim($titles[$index]) : '',
            ];
        }

        return $chapters;
    }
}
