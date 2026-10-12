<?php
declare(strict_types=1);

namespace Sonoquill\Pipeline;

use Sonoquill\Auphonic\AuphonicClient;
use Sonoquill\Db\EpisodeRepository;
use Sonoquill\Db\EpisodeStatus;
use Sonoquill\Podlove\EpisodeDraft;
use Sonoquill\Podlove\MediaStore;
use Sonoquill\Storage\EpisodeStorage;

/**
 * Fetch the finished file and store the episode as a Podlove draft.
 *
 * Triggered by the Auphonic callback — or, in the production mode
 * "direct", right after the montage, with the montage MP3 as the finished
 * file. The draft is never published — that is the second human gate
 * (audio approval).
 */
final class Publish
{
    public static function run(int $episodeId): void
    {
        $episode = EpisodeRepository::find($episodeId);
        if ($episode === null) {
            return;
        }

        if (\Sonoquill\Settings\Options::directProduction()) {
            self::runDirect($episodeId, $episode);

            return;
        }

        $uuid = (string) ($episode['auphonic_production_uuid'] ?? '');
        if ($uuid === '') {
            return;
        }

        try {
            $client = AuphonicClient::fromSettings();
            $details = $client->details($uuid);

            $status = (string) ($details['status_string'] ?? '');
            if ((int) ($details['status'] ?? -1) !== 3) {
                EpisodeRepository::log($episodeId, 'auphonic', sprintf(
                    /* translators: %s: Auphonic production status */
                    __('Not finished yet, status "%s". Waiting for the next callback.', 'sonoquill'),
                    $status
                ));

                return;
            }

            $datei = self::audioFile($details);
            if ($datei === null) {
                throw new \RuntimeException(__('Auphonic does not list an output file.', 'sonoquill'));
            }

            $slug = (string) ($episode['podlove_slug'] ?? '');
            if ($slug === '') {
                throw new \RuntimeException(__('The episode has no Podlove slug.', 'sonoquill'));
            }

            $audio = $client->download($datei['url']);

            // Auphonic reports the size and checksum of the file. Checking both
            // costs nothing and prevents a truncated download from ending up
            // in the feed as a finished episode.
            if ($datei['size'] > 0 && strlen($audio) !== $datei['size']) {
                throw new \RuntimeException(sprintf(
                    /* translators: 1: bytes received, 2: expected bytes */
                    __('The file is incomplete: %1$d of %2$d bytes received.', 'sonoquill'),
                    strlen($audio),
                    $datei['size']
                ));
            }

            if ($datei['checksum'] !== '' && md5($audio) !== $datei['checksum']) {
                throw new \RuntimeException(__('The checksum of the downloaded file does not match.', 'sonoquill'));
            }

            $audioPath = MediaStore::write($slug, 'mp3', $audio);

            // Without ffmpeg Auphonic added opener, bridges and outro; check our
            // predicted times against the chapters it reports.
            $montage = EpisodeRepository::decodeMap($episode['montage_json'] ?? null);
            if (($montage['mode'] ?? '') === 'php') {
                self::reconcileTimeline($episodeId, $episode, $audio, (int) ($montage['intro_shift_ms'] ?? 0));
            }

            // The transcript comes from the mix and is exact; it is
            // not fetched from Auphonic.
            $vtt = EpisodeStorage::mixRelativePath($episodeId, 'vtt');
            $transcriptWritten = false;
            if (EpisodeStorage::exists($vtt)) {
                MediaStore::write($slug, 'vtt', (string) file_get_contents(EpisodeStorage::absolutePath($vtt)));
                $transcriptWritten = true;
            }

            EpisodeRepository::update($episodeId, ['production_url' => $datei['url']]);

            // If the finished file is noticeably longer than our mix,
            // Auphonic appended something — with free credits, its jingle.
            $warning = '';
            $mixMs = (int) ($episode['duration_ms'] ?? 0);
            $outMs = (int) round((float) ($details['length'] ?? 0) * 1000);
            if ($mixMs > 0 && $outMs - $mixMs > 3000) {
                $warning = sprintf(
                    /* translators: %s: number of seconds */
                    __('Warning: the finished file is %s seconds longer than the mix. Auphonic probably appended its jingle because only free credits were left. Buy more credits and send the episode to Auphonic again.', 'sonoquill'),
                    number_format_i18n(($outMs - $mixMs) / 1000, 1)
                );
                EpisodeRepository::log($episodeId, 'auphonic', $warning);
            }

            $postId = EpisodeDraft::upsert($episodeId);
            $attached = EpisodeDraft::attachAssets($episodeId);

            EpisodeRepository::update($episodeId, ['status' => EpisodeStatus::AWAITING_AUDIO]);

            EpisodeRepository::log($episodeId, 'podlove', sprintf(
                /* translators: 1: post ID, 2: file name, 3: file size, 4: transcript note, 5: list of linked assets */
                __('Draft #%1$d created. File %2$s (%3$s)%4$s. Linked: %5$s.', 'sonoquill'),
                $postId,
                basename($audioPath),
                size_format(strlen($audio)),
                $transcriptWritten ? __(', transcript included', 'sonoquill') : __(', without transcript', 'sonoquill'),
                $attached === [] ? __('nothing', 'sonoquill') : implode(', ', $attached)
            ));

            \Sonoquill\Notify\Notifier::audioReady($episodeId, $warning);
        } catch (\Throwable $e) {
            EpisodeRepository::log($episodeId, 'podlove', __('Failed: ', 'sonoquill') . $e->getMessage());
        }
    }

