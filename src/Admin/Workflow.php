<?php
declare(strict_types=1);

namespace Sonoquill\Admin;

use Sonoquill\Db\EpisodeStatus;
use Sonoquill\Segments\SegmentRepository;
use Sonoquill\Storage\EpisodeStorage;

/**
 * The stations of an episode, derived from the data — not from the
 * episode status alone: an episode can be in "text_freigegeben" and still
 * have been mixed long ago.
 *
 * The first station that is not finished is "dran" (up next). What comes
 * after it is called "automatisch" (automatic) if the episode carries on by
 * itself and the station is not a human approval, "deine Freigabe" (your
 * approval) for the two gates, and "offen" (open) otherwise. This way the bar
 * shows where you yourself are needed.
 *
 * Both the overview and the episode view read from this.
 */
final class Workflow
{
    public const QUELLEN = 'quellen';
    public const SKRIPT = 'skript';
    public const TEXTFREIGABE = 'textfreigabe';
    public const AUDIO = 'audio';
    public const AUPHONIC = 'auphonic';
    public const PODLOVE = 'podlove';
    public const AUDIOFREIGABE = 'audiofreigabe';

    /** Stations at which a human decides. */
    private const GATES = [self::TEXTFREIGABE, self::AUDIOFREIGABE];

    /**
     * @param array<string,mixed> $episode
     *
     * @return list<array{key:string,titel:string,zustand:string,wort:string}>
     */
    public static function stations(array $episode): array
    {
        $id = (int) $episode['id'];
        $status = (string) $episode['status'];
        $summary = SegmentRepository::summary($id);
        $mix = (string) ($episode['mixed_audio_path'] ?? '');
        $auto = (int) ($episode['auto_chain'] ?? 0) === 1;

        $afterApproval = in_array($status, [
            EpisodeStatus::TEXT_APPROVED,
            EpisodeStatus::PRODUCING,
            EpisodeStatus::AWAITING_AUDIO,
            EpisodeStatus::DONE,
        ], true);

        $raw = [];
        if (\Sonoquill\Source\Sources::forEpisode($episode)->preparesText()) {
            $raw[self::QUELLEN] = [__('Sources', 'sonoquill'), trim((string) ($episode['source_text'] ?? '')) !== ''];
        }

        $raw[self::SKRIPT] = [__('Script', 'sonoquill'), trim((string) ($episode['script_text'] ?? '')) !== ''];
        $raw[self::TEXTFREIGABE] = [__('Text approval', 'sonoquill'), $afterApproval];
        $raw[self::AUDIO] = [__('Audio', 'sonoquill'), $mix !== '' && EpisodeStorage::exists($mix) && (int) $summary['offen'] === 0];
        if (!\Sonoquill\Settings\Options::directProduction()) {
            $raw[self::AUPHONIC] = [__('Auphonic', 'sonoquill'), (string) ($episode['auphonic_production_uuid'] ?? '') !== ''];
        }
        $raw[self::PODLOVE] = [__('Podlove', 'sonoquill'), in_array($status, [EpisodeStatus::AWAITING_AUDIO, EpisodeStatus::DONE], true)];
        $raw[self::AUDIOFREIGABE] = [__('Audio approval', 'sonoquill'), $status === EpisodeStatus::DONE];

        $stations = [];
        $current = false;

        foreach ($raw as $key => [$title, $done]) {
            if ($done) {
                $state = 'fertig';
            } elseif (!$current) {
                $state = 'dran';
                $current = true;
            } elseif (in_array($key, self::GATES, true)) {
                $state = 'freigabe';
            } elseif ($auto) {
                $state = 'automatisch';
            } else {
                $state = 'offen';
            }

            $stations[] = [
                'key'     => $key,
                'titel'   => $title,
                'zustand' => $state,
                'wort'    => self::word($state, self::needsHuman($episode, $key)),
            ];
        }

        return $stations;
    }

    /**
     * The station that is currently up next. null if everything is done.
     *
     * @param list<array{key:string,titel:string,zustand:string,wort:string}> $stations
     *
     * @return array{key:string,titel:string,zustand:string,wort:string,nummer:int,gesamt:int}|null
     */
    public static function current(array $stations): ?array
    {
        foreach ($stations as $index => $station) {
            if ($station['zustand'] === 'dran') {
                return $station + ['nummer' => $index + 1, 'gesamt' => count($stations)];
            }
        }

        return null;
    }

    /**
     * Is this station waiting for a human, or does it run by itself?
     *
     * @param array<string,mixed> $episode
     */
    public static function needsHuman(array $episode, string $key): bool
    {
        if (in_array($key, self::GATES, true)) {
            return true;
        }

        $status = (string) $episode['status'];
        $auto = (int) ($episode['auto_chain'] ?? 0) === 1;

        return match ($key) {
            self::QUELLEN => $status === EpisodeStatus::SOURCE_FAILED,
            self::SKRIPT  => !in_array($status, [EpisodeStatus::REDIGAT_RUNNING, EpisodeStatus::SOURCE_RUNNING], true)
                && !($auto && $status === EpisodeStatus::PARSED),
            self::AUDIO, self::AUPHONIC => !$auto,
            default => false,
        };
    }

    private static function word(string $state, bool $human): string
    {
        return match ($state) {
            'fertig'      => __('done', 'sonoquill'),
            'dran'        => $human ? __('your turn', 'sonoquill') : __('running', 'sonoquill'),
            'freigabe'    => __('your approval', 'sonoquill'),
            'automatisch' => __('automatic', 'sonoquill'),
            default       => __('open', 'sonoquill'),
        };
    }
}
