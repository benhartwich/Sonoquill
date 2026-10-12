<?php
declare(strict_types=1);

namespace Sonoquill\Pipeline;

use Sonoquill\Ai\AnthropicClient;
use Sonoquill\Ai\DictionaryPrompt;
use Sonoquill\Db\EpisodeRepository;
use Sonoquill\Voice\DictionaryWriter;
use Sonoquill\Voice\PronunciationDictionary;

/**
 * Extends the pronunciation dictionary while the script is being generated.
 *
 * The timing is the whole point: the rules must be in place before synthesis
 * runs. If the maintenance only happened after listening back, the segments
 * would already have been generated with the old dictionary, and every
 * affected one would have to be paid for a second time.
 *
 * Flow: the edited script reports conspicuous terms, a model decides which of
 * them actually need a rule, the rules go first into the local PLS file and
 * then via `add-rules` to ElevenLabs, and the returned version is bound
 * immediately. A rule that nobody binds has no effect anywhere.
 */
final class DictionaryUpdate
{
    public static function run(int $episodeId): void
    {
        $episode = EpisodeRepository::find($episodeId);
        if ($episode === null) {
            return;
        }

        $candidates = array_values(array_filter(
            EpisodeRepository::decodeList($episode['pronunciation_candidates'] ?? null),
            'is_string'
        ));

        if ($candidates === []) {
            return;
        }

        $existing = PronunciationDictionary::graphemes();

        // Anything already in there is not submitted at all.
        $open = array_values(array_diff($candidates, $existing));
        if ($open === []) {
            EpisodeRepository::log($episodeId, 'woerterbuch', __('All reported terms are already in the dictionary.', 'sonoquill'));

            return;
        }

        try {
            $client = AnthropicClient::fromSettings('woerterbuch');
            $response = \Sonoquill\Ai\BatchGate::complete(
                $client,
                $episodeId,
                'woerterbuch',
                Scheduler::HOOK_DICTIONARY,
                DictionaryPrompt::system(),
                DictionaryPrompt::user($open, $existing),
                ['output_config' => DictionaryPrompt::outputConfig() + ['effort' => 'low'], 'max_tokens' => 8000]
            );

            // Previously not counted.
            EpisodeRepository::addCost($episodeId, $response->centsToBook());

            $data = $response->json();
            if ($data === null) {
                throw new \RuntimeException(__('The response was not JSON.', 'sonoquill'));
            }
        } catch (\Sonoquill\Ai\PendingBatch) {
            return;
        } catch (\Throwable $e) {
            EpisodeRepository::log($episodeId, 'woerterbuch', __('Proposal failed: ', 'sonoquill') . $e->getMessage());

            return;
        }

        $proposed = array_values(array_filter((array) ($data['regeln'] ?? []), 'is_array'));
        $skipped = array_values(array_filter((array) ($data['ohne_regel'] ?? []), 'is_string'));

        if ($proposed === []) {
            EpisodeRepository::log($episodeId, 'woerterbuch', sprintf(
                /* translators: %s: comma-separated list of checked terms */
                __('No rule needed. Checked: %s.', 'sonoquill'),
                implode(', ', $open)
            ));

            return;
        }

        $added = [];
        $failed = [];

        foreach ($proposed as $rule) {
            $grapheme = trim((string) ($rule['begriff'] ?? ''));
            $alias = trim((string) ($rule['aussprache'] ?? ''));
            $ipa = trim((string) ($rule['ipa'] ?? ''));

            if ($grapheme === '' || ($ipa === '' && $alias === '')) {
                continue;
            }

            // The term must actually appear in the script; otherwise a false
            // report from the model creates a rule that nobody needs and that
            // gets carried along in every future episode.
            if (!str_contains((string) $episode['script_text'], $grapheme)) {
                /* translators: %s: the dictionary term */
                $failed[] = sprintf(__('%s (not in the script)', 'sonoquill'), $grapheme);
                continue;
            }

            try {
                $result = DictionaryWriter::addRule($grapheme, $ipa, $alias);
                $added[] = [
                    'begriff'     => $grapheme,
                    'aussprache'  => $result['art'] === 'phoneme' ? $ipa : $alias,
                    'art'         => $result['art'],
                    'ipa'         => $ipa,
                    'begruendung' => (string) ($rule['begruendung'] ?? ''),
                    'version'     => $result['version_id'],
                ];
            } catch (\Throwable $e) {
                $failed[] = sprintf('%s (%s)', $grapheme, $e->getMessage());
            }
        }

        PronunciationDictionary::forget();

        // A second run (e.g. after an error) appends instead of replacing.
        $previous = EpisodeRepository::decodeList($episode['dictionary_added'] ?? null);
        EpisodeRepository::update($episodeId, [
            'dictionary_added' => (string) wp_json_encode(array_merge($previous, $added)),
        ]);

        EpisodeRepository::log($episodeId, 'woerterbuch', sprintf(
            /* translators: 1: number of rules created, 2: list of added terms or empty, 3: note on terms left without a rule or empty, 4: note on terms that could not be created or empty, 5: ElevenLabs dictionary version ID */
            __('%1$d rules created%2$s%3$s%4$s Bound version is now %5$s.', 'sonoquill'),
            count($added),
            $added === [] ? '' : ': ' . implode(', ', array_column($added, 'begriff')) . '.',
            $skipped === [] ? '' : __(' Left without a rule: ', 'sonoquill') . implode(', ', $skipped) . '.',
            $failed === [] ? '' : __(' Could not create: ', 'sonoquill') . implode('; ', $failed) . '.',
            \Sonoquill\Settings\Options::get('elevenlabs_dictionary_version_id')
        ));
    }
}
