<?php
declare(strict_types=1);

namespace Sonoquill\Pipeline;

// phpcs:disable WordPress.WP.AlternativeFunctions.rename_rename -- Atomic rename within the plugin's own storage directory.

use Sonoquill\Audio\Ffmpeg;
use Sonoquill\Audio\MusicBed;
use Sonoquill\Audio\Transcript;
use Sonoquill\Db\EpisodeRepository;
use Sonoquill\Db\EpisodeStatus;
use Sonoquill\Segments\SegmentKind;
use Sonoquill\Segments\SegmentRepository;
use Sonoquill\Settings\Options;
use Sonoquill\Storage\EpisodeStorage;

/**
 * Assembly, chapter times and transcript.
 *
 * A chapter's start time is the sum of the measured durations of all preceding
 * segments plus the inserted pauses. It is exact because it is measured — which
 * is why assembly is done without crossfades, since each crossfade would shorten
 * every joint by its own length.
 */
final class Montage
{
    /** Silence at the end of the episode, in milliseconds. */
    private const TAIL_MS = 1500;

    public static function run(int $episodeId): void
    {
        $episode = EpisodeRepository::find($episodeId);
        if ($episode === null) {
            return;
        }

        // A text that has not (or no longer) been approved is not assembled —
        // otherwise an outdated version would be sent to Auphonic.
        if (in_array((string) $episode['status'], EpisodeStatus::BEFORE_TEXT_APPROVAL, true)) {
            EpisodeRepository::log($episodeId, 'montage', __('Skipped: the text has not been approved.', 'sonoquill'));

            return;
        }

        $segments = SegmentRepository::forEpisode($episodeId);
        if ($segments === []) {
            EpisodeRepository::log($episodeId, 'montage', __('There are no segments.', 'sonoquill'));

            return;
        }

        $missing = [];
        foreach ($segments as $segment) {
            if (!EpisodeStorage::exists((string) $segment['audio_path'])) {
                $missing[] = (int) $segment['idx'];
            }
        }

        if ($missing !== []) {
            EpisodeRepository::log($episodeId, 'montage', sprintf(
                /* translators: %s: comma-separated list of segment numbers */
                __('Aborted: audio is missing for segment %s.', 'sonoquill'),
                implode(', ', array_slice($missing, 0, 10))
            ));

            return;
        }

        $pauseMs = (int) (Options::get('chapter_pause_ms') !== '' ? Options::get('chapter_pause_ms') : '1500');
        // Without a pause between paragraphs, one paragraph runs into the next,
        // because the segments start and end with practically no lead-in or
        // lead-out. Shorter than a chapter pause, longer than one of the model's
        // sentence pauses (those are around two to four tenths of a second).
        $absatzMs = (int) (Options::get('paragraph_pause_ms') !== '' ? Options::get('paragraph_pause_ms') : '450');

        // As soon as a passage has been re-recorded, two speaking styles meet
        // and the loudness has to be matched.
        $hasPatched = false;
        foreach ($segments as $segment) {
            if ((string) $segment['source'] === 'sts') {
                $hasPatched = true;
                break;
            }
        }

        // The separator lengthens the chapter pauses, so it has to be known
        // before the speech is assembled.
        $music = MusicBed::active();
        $separatorMs = [];
        foreach ($music['trenner'] as $file) {
            $separatorMs[] = (int) (\Sonoquill\Audio\AudioEngine::durationMs($file) ?? 0);
        }
        $separatorAt = [];
        $boundary = 0;
        // Without ffmpeg Auphonic inserts the bridges; nothing overlaps.
        $php = \Sonoquill\Audio\AudioEngine::mode() === \Sonoquill\Audio\AudioEngine::MODE_PHP;
        $insertPlan = []; // [position after which, separator variant]
        $chapterPositions = [];

        $files = [];
        $dauern = [];
        $pausesAfter = [];
        $offsets = [];
        $chapters = [];
        $transcriptInput = [];
        $offset = 0;

        // Chapter titles are stored on the segment if the metadata already
        // existed at segmentation time. If the text was approved earlier, they
        // come from the episode — keyed by section number.
        $known = [];
        foreach (EpisodeRepository::decodeList($episode['chapters'] ?? null) as $chapter) {
            $known[(int) ($chapter['abschnitt'] ?? 0)] = trim((string) ($chapter['titel'] ?? ''));
        }
        $section = 0;

        foreach ($segments as $position => $segment) {
            $files[] = EpisodeStorage::absolutePath((string) $segment['audio_path']);
            $dauern[$position] = (int) $segment['duration_ms'];
            $offsets[$position] = $offset;

            if (in_array((string) $segment['kind'], [SegmentKind::INTRO, SegmentKind::CHAPTER_START], true)) {
                $section++;
                $title = trim((string) $segment['chapter_title']);
                if ($title === '') {
                    $title = $known[$section] ?? '';
                }
                if ($title !== '') {
                    $chapters[] = ['start_ms' => $offset, 'titel' => $title];
                    $chapterPositions[] = $position;
                }
            }

            $transcriptInput[] = [
                'offset_ms'   => $offset,
                'alignment'   => EpisodeRepository::decodeMap($segment['alignment_json'] ?? null),
                'text'        => (string) $segment['text'],
                'duration_ms' => (int) $segment['duration_ms'],
            ];

            $offset += (int) $segment['duration_ms'];

            // Pause after this segment: long before a chapter start, short
            // between two paragraphs of the same chapter, none at the end.
            $next = $segments[$position + 1] ?? null;
            if ($next !== null) {
                $laenge = $absatzMs;
                if ((string) $next['kind'] === SegmentKind::CHAPTER_START) {
                    $variant = MusicBed::separatorFor($boundary++, count($separatorMs));
                    if ($php && Options::directProduction()) {
                        // No bridge will be inserted: a plain chapter pause.
                        $laenge = $pauseMs;
                    } elseif ($php) {
                        // The bridge is inserted later; leave a short gap around the insert point.
                        $laenge = $variant >= 0 ? MusicBed::INSERT_BEFORE_MS + MusicBed::INSERT_AFTER_MS : $pauseMs;
                        if ($variant >= 0) {
                            $insertPlan[] = [$position, $variant];
                        }
                    } else {
                        $gap = MusicBed::chapterGap($variant >= 0 ? $separatorMs[$variant] : 0, $pauseMs);
                        $laenge = $gap['pause'];
                        if ($gap['start'] >= 0) {
                            $separatorAt[] = ['ms' => $offset + $gap['start'], 'datei' => $variant, 'dauer' => $separatorMs[$variant]];
                        }
                    }
                }

                if ($laenge > 0) {
                    $pausesAfter[$position] = $laenge;
                    $offset += $laenge;
                }
            }
        }

        // One and a half seconds of room tone after the last word. Previously
        // the file ended a hundred milliseconds after "Clear Skies" — that
        // sounded like a small click rather than an ending (the host, 26.09.2026).
        $last = count($segments) - 1;
        if ($last >= 0) {
            $pausesAfter[$last] = self::TAIL_MS;
            $offset += self::TAIL_MS;
        }

        $relative = EpisodeStorage::mixRelativePath($episodeId);
        $output = EpisodeStorage::absolutePath($relative);

        $roomTone = null;
        try {
            EpisodeStorage::ensureEpisodeDir($episodeId);

            if ($php) {
                // Frame by frame; the pauses land on the frame grid (26 ms), so
                // offsets are recomputed from what was actually written.
                $written = \Sonoquill\Audio\Mp3::concat($files, $pausesAfter, $output);
                $real = [];
                $sum = 0;
                foreach ($written as $position => $ms) {
                    $real[$position] = $sum;
                    $sum += $ms;
                }
                foreach ($transcriptInput as $position => &$entry) {
                    $entry['offset_ms'] = $real[$position];
                }
                unset($entry);
                foreach ($chapterPositions as $n => $position) {
                    $chapters[$n]['start_ms'] = $real[$position];
                }
                $offset = $sum;
            } else {
                // Room tone taken from our own material, so that the pauses
                // between sentences are not digital silence.
                $roomTone = Ffmpeg::extractRoomTone(
                    $files,
                    EpisodeStorage::absolutePath($episodeId . '/raumton.wav')
                );

                Ffmpeg::concat($files, $pausesAfter, $output, $hasPatched, $roomTone, $dauern);
            }
        } catch (\Throwable $e) {
            EpisodeRepository::log($episodeId, 'montage', __('Failed: ', 'sonoquill') . $e->getMessage());

            return;
        }

        // Opener and outro. If mixing them in fails, the plain speech assembly
        // is kept — an episode without music is better than no episode.
        $shift = 0;
        $musicNote = '';
        $montage = ['mode' => $php ? 'php' : 'ffmpeg'];
        if ($php && Options::directProduction()) {
            // Nobody adds the music later: the speech assembly is the episode.
            if ($music['opener'] !== null || $music['outro'] !== null || $music['trenner'] !== []) {
                $musicNote = __(' Without music: the production mode is "direct" and there is no ffmpeg to mix it in.', 'sonoquill');
            }
        } elseif ($php) {
            // Auphonic adds the music. Remember what it has to do, and move
            // chapters and transcript to where they will be in the finished file.
            $plan = self::auphonicPlan($music, $separatorMs, $insertPlan, $real ?? [], $offset);
            $montage += $plan;
            // Auphonic expects the chapters in the timeline of the uploaded
            // speech file and shifts them itself.
            $montage['chapters_speech'] = self::mergeChapterTimes($episode, $chapters);
            foreach ($transcriptInput as &$entry) {
                $entry['offset_ms'] = MusicBed::finalTime((int) $entry['offset_ms'], $plan['intro_shift_ms'], $plan['inserts']);
            }
            unset($entry);
            foreach ($chapters as $n => &$chapter) {
                $chapter['start_ms'] = $n === 0 ? 0 : MusicBed::finalTime((int) $chapter['start_ms'], $plan['intro_shift_ms'], $plan['inserts']);
            }
            unset($chapter);
            $musicNote = $plan['summary'];
        } elseif ($music['opener'] !== null || $music['outro'] !== null || $separatorAt !== []) {
            $speechFile = EpisodeStorage::absolutePath($episodeId . '/sprache.mp3');
            try {
                if (!@rename($output, $speechFile)) {
                    throw new \RuntimeException(__('The speech assembly could not be renamed.', 'sonoquill'));
                }

                $speechMs = \Sonoquill\Audio\AudioEngine::durationMs($speechFile) ?? $offset;
                $openerMs = $music['opener'] !== null ? (int) (\Sonoquill\Audio\AudioEngine::durationMs($music['opener']) ?? 0) : 0;
                $outroMs = $music['outro'] !== null ? (int) (\Sonoquill\Audio\AudioEngine::durationMs($music['outro']) ?? 0) : 0;
                $plan = MusicBed::plan($openerMs, $speechMs, $outroMs);

                Ffmpeg::wrapWithMusic($speechFile, $music['opener'], $music['outro'], $output, $plan, $openerMs, MusicBed::OVERLAP_MS, $music['trenner'], $separatorAt);

                $shift = $plan['speech'];
                $musicNote = sprintf(
                    /* translators: 1: list of music parts (opener, outro, separators), 2: time at which the voice starts */
                    __(' With %1$s, voice starts at %2$s, stereo.', 'sonoquill'),
                    implode(', ', array_filter([
                        $music['opener'] !== null ? 'Opener' : '',
                        $music['outro'] !== null ? 'Outro' : '',
                        /* translators: %d: number of separators */
                        $separatorAt !== [] ? sprintf(__('%d separators', 'sonoquill'), count($separatorAt)) : '',
                    ])),
                    Synthesis::formatDuration($shift)
                );
            } catch (\Throwable $e) {
                if (!is_readable($output) && is_readable($speechFile)) {
                    @rename($speechFile, $output);
                }
                $shift = 0;
                $musicNote = __(' Without music: ', 'sonoquill') . $e->getMessage()
                    . ($separatorAt !== [] ? __(' The chapter pauses are still lengthened for the separator.', 'sonoquill') : '');
            }
        }

        if ($shift > 0) {
            foreach ($transcriptInput as &$entry) {
                $entry['offset_ms'] += $shift;
            }
            unset($entry);

            // The first chapter begins with the opener; the others shift back.
            foreach ($chapters as $position => &$chapter) {
                $chapter['start_ms'] = $position === 0 ? 0 : $chapter['start_ms'] + $shift;
            }
            unset($chapter);
        }

        $measured = \Sonoquill\Audio\AudioEngine::durationMs($output) ?? $offset;
        // Without ffmpeg the mix is the speech alone; the episode will be as long as
        // speech plus the music Auphonic adds.
        $expected = $php ? $measured + (int) ($montage['added_ms'] ?? 0) : $measured;
        if (Options::directProduction()) {
            $montage['direct'] = true;
        }
        $transcript = Transcript::webvtt($transcriptInput, (string) ($episode['episode_title'] ?? ''));

        EpisodeStorage::write(EpisodeStorage::mixRelativePath($episodeId, 'vtt'), $transcript);

        EpisodeRepository::update($episodeId, [
            'mixed_audio_path' => $relative,
            'duration_ms'      => $expected,
            'montage_json'     => (string) wp_json_encode($montage),
            'chapters'         => (string) wp_json_encode(self::mergeChapterTimes($episode, $chapters)),
        ]);

        EpisodeRepository::log($episodeId, 'montage', sprintf(
            /* translators: 1: number of segments, 2: number of chapters, 3: measured duration, 4: calculated duration, 5: file size, 6: additional notes on loudness, pauses and music */
            __('Done: %1$d segments, %2$d chapters, duration %3$s (calculated %4$s), %5$s.%6$s', 'sonoquill'),
            count($files),
            count($chapters),
            Synthesis::formatDuration($measured),
            Synthesis::formatDuration($offset),
            size_format(EpisodeStorage::size($relative)),
            ($hasPatched ? __(' Loudness matched because re-recorded segments are included.', 'sonoquill') : '')
            . ($php
                ? __(' Joined in PHP (no ffmpeg), pauses are digital silence.', 'sonoquill')
                : ($roomTone === null ? __(' No quiet passage found for room tone, pauses are digital silence.', 'sonoquill') : __(' Pauses filled with room tone.', 'sonoquill')))
            . $musicNote
        ));

        /**
         * Action: the montage of an episode is finished.
         *
         * @param int    $episodeId Episode ID.
         * @param string $relative  Path of the mix relative to the storage directory (EpisodeStorage::absolutePath()).
         */
        do_action('sonoquill_montage_done', $episodeId, $relative);

        AutoChain::afterMontage($episodeId);
    }

