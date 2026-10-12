<?php
declare(strict_types=1);

namespace Sonoquill\Tests\Unit;

use Sonoquill\Audio\MusicBed;
use Sonoquill\Tests\WpTestCase;

/**
 * Opener and outro variants, chosen per episode.
 */
final class MusicRotationTest extends WpTestCase
{
    private function upload(string ...$slots): void
    {
        MusicBed::ensureDir();
        foreach ($slots as $slot) {
            file_put_contents(MusicBed::file($slot), $slot);
        }
    }

    public function testPickStrategies(): void
    {
        $files = ['a', 'b', 'c'];

        self::assertSame('a', MusicBed::pick($files, 'fixed', 5));
        self::assertSame(['a', 'b', 'c', 'a'], array_map(static fn (int $id): ?string => MusicBed::pick($files, 'rotate', $id), [1, 2, 3, 4]));
        self::assertSame(MusicBed::pick($files, 'random', 17, 'opener'), MusicBed::pick($files, 'random', 17, 'opener'), 'Deterministic per episode.');
        self::assertNull(MusicBed::pick([], 'rotate', 1));
    }

    public function testRandomSpreadsOverAllFiles(): void
    {
        $seen = [];
        for ($id = 1; $id <= 60; $id++) {
            $seen[MusicBed::pick(['a', 'b', 'c'], 'random', $id, 'opener')] = true;
        }

        self::assertCount(3, $seen);
    }

    public function testFixedKeepsThePlainSlot(): void
    {
        $this->settings(['music_enabled' => true]);
        $this->upload('opener', 'opener-1', 'opener-2', 'outro');

        $music = MusicBed::active(2);

        self::assertSame(MusicBed::file('opener'), $music['opener']);
        self::assertSame(MusicBed::file('outro'), $music['outro']);
    }

    public function testRotateUsesTheVariants(): void
    {
        $this->settings(['music_enabled' => true, 'music_rotation' => 'rotate']);
        $this->upload('opener', 'opener-1', 'opener-3', 'outro-2');

        self::assertSame(MusicBed::file('opener'), MusicBed::active(1)['opener']);
        self::assertSame(MusicBed::file('opener-1'), MusicBed::active(2)['opener']);
        self::assertSame(MusicBed::file('opener-3'), MusicBed::active(3)['opener']);
        self::assertSame(MusicBed::file('outro-2'), MusicBed::active(3)['outro'], 'Only a variant uploaded: it is used.');
    }

    public function testFilterChoosesPerEpisode(): void
    {
        $this->settings(['music_enabled' => true]);
        $this->upload('opener', 'opener-4');
        add_filter('sonoquill_music_for_episode', static function (array $music, int $id): array {
            $music['opener'] = $id === 9 ? MusicBed::file('opener-4') : $music['opener'];
            $music['outro'] = '/does/not/exist.mp3';

            return $music;
        }, 10, 2);

        self::assertSame(MusicBed::file('opener-4'), MusicBed::active(9)['opener']);
        self::assertNull(MusicBed::active(9)['outro'], 'Unreadable files are dropped.');
        self::assertSame(MusicBed::file('opener'), MusicBed::active(8)['opener']);
    }

    public function testMusicOffStaysOff(): void
    {
        $this->upload('opener');

        self::assertSame(['opener' => null, 'outro' => null, 'trenner' => []], MusicBed::active(1));
    }
}
