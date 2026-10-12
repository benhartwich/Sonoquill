<?php
declare(strict_types=1);

namespace Sonoquill\Pipeline;

use Sonoquill\Auphonic\AuphonicClient;
use Sonoquill\Auphonic\ChapterFormat;
use Sonoquill\Db\EpisodeRepository;
use Sonoquill\Rest\AuphonicWebhook;
use Sonoquill\Storage\EpisodeStorage;

/**
 * Step 10: the assembled raw mix is sent to Auphonic.
 *
 * Chapter marks and metadata travel along with it. Speech recognition must be
 * switched off in the preset — chapters and transcript come from the assembly
 * step and are exact; the ASR variant costs credits and is worse.
 */
final class Production
{
    public static function run(int $episodeId): void
    {
        $episode = EpisodeRepository::find($episodeId);
        if ($episode === null) {
            return;
        }

        $mix = (string) ($episode['mixed_audio_path'] ?? '');
        if ($mix === '' || !EpisodeStorage::exists($mix)) {
            EpisodeRepository::log($episodeId, 'auphonic', __('Aborted: there is no assembled mix.', 'sonoquill'));

            return;
        }

        $title = trim((string) ($episode['episode_title'] ?? ''));
        if ($title === '') {
            EpisodeRepository::log($episodeId, 'auphonic', __('Aborted: the episode has no title.', 'sonoquill'));

            return;
        }

        $slug = (string) ($episode['podlove_slug'] ?? '');
        if ($slug === '') {
            $slug = \Sonoquill\Podlove\EpisodeDraft::slugFor($episode);
            EpisodeRepository::update($episodeId, ['podlove_slug' => $slug]);
        }

        $keywords = EpisodeRepository::decodeList($episode['keywords'] ?? null);

        $metadata = [
            'title'           => $title,
            'subtitle'        => (string) ($episode['description_short'] ?? ''),
            'summary'         => (string) ($episode['description_long'] ?? ''),
            'tags'            => implode(', ', array_map('strval', $keywords)),
            'output_basename' => $slug,
        ];
        $montage = EpisodeRepository::decodeMap($episode['montage_json'] ?? null);
        $withMusic = ($montage['mode'] ?? '') === 'php'
            && (!empty($montage['intro']) || !empty($montage['outro']) || !empty($montage['inserts']));

        try {
            $client = AuphonicClient::fromSettings();
            $uuid = $withMusic
                // Assembly without ffmpeg: Auphonic adds opener, bridges and outro.
                ? $client->startWithMusic(
                    EpisodeStorage::absolutePath($mix),
                    $slug . '.mp3',
                    $metadata,
                    (array) ($montage['chapters_speech'] ?? []),
                    AuphonicWebhook::url(),
                    [
                        'intro'   => $montage['intro'] ?? null,
                        'outro'   => $montage['outro'] ?? null,
                        'inserts' => (array) ($montage['inserts'] ?? []),
                    ]
                )
                : $client->start(
                    EpisodeStorage::absolutePath($mix),
                    $slug . '.mp3',
                    $metadata,
                    ChapterFormat::toText(EpisodeRepository::decodeList($episode['chapters'] ?? null)),
                    AuphonicWebhook::url()
                );

            EpisodeRepository::update($episodeId, [
                'auphonic_production_uuid' => $uuid,
                'status'                   => \Sonoquill\Db\EpisodeStatus::PRODUCING,
            ]);

            EpisodeRepository::log($episodeId, 'auphonic', sprintf(
                /* translators: 1: Auphonic production UUID, 2: uploaded file size, 3: webhook callback URL */
                __('Production %1$s started, %2$s uploaded. The callback will arrive at %3$s.', 'sonoquill'),
                $uuid,
                size_format(EpisodeStorage::size($mix)),
                AuphonicWebhook::url()
            ));
        } catch (\Throwable $e) {
            EpisodeRepository::log($episodeId, 'auphonic', __('Failed: ', 'sonoquill') . $e->getMessage());
        }
    }
}