    /**
     * Production mode "direct": the montage MP3 is the finished file.
     * Duration and chapters were measured by the montage and are already
     * stored in the episode.
     *
     * @param array<string,mixed> $episode
     */
    private static function runDirect(int $episodeId, array $episode): void
    {
        try {
            if (in_array((string) $episode['status'], EpisodeStatus::BEFORE_TEXT_APPROVAL, true)) {
                throw new \RuntimeException(__('The text has not been approved.', 'sonoquill'));
            }

            $mix = (string) ($episode['mixed_audio_path'] ?? '');
            if ($mix === '' || !EpisodeStorage::exists($mix)) {
                throw new \RuntimeException(__('There is no assembled mix.', 'sonoquill'));
            }

            $title = trim((string) ($episode['episode_title'] ?? ''));
            if ($title === '') {
                throw new \RuntimeException(__('The episode has no title.', 'sonoquill'));
            }

            $slug = (string) ($episode['podlove_slug'] ?? '');
            if ($slug === '') {
                $slug = \Sonoquill\Podlove\SlugBuilder::fromTitle($title, \Sonoquill\Settings\Options::filePrefix());
                EpisodeRepository::update($episodeId, ['podlove_slug' => $slug]);
            }

            $audio = (string) file_get_contents(EpisodeStorage::absolutePath($mix));
            $audioPath = MediaStore::write($slug, 'mp3', $audio);

            // The measured length of the file is the truth; the stored one may
            // still include music that Auphonic would have added.
            $measured = \Sonoquill\Audio\AudioEngine::durationMs(EpisodeStorage::absolutePath($mix));
            if ($measured !== null && $measured > 0) {
                EpisodeRepository::update($episodeId, ['duration_ms' => $measured]);
            }

            $vtt = EpisodeStorage::mixRelativePath($episodeId, 'vtt');
            $transcriptWritten = false;
            if (EpisodeStorage::exists($vtt)) {
                MediaStore::write($slug, 'vtt', (string) file_get_contents(EpisodeStorage::absolutePath($vtt)));
                $transcriptWritten = true;
            }

            $postId = EpisodeDraft::upsert($episodeId);
            $attached = EpisodeDraft::attachAssets($episodeId);

            EpisodeRepository::update($episodeId, ['status' => EpisodeStatus::AWAITING_AUDIO]);

            EpisodeRepository::log($episodeId, 'podlove', sprintf(
                /* translators: 1: post ID, 2: file name, 3: file size, 4: transcript note, 5: list of linked assets */
                __('Draft #%1$d created. File %2$s (%3$s)%4$s. Linked: %5$s.', 'sonoquill'),
                $postId,
                basename($audioPath),
                size_format(strlen($audio)),
                $transcriptWritten ? __(', transcript included', 'sonoquill') : __(', without transcript', 'sonoquill'),
                $attached === [] ? __('nothing', 'sonoquill') : implode(', ', $attached)
            ) . ' ' . __('Taken directly from the montage, without Auphonic.', 'sonoquill'));

            \Sonoquill\Notify\Notifier::audioReady($episodeId);
        } catch (\Throwable $e) {
            EpisodeRepository::log($episodeId, 'podlove', __('Failed: ', 'sonoquill') . $e->getMessage());
        }
    }

