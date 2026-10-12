<?php
declare(strict_types=1);

namespace Sonoquill\Pipeline;

use Sonoquill\Ai\AnthropicClient;
use Sonoquill\Ai\AnthropicException;
use Sonoquill\Ai\PromptLoader;
use Sonoquill\Db\EpisodeRepository;
use Sonoquill\Db\EpisodeStatus;

/**
 * The fact script is turned into the spoken script.
 *
 * One call, plain running text back — that is what the system prompt demands.
 * The publication metadata is produced in the second call.
 */
final class Redigat
{
    public static function run(int $episodeId): void
    {
        $episode = EpisodeRepository::find($episodeId);
        if ($episode === null) {
            return;
        }

        EpisodeRepository::update($episodeId, ['status' => EpisodeStatus::REDIGAT_RUNNING]);

        try {
            $client = AnthropicClient::fromSettings('redigat');
            $system = PromptLoader::scriptSystemPrompt();
            $source = (string) ($episode['source_text'] ?? '');

            if (trim($source) === '') {
                throw new AnthropicException(__('The episode has no fact script.', 'sonoquill'));
            }

            $blocks = EpisodeRepository::decodeList($episode['source_blocks'] ?? null);
            $document = \Sonoquill\Text\SourceDocument::fromArray($blocks);
            $vorlage = $blocks === [] ? $source : $document->forPrompt();

            EpisodeRepository::log($episodeId, 'redigat', sprintf(
                /* translators: 1: model name, 2: length of the source text in characters */
                __('Calling %1$s, source text %2$d characters.', 'sonoquill'),
                $client->model(),
                mb_strlen($vorlage)
            ));

            // Set effort explicitly: without it, Opus 5.5 thinks at "medium",
            // Opus 5 at "high". "medium" is enough for the rewording.
            $response = \Sonoquill\Ai\BatchGate::complete(
                $client,
                $episodeId,
                'redigat',
                Scheduler::HOOK_REDIGAT,
                $system,
                $vorlage,
                // Thinking counts against max_tokens. Opus 5.5 thinks longer than
                // Opus 5; 64,000 safely leaves room for the text.
                ['output_config' => ['effort' => 'medium'], 'max_tokens' => 64000]
            );
            $script = trim($response->text);

            // chars_billed counts only the ElevenLabs quota.
            // The length of the spoken script is Anthropic output and only
            // becomes quota once a segment from it is synthesized —
            // otherwise it would appear twice on the bill.
            EpisodeRepository::update($episodeId, ['script_text' => $script]);

            EpisodeRepository::addCost($episodeId, $response->centsToBook());

            EpisodeRepository::log($episodeId, 'redigat', sprintf(
                /* translators: 1: script length in characters, 2: input tokens, 3: output tokens, 4: estimated cost in US cents */
                __('Spoken script generated: %1$d characters. Tokens: %2$d in, %3$d out, estimated %4$s US cents.', 'sonoquill'),
                mb_strlen($script),
                $response->inputTokens,
                $response->outputTokens,
                number_format_i18n($response->estimatedCents(), 2)
            ));

            Gate::evaluate($episodeId);
            Scheduler::queueMetadata($episodeId);
            Scheduler::queueFactCheck($episodeId);
        } catch (\Sonoquill\Ai\PendingBatch) {
            // The response arrives as a batch; the step is triggered again afterwards.
            return;
        } catch (\Throwable $e) {
            EpisodeRepository::update($episodeId, ['status' => EpisodeStatus::REDIGAT_FAILED]);
            EpisodeRepository::log($episodeId, 'redigat', __('Failed: ', 'sonoquill') . $e->getMessage());
        }
    }
}
