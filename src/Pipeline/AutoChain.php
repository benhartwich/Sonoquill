<?php
declare(strict_types=1);

namespace Sonoquill\Pipeline;

use Sonoquill\Db\EpisodeRepository;
use Sonoquill\Db\EpisodeStatus;
use Sonoquill\Segments\Segmenter;
use Sonoquill\Segments\SegmentRepository;

/**
 * The chain between the two human gates runs on its own.
 *
 * There are two human gates: the text approval (before any audio is
 * produced) and the audio approval (before the episode is published).
 * Neither is optional; the rest runs through. Until now it did not run all
 * the way through — generating audio and handing it to Auphonic were each a
 * button. For episodes with `auto_chain` this class takes over: after text
 * approval, segments and synthesis; after montage, the production. Audio
 * approval stays as it is; it is the second gate.
 *
 * In the production mode "direct" the montage goes to Podlove without
 * Auphonic; the same rule applies to the draft.
 *
 * An episode is sent to Auphonic automatically exactly once. Whoever
 * re-records segments afterwards and re-runs the montage decides for
 * themselves when the new version is produced — otherwise every correction
 * would kick off a paid production.
 */
final class AutoChain
{
    /**
     * @param array<string,mixed> $episode
     */
    public static function isActive(array $episode): bool
    {
        return (int) ($episode['auto_chain'] ?? 0) === 1;
    }

    /**
     * After text approval: create segments, schedule synthesis.
     *
     * @return string|null What happened, or null if the episode does not run automatically.
     */
    public static function afterTextApproval(int $episodeId): ?string
    {
        $episode = EpisodeRepository::find($episodeId);
        if ($episode === null || !self::isActive($episode)) {
            return null;
        }

        $result = Segmenter::rebuild($episodeId);
        Scheduler::queueSynthesis($episodeId);

        $message = sprintf(
            /* translators: 1: total number of segments, 2: number of newly created segments */
            __('Continuing automatically: %1$d segments, %2$d of them new. Synthesis is running, then montage and Auphonic.', 'sonoquill'),
            $result['gesamt'],
            $result['angelegt']
        );
        EpisodeRepository::log($episodeId, 'automatik', $message);

        return $message;
    }

    /**
     * After a successful montage: send to Auphonic the first time — or, in
     * the production mode "direct", create the Podlove draft the first time.
     */
    public static function afterMontage(int $episodeId): void
    {
        $episode = EpisodeRepository::find($episodeId);
        if ($episode === null || !self::isActive($episode)) {
            return;
        }

        $direct = \Sonoquill\Settings\Options::directProduction();
        $alreadySent = $direct
            ? (int) ($episode['podlove_post_id'] ?? 0) > 0
            : (string) ($episode['auphonic_production_uuid'] ?? '') !== '';

        if ((string) $episode['status'] !== EpisodeStatus::TEXT_APPROVED || $alreadySent) {
            return;
        }

        $summary = SegmentRepository::summary($episodeId);
        if ($summary['offen'] > 0 || $summary['beanstandet'] > 0) {
            return;
        }

        if ($direct) {
            Scheduler::queuePublish($episodeId);
            EpisodeRepository::log($episodeId, 'automatik', __('Montage complete, the Podlove draft is being created directly from it (without Auphonic).', 'sonoquill'));

            return;
        }

        Scheduler::queueProduction($episodeId);
        EpisodeRepository::log($episodeId, 'automatik', __('Montage complete, the episode is being sent to Auphonic automatically.', 'sonoquill'));
    }
}