    /**
     * The finished MP3 from Auphonic's response — with size and checksum.
     *
     * @param array<string,mixed> $details
     *
     * @return array{url:string,checksum:string,size:int}|null
     */
    private static function audioFile(array $details): ?array
    {
        foreach ((array) ($details['output_files'] ?? []) as $file) {
            if (!is_array($file)) {
                continue;
            }

            $url = (string) ($file['download_url'] ?? '');
            $ending = strtolower((string) ($file['ending'] ?? ''));

            if ($url === '' || ($ending !== 'mp3' && !str_ends_with(strtolower($url), '.mp3'))) {
                continue;
            }

            return [
                'url'      => $url,
                'checksum' => strtolower((string) ($file['checksum'] ?? '')),
                'size'     => (int) ($file['size'] ?? 0),
            ];
        }

        return null;
    }

    /**
     * Adopts the chapter times Auphonic wrote into the finished file.
     *
     * Without ffmpeg Auphonic adds opener and bridges, so the exact positions
     * are only known afterwards. The file's own chapter marks (ID3 CHAP) are
     * the reference — the API reports the chapters unshifted, as they were
     * sent. The prediction is usually within a few tens of milliseconds;
     * chapters and transcript still take the exact values.
     *
     * @param array<string,mixed> $episode
     */
    private static function reconcileTimeline(int $episodeId, array $episode, string $audio, int $introShiftMs): void
    {
        $ours = EpisodeRepository::decodeList($episode['chapters'] ?? null);
        $embedded = \Sonoquill\Audio\Id3Chapters::parse($audio);

        if ($ours === [] || count($embedded) !== count($ours)) {
            EpisodeRepository::log($episodeId, 'auphonic', sprintf(
                /* translators: 1: chapters predicted, 2: chapter marks found in the file */
                __('Timeline check skipped: %1$d chapters predicted, %2$d chapter marks in the finished file.', 'sonoquill'),
                count($ours),
                count($embedded)
            ));

            return;
        }

        // Chapter 1 starts with the opener at 0; its speech starts where the
        // file's first chapter mark is. The others are compared directly.
        $starts = [];
        $deltas = [];
        foreach ($ours as $i => $chapter) {
            $predicted = $i === 0 ? $introShiftMs : (int) ($chapter['start_ms'] ?? 0);
            $starts[$i] = $predicted;
            $deltas[$i] = $embedded[$i]['start_ms'] - $predicted;
        }
        $worst = max(array_map('abs', $deltas));

        foreach ($ours as $i => &$chapter) {
            if ($i > 0) {
                $chapter['start_ms'] = $embedded[$i]['start_ms'];
            }
        }
        unset($chapter);
        EpisodeRepository::update($episodeId, ['chapters' => (string) wp_json_encode($ours)]);

        $vtt = EpisodeStorage::mixRelativePath($episodeId, 'vtt');
        if ($worst > 0 && EpisodeStorage::exists($vtt)) {
            EpisodeStorage::write($vtt, \Sonoquill\Audio\Transcript::shiftByChapters(
                (string) file_get_contents(EpisodeStorage::absolutePath($vtt)),
                $starts,
                $deltas
            ));
        }

        EpisodeRepository::log($episodeId, 'auphonic', sprintf(
            /* translators: %d: milliseconds */
            __('Timeline check: chapters and transcript follow the chapter marks in the finished file (prediction was off by up to %d ms).', 'sonoquill'),
            $worst
        ));
    }
}
