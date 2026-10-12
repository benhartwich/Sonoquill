<?php
declare(strict_types=1);

namespace Sonoquill\Audio;

/**
 * Opener and outro, attached automatically by the assembly step.
 *
 * Putting jingles into the Auphonic preset would be the obvious option, but it
 * would come at a price: the
 * assembly step calculates chapters and transcript down to the millisecond, and an
 * intro that Auphonic only prepends later would shift the transcript by its length.
 * That is why the assembly step mixes the music itself and shifts chapters and
 * transcript along with it.
 *
 * The sequence: the opener plays — an audio logo with a spoken line —,
 * and while it fades out over three seconds, the voice comes in. Shortly
 * after the last word the outro begins. With music the episode becomes stereo — the voice sits
 * in the centre, the music keeps its width.
 *
 * Between chapters there is, if uploaded, a short musical bridge
 * ("separator") — up to five different ones that rotate in order, so that
 * not every chapter change sounds the same. Done like in a radio feature: the
 * bridge starts shortly after the last word, and the next chapter
 * already begins while it fades out under the voice. Only the
 * chapter pause gets longer for this; chapter and transcript times therefore
 * stay correct on their own. Break tags in the text were not reliably heard by the voice as a cut
 * (the host, 26.09.2026).
 *
 * The files are stored publicly under uploads/ so that the settings page
 * can play them; there is nothing secret about them.
 */
final class MusicBed
{
    public const SLOTS = ['opener', 'outro'];

    /** Separator files; they rotate in this order. */
    public const SEPARATOR_SLOTS = ['trenner-1', 'trenner-2', 'trenner-3', 'trenner-4', 'trenner-5'];

    /**
     * @return list<string>
     */
    public static function allSlots(): array
    {
        return array_merge(self::SLOTS, self::SEPARATOR_SLOTS);
    }

    /** How long the opener fades out under the first sentence. */
    public const OVERLAP_MS = 3000;

    /** Silence between the last word and the outro. */
    public const GAP_MS = 600;

    /** Room tone between the last word of a chapter and the bridge. */
    public const SEPARATOR_BEFORE_MS = 400;

    /** How long before the end of the bridge the next chapter begins. */
    public const SEPARATOR_OVERLAP_MS = 1500;

    /** The assembly step fades the bridge out over this final stretch. */
    public const SEPARATOR_FADE_MS = 2500;

    /**
     * PHP path (Auphonic inserts the bridge, nothing overlaps): silence before
     * the bridge and after it, until the next chapter begins.
     */
    public const INSERT_BEFORE_MS = 400;
    public const INSERT_AFTER_MS = 300;

    /** Overlap of the opener with the first words when Auphonic mixes it. */
    public const AUPHONIC_INTRO_OVERLAP_MS = 3000;

    /**
     * Where a sound in the speech file (at $ms) ends up in the finished file
     * when Auphonic adds the intro and inserts: shifted by the intro minus its
     * overlap, and by every bridge inserted before it.
     *
     * @param list<array{at_ms:int,ms:int}> $inserts
     */
    public static function finalTime(int $ms, int $introShiftMs, array $inserts): int
    {
        $shift = $introShiftMs;
        foreach ($inserts as $insert) {
            if ($insert['at_ms'] <= $ms) {
                $shift += $insert['ms'];
            }
        }

        return $ms + $shift;
    }

    public static function dir(): string
    {
        return \Sonoquill\Storage\EpisodeStorage::uploads()['dir'] . '/music';
    }

    /**
     * Creates the music folder with index files and an .htaccess against
     * directory listings. The files themselves stay reachable, so that the
     * settings page can play them.
     */
    public static function ensureDir(): bool
    {
        $dir = self::dir();
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return false;
        }
        \Sonoquill\Storage\EpisodeStorage::protectDirectory($dir, false);

        return true;
    }

    public static function file(string $slot): string
    {
        return self::dir() . '/' . $slot . '.mp3';
    }

    public static function url(string $slot): string
    {
        $path = self::file($slot);

        return \Sonoquill\Storage\EpisodeStorage::uploads()['url'] . '/music/' . $slot . '.mp3' . (is_readable($path) ? '?v=' . filemtime($path) : '');
    }

    /**
     * The files that go into the next assembly.
     *
     * @return array{opener:?string,outro:?string,trenner:list<string>}
     */
    public static function active(): array
    {
        if (!\Sonoquill\Settings\Options::flag('music_enabled')) {
            return ['opener' => null, 'outro' => null, 'trenner' => []];
        }

        $out = [];
        foreach (self::SLOTS as $slot) {
            $out[$slot] = is_readable(self::file($slot)) ? self::file($slot) : null;
        }

        $out['trenner'] = [];
        foreach (self::SEPARATOR_SLOTS as $slot) {
            if (is_readable(self::file($slot))) {
                $out['trenner'][] = self::file($slot);
            }
        }

        return $out;
    }

    /**
     * Which separator is placed at the n-th chapter boundary (starting at 0).
     */
    public static function separatorFor(int $boundary, int $count): int
    {
        return $count > 0 ? $boundary % $count : -1;
    }

    /**
     * Length of the pause before a chapter start and where within it the separator begins.
     *
     * @return array{pause:int,start:int} start relative to the beginning of the pause, -1 without a separator.
     */
    public static function chapterGap(int $separatorMs, int $plainPauseMs): array
    {
        if ($separatorMs <= 0) {
            return ['pause' => $plainPauseMs, 'start' => -1];
        }

        return [
            // Never shorter than the silent chapter pause, even with a very short file.
            'pause' => max($plainPauseMs, self::SEPARATOR_BEFORE_MS + $separatorMs - self::SEPARATOR_OVERLAP_MS),
            'start' => self::SEPARATOR_BEFORE_MS,
        ];
    }

    /**
     * When each part begins, in milliseconds from the start of the file.
     *
     * @return array{speech:int,outro:int,total:int}
     */
    public static function plan(int $openerMs, int $speechMs, int $outroMs, int $overlapMs = self::OVERLAP_MS, int $gapMs = self::GAP_MS): array
    {
        $speech = $openerMs > 0 ? max(0, $openerMs - min($overlapMs, $openerMs)) : 0;
        $outro = $outroMs > 0 ? $speech + $speechMs + $gapMs : -1;
        $total = max($speech + $speechMs, $outro >= 0 ? $outro + $outroMs : 0, $openerMs);

        return ['speech' => $speech, 'outro' => $outro, 'total' => $total];
    }
}
