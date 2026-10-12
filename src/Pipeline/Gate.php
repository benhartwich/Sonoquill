<?php
declare(strict_types=1);

namespace Sonoquill\Pipeline;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for the run log and e-mails; they are escaped where they are displayed.

use Sonoquill\Db\EpisodeRepository;
use Sonoquill\Db\EpisodeStatus;
use Sonoquill\Numbers\DiffResult;
use Sonoquill\Numbers\NumberDiff;
use Sonoquill\Numbers\ScriptGuard;

/**
 * The number and content checks, applied to a stored episode.
 *
 * Runs not just once after the edited script is produced, but again after
 * every change to the spoken script. Otherwise a manual correction could
 * introduce a new discrepancy without anyone noticing.
 */
final class Gate
{
    /**
     * Re-evaluates the episode and persists the result.
     *
     * @return array{blocking:bool,diff:array<string,mixed>,guard:list<array<string,mixed>>}
     */
    public static function evaluate(int $episodeId): array
    {
        $episode = EpisodeRepository::find($episodeId);
        if ($episode === null) {
            /* translators: %d: episode ID */
            throw new \RuntimeException(sprintf(__('Episode %d does not exist.', 'sonoquill'), $episodeId));
        }

        $source = self::numberSource($episode);
        $script = (string) ($episode['script_text'] ?? '');

        $gateOff = self::numberGateOffReason($episode);
        $diff = $gateOff === ''
            ? NumberDiff::compare($source, $script, \Sonoquill\Settings\Options::baseLanguage())
            : new DiffResult([], [], [], []);
        $guard = ScriptGuard::check($script);

        $acknowledged = EpisodeRepository::decodeList($episode['diff_acknowledged'] ?? null);
        $acknowledged = array_values(array_filter($acknowledged, 'is_string'));

        // Serious findings from the fact check must be acknowledged as well.
        // They do not block on their own — the check comes from a model, and
        // a rule is preferred over relying on a model behaving well. But
        // they cannot be overlooked.
        $openFacts = array_diff(FactCheck::blockingKeys($episodeId), $acknowledged);

        // A source can report its own findings that must be acknowledged —
        // for example numbers a model wrote into the fact script that do not
        // appear in the sources. Otherwise the number diff would only check
        // whether the spoken script faithfully reproduces an unsupported number.
        $openEvidence = array_diff(self::sourceKeys($episode), $acknowledged);

        $blocking = $diff->isBlockingAfter($acknowledged)
            || ScriptGuard::isBlocking($script)
            || $openFacts !== []
            || $openEvidence !== [];

        $diffArray = $diff->toArray();
        if ($gateOff !== '') {
            // Recorded so that the episode view and the mail can say why there are no number findings.
            $diffArray['aus'] = $gateOff;
        }

        $fields = [
            'diff_json'  => (string) wp_json_encode($diffArray),
            'guard_json' => (string) wp_json_encode($guard),
        ];

        // An approval that has already been granted is not silently revoked —
        // and a state beyond text approval even less so. Only if something
        // actually blocks does the episode go back.
        $current = (string) $episode['status'];
        $afterApproval = in_array($current, [
            EpisodeStatus::TEXT_APPROVED,
            EpisodeStatus::PRODUCING,
            EpisodeStatus::AWAITING_AUDIO,
            EpisodeStatus::DONE,
        ], true);

        if ($blocking) {
            $fields['status'] = EpisodeStatus::GATE_FAILED;
        } elseif (!$afterApproval) {
            $fields['status'] = EpisodeStatus::AWAITING_TEXT;
        }

        EpisodeRepository::update($episodeId, $fields);

        return [
            'blocking'    => $blocking,
            'diff'        => $diffArray,
            'guard'       => $guard,
            'offene_fakten' => array_values($openFacts),
            'offene_belege' => array_values($openEvidence),
        ];
    }

    /** Why the number gate does not run: the setting is off. */
    public const GATE_OFF_SETTING = 'einstellung';
    /** Why the number gate does not run: NumberDiff does not understand the language. */
    public const GATE_OFF_LANGUAGE = 'sprache';
    /** Why the number gate does not run: the source of the episode switched it off. */
    public const GATE_OFF_SOURCE = 'quelle';

    /**
     * Why the hard number gate is off for this episode, or '' if it runs.
     *
     * @param array<string,mixed> $episode
     */
    public static function numberGateOffReason(array $episode): string
    {
        if (!\Sonoquill\Settings\Options::flag('number_gate')) {
            return self::GATE_OFF_SETTING;
        }
        if (!NumberDiff::supports(\Sonoquill\Settings\Options::language())) {
            return self::GATE_OFF_LANGUAGE;
        }
        if (!\Sonoquill\Source\Sources::usesNumberGate($episode)) {
            return self::GATE_OFF_SOURCE;
        }

        return '';
    }

    /**
     * The side of the number diff that holds the source figures.
     *
     * @param array<string,mixed> $episode
     */
    public static function numberSource(array $episode): string
    {
        // Context blocks are background, not a fact source. A source that
        // writes source_text itself may have included them there.
        $blocks = EpisodeRepository::decodeList($episode['source_blocks'] ?? null);
        if ($blocks !== []) {
            $document = \Sonoquill\Text\SourceDocument::fromArray($blocks);
            if ($document->hasContext()) {
                return $document->bodyText();
            }
        }

        return (string) ($episode['source_text'] ?? '');
    }

    /**
     * Findings from the source that block approval until they are acknowledged.
     *
     * @param array<string,mixed> $episode
     *
     * @return list<string>
     */
    public static function sourceKeys(array $episode): array
    {
        return \Sonoquill\Source\Sources::forEpisode($episode)->blockingKeys($episode);
    }

    /**
     * All keys that can be overridden — invented numbers are deliberately
     * not among them.
     *
     * @return list<string>
     */
    public static function acknowledgeableKeys(int $episodeId): array
    {
        $episode = EpisodeRepository::find($episodeId);
        if ($episode === null) {
            return [];
        }

        $diff = EpisodeRepository::decodeMap($episode['diff_json'] ?? null);
        $keys = [];

        foreach (['fehlt', 'geaendert'] as $bucket) {
            foreach ((array) ($diff[$bucket] ?? []) as $finding) {
                if (isset($finding['key'])) {
                    $keys[] = (string) $finding['key'];
                }
            }
        }

        return array_merge($keys, FactCheck::blockingKeys($episodeId), self::sourceKeys($episode));
    }
}
