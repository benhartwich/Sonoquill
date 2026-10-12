<?php
declare(strict_types=1);

namespace Sonoquill\Pipeline;

use Sonoquill\Ai\AnthropicClient;
use Sonoquill\Ai\FactCheckPrompt;
use Sonoquill\Db\EpisodeRepository;
use Sonoquill\Text\SourceDocument;

/**
 * The content comparison, run after the number diff.
 */
final class FactCheck
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

        $blocks = EpisodeRepository::decodeList($episode['source_blocks'] ?? null);
        $source = $blocks === []
            ? (string) ($episode['source_text'] ?? '')
            : SourceDocument::fromArray($blocks)->forFactCheck();

        try {
            $client = AnthropicClient::fromSettings('faktenpruefung');
            $response = \Sonoquill\Ai\BatchGate::complete(
                $client,
                $episodeId,
                'faktenpruefung',
                Scheduler::HOOK_FACTCHECK,
                FactCheckPrompt::system(),
                FactCheckPrompt::user($source, $script),
                ['output_config' => FactCheckPrompt::outputConfig() + ['effort' => 'medium'], 'max_tokens' => 32000]
            );

            $data = $response->json();
            if ($data === null) {
                throw new \RuntimeException(__('The response was not JSON.', 'sonoquill'));
            }

            $findings = array_values(array_filter(
                (array) ($data['befunde'] ?? []),
                static fn ($f): bool => is_array($f)
            ));

            EpisodeRepository::update($episodeId, [
                'factcheck_json' => (string) wp_json_encode($findings),
            ]);

            $severe = count(array_filter(
                $findings,
                static fn (array $f): bool => ($f['schwere'] ?? '') === 'hoch'
            ));

            EpisodeRepository::addCost($episodeId, $response->centsToBook());

            EpisodeRepository::log($episodeId, 'faktenpruefung', sprintf(
                /* translators: 1: number of fact-check findings, 2: number of severe findings, 3: input tokens, 4: output tokens, 5: estimated cost in US cents */
                __('%1$d findings, %2$d of them severe. Tokens: %3$d in, %4$d out, estimated %5$s US cents.', 'sonoquill'),
                count($findings),
                $severe,
                $response->inputTokens,
                $response->outputTokens,
                number_format_i18n($response->estimatedCents(), 2)
            ));

            Gate::evaluate($episodeId);
        } catch (\Sonoquill\Ai\PendingBatch) {
            return;
        } catch (\Throwable $e) {
            EpisodeRepository::log($episodeId, 'faktenpruefung', __('Failed: ', 'sonoquill') . $e->getMessage());
        }

        // The mail is only sent once the edited script, the fact check and the
        // metadata are all done; whichever finishes last sends it.
        \Sonoquill\Notify\Notifier::maybeTextReady($episodeId);
    }

    /**
     * Keys of the severe findings that must be confirmed.
     *
     * @return list<string>
     */
    public static function blockingKeys(int $episodeId): array
    {
        $episode = EpisodeRepository::find($episodeId);
        if ($episode === null) {
            return [];
        }

        $keys = [];
        foreach (EpisodeRepository::decodeList($episode['factcheck_json'] ?? null) as $index => $finding) {
            if (($finding['schwere'] ?? '') === 'hoch') {
                $keys[] = 'fakt:' . $index;
            }
        }

        return $keys;
    }
}