    /**
     * What Auphonic has to add when the assembly runs without ffmpeg.
     *
     * The opener overlaps the first words (ducked), the outro starts GAP_MS after
     * the last word — the speech file ends with TAIL_MS of silence, so the outro
     * overlaps part of it — and each bridge is inserted INSERT_AFTER_MS before
     * the next chapter.
     *
     * @param array{opener:?string,outro:?string,trenner:list<string>} $music
     * @param list<int>                                                  $separatorMs
     * @param list<array{0:int,1:int}>                                   $insertPlan  [position after which, variant]
     * @param array<int,int>                                             $offsets     real start of each segment in the speech file
     *
     * @return array{intro:?array{file:string,ms:int,overlap_ms:int},outro:?array{file:string,ms:int,overlap_ms:int},inserts:list<array{file:string,at_ms:int,ms:int}>,intro_shift_ms:int,added_ms:int,summary:string}
     */
    private static function auphonicPlan(array $music, array $separatorMs, array $insertPlan, array $offsets, int $speechMs): array
    {
        $intro = null;
        if ($music['opener'] !== null) {
            $ms = (int) (\Sonoquill\Audio\AudioEngine::durationMs($music['opener']) ?? 0);
            if ($ms > 0) {
                $intro = ['file' => $music['opener'], 'ms' => $ms, 'overlap_ms' => min(MusicBed::AUPHONIC_INTRO_OVERLAP_MS, intdiv($ms, 2))];
            }
        }

        $outro = null;
        if ($music['outro'] !== null) {
            $ms = (int) (\Sonoquill\Audio\AudioEngine::durationMs($music['outro']) ?? 0);
            if ($ms > 0) {
                $outro = ['file' => $music['outro'], 'ms' => $ms, 'overlap_ms' => max(0, self::TAIL_MS - MusicBed::GAP_MS)];
            }
        }

        $inserts = [];
        foreach ($insertPlan as [$position, $variant]) {
            if (!isset($offsets[$position + 1], $music['trenner'][$variant])) {
                continue;
            }
            $inserts[] = [
                'file'  => $music['trenner'][$variant],
                'at_ms' => $offsets[$position + 1] - MusicBed::INSERT_AFTER_MS,
                'ms'    => (int) $separatorMs[$variant],
            ];
        }

        $introShift = $intro !== null ? $intro['ms'] - $intro['overlap_ms'] : 0;
        $added = $introShift + array_sum(array_column($inserts, 'ms')) + ($outro !== null ? $outro['ms'] - $outro['overlap_ms'] : 0);

        $parts = array_filter([
            $intro !== null ? __('opener', 'sonoquill') : '',
            $outro !== null ? __('outro', 'sonoquill') : '',
            /* translators: %d: number of music bridges */
            $inserts !== [] ? sprintf(_n('%d bridge', '%d bridges', count($inserts), 'sonoquill'), count($inserts)) : '',
        ]);

        return [
            'intro'          => $intro,
            'outro'          => $outro,
            'inserts'        => $inserts,
            'intro_shift_ms' => $introShift,
            'added_ms'       => $added,
            'summary'        => $parts === []
                ? ''
                /* translators: %s: list like "opener, outro, 5 bridges" */
                : sprintf(__(' Auphonic will add: %s.', 'sonoquill'), implode(', ', $parts)),
        ];
    }

    /**
     * Keeps the chapter headings from the metadata step and adds the measured
     * start times.
     *
     * @param array<string,mixed>                      $episode
     * @param list<array{start_ms:int,titel:string}>    $measured
     *
     * @return list<array{abschnitt:int,titel:string,start_ms:int}>
     */
    private static function mergeChapterTimes(array $episode, array $measured): array
    {
        $existing = EpisodeRepository::decodeList($episode['chapters'] ?? null);
        $chapters = [];

        foreach ($measured as $position => $chapter) {
            $titel = $chapter['titel'];
            if ($titel === '' && isset($existing[$position]['titel'])) {
                $titel = (string) $existing[$position]['titel'];
            }

            $chapters[] = [
                'abschnitt' => $position + 1,
                'titel'     => $titel,
                'start_ms'  => $chapter['start_ms'],
            ];
        }

        return $chapters;
    }
}
