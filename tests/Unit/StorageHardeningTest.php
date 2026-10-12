<?php
declare(strict_types=1);

namespace Sonoquill\Tests\Unit;

use Sonoquill\Audio\MusicBed;
use Sonoquill\Health\Checks\PodloveCheck;
use Sonoquill\Health\Checks\StorageCheck;
use Sonoquill\Health\Result;
use Sonoquill\Storage\EpisodeStorage;
use Sonoquill\Tests\Wp;
use Sonoquill\Tests\WpTestCase;

/**
 * Protection files, the reachability probe and the Podlove feed-protection warning.
 */
final class StorageHardeningTest extends WpTestCase
{
    /** Answers HEAD requests: 200 for $served URLs (substring), 404 for everything else. */
    private function server(string ...$served): void
    {
        Wp::$http = static function (string $url) use ($served): array {
            foreach ($served as $part) {
                if (str_contains($url, $part) && !str_contains($url, '/missing-')) {
                    return ['response' => ['code' => 200], 'body' => ''];
                }
            }

            return ['response' => ['code' => 404], 'body' => ''];
        };
    }

    public function testStorageDirGetsProtectionFiles(): void
    {
        $base = EpisodeStorage::ensureBaseDir();

        self::assertFileExists($base . '/index.html');
        self::assertFileExists($base . '/index.php');
        self::assertStringContainsString('Require all denied', (string) file_get_contents($base . '/.htaccess'));
    }

    public function testMusicDirOnlyBlocksListings(): void
    {
        self::assertTrue(MusicBed::ensureDir());

        self::assertFileExists(MusicBed::dir() . '/index.html');
        self::assertSame("Options -Indexes\n", file_get_contents(MusicBed::dir() . '/.htaccess'));
    }

    public function testReachableStorageWarnsWithServerSnippets(): void
    {
        $this->server('/sonoquill/data-');

        $result = (new StorageCheck())->run();

        self::assertSame(Result::WARN, $result->status);
        self::assertStringContainsString('location ^~ /wp-content/uploads/sonoquill/data-', $result->detail);
        self::assertStringContainsString('Require all denied', $result->detail);
        self::assertSame('HEAD', Wp::$requests[0][1]['method']);
    }

    public function testBlockedStorageIsOk(): void
    {
        $this->server();

        self::assertSame(Result::OK, (new StorageCheck())->run()->status);
    }

    public function testCatchAllServerIsInconclusive(): void
    {
        Wp::$http = static fn (): array => ['response' => ['code' => 200], 'body' => ''];

        self::assertNull(StorageCheck::probe(EpisodeStorage::ensureBaseDir(), (string) EpisodeStorage::url()));
    }

    public function testReachableMusicIsMentioned(): void
    {
        MusicBed::ensureDir();
        $this->server('/sonoquill/music/');

        $result = (new StorageCheck())->run();

        self::assertSame(Result::OK, $result->status, 'Public music alone is by design.');
        self::assertStringContainsString('location ^~ /wp-content/uploads/sonoquill/music/', $result->detail);
    }

    public function testProtectedFeedWithoutDownloadFilterWarns(): void
    {
        $warning = PodloveCheck::feedProtectionWarning(['Members'], false);

        self::assertNotNull($warning);
        self::assertSame(Result::WARN, $warning->status);
        self::assertStringContainsString('download_media_file', $warning->detail);
        self::assertStringContainsString('podlove_pre_media_file_download', $warning->detail);

        self::assertNull(PodloveCheck::feedProtectionWarning(['Members'], true));
        self::assertNull(PodloveCheck::feedProtectionWarning([], false));
    }
}
