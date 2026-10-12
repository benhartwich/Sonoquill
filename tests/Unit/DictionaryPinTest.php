<?php
declare(strict_types=1);

namespace Sonoquill\Tests\Unit;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Sonoquill\Db\EpisodeRepository;
use Sonoquill\Db\EpisodeStatus;
use Sonoquill\Pipeline\Approval;
use Sonoquill\Settings\Options;
use Sonoquill\Support\Crypto;
use Sonoquill\Support\KeyStore;
use Sonoquill\Tests\Wp;
use Sonoquill\Tests\WpTestCase;
use Sonoquill\Voice\DictionaryPin;

/**
 * A dictionary without a pinned version blocks the approval instead of
 * failing synthesis afterwards; the latest version can be pinned.
 */
final class DictionaryPinTest extends WpTestCase
{
    public function testNoDictionaryNoProblem(): void
    {
        self::assertFalse(DictionaryPin::isMissing());
        self::assertSame('', DictionaryPin::problem());
    }

    public function testDictionaryWithoutVersionIsAProblem(): void
    {
        $this->settings(['elevenlabs_dictionary_id' => 'dict1']);
        self::assertTrue(DictionaryPin::isMissing());
        self::assertStringContainsString('without a version', DictionaryPin::problem());

        $this->settings(['elevenlabs_dictionary_version_id' => 'v7']);
        self::assertSame('', DictionaryPin::problem());
    }

    public function testApprovalIsBlockedWithAClearMessage(): void
    {
        $this->settings(['elevenlabs_dictionary_id' => 'dict1', 'podcast_language' => 'de']);
        $id = $this->episode([
            'status'      => EpisodeStatus::AWAITING_TEXT,
            'source_text' => 'Text.',
            'script_text' => 'Text.',
        ]);

        self::assertFalse(Approval::approveText($id));
        self::assertStringContainsString('Pin latest version', Approval::lastError());
        self::assertSame(EpisodeStatus::AWAITING_TEXT, EpisodeRepository::find($id)['status']);
    }

    public function testResolvingNeedsAKey(): void
    {
        $this->settings(['elevenlabs_dictionary_id' => 'dict1']);

        $this->expectException(\RuntimeException::class);
        DictionaryPin::latestVersionId();
    }

    #[RunInSeparateProcess]
    public function testPinLatestStoresTheReportedVersion(): void
    {
        define(KeyStore::CONSTANT, Crypto::generateKeyBase64());
        $this->settings([
            'elevenlabs_api_key'       => KeyStore::crypto()->encrypt('xi-test'),
            'elevenlabs_dictionary_id' => 'dict1',
        ]);
        Wp::$http = static fn (string $url, array $args): array => [
            'response' => ['code' => 200],
            'body'     => (string) json_encode(['id' => 'dict1', 'latest_version_id' => 'ver42']),
        ];

        self::assertSame('ver42', DictionaryPin::pinLatest());
        self::assertSame('ver42', Options::get('elevenlabs_dictionary_version_id'));
        self::assertStringEndsWith('/pronunciation-dictionaries/dict1', Wp::$requests[0][0]);
        self::assertSame('xi-test', Wp::$requests[0][1]['headers']['xi-api-key']);
    }
}
