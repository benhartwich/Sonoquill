<?php
declare(strict_types=1);

namespace Sonoquill\Tests\Unit;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Sonoquill\Ai\AnthropicClient;
use Sonoquill\Ai\BatchGate;
use Sonoquill\Source\AbstractSource;
use Sonoquill\Source\Sources;
use Sonoquill\Support\Crypto;
use Sonoquill\Support\KeyStore;
use Sonoquill\Tests\Wp;
use Sonoquill\Tests\WpTestCase;

/**
 * Batch API per source and per call.
 */
final class BatchPreferenceTest extends WpTestCase
{
    private function registerSource(?bool $batch): void
    {
        $source = new class extends AbstractSource {
            public ?bool $batch = null;
            public function id(): string { return 'taeglich'; }
            public function label(): string { return 'Daily'; }
            public function description(): string { return ''; }
            public function renderStartForm(): void {}
            public function prefersBatch(array $episode): ?bool { return $this->batch; }
        };
        $source->batch = $batch;
        Sources::register($source);
    }

    public function testSettingAppliesWithoutPreference(): void
    {
        $this->registerSource(null);
        $id = $this->episode(['source_type' => 'taeglich']);

        $this->settings(['anthropic_batch' => true]);
        self::assertTrue(BatchGate::useBatch($id, 'redigat'));
        $this->settings(['anthropic_batch' => false]);
        self::assertFalse(BatchGate::useBatch($id, 'redigat'));
    }

    public function testSourcePreferenceOverridesTheSetting(): void
    {
        $this->settings(['anthropic_batch' => true]);
        $this->registerSource(false);
        $id = $this->episode(['source_type' => 'taeglich']);

        self::assertFalse(BatchGate::useBatch($id, 'redigat'));
    }

    public function testFilterHasTheLastWord(): void
    {
        $this->settings(['anthropic_batch' => true]);
        $id = $this->episode();
        add_filter('sonoquill_use_batch', static fn (bool $use, int $episodeId, string $step): bool => $step !== 'metadaten' && $use, 10, 3);

        self::assertTrue(BatchGate::useBatch($id, 'redigat'));
        self::assertFalse(BatchGate::useBatch($id, 'metadaten'));
    }

    #[RunInSeparateProcess]
    public function testCompleteCallsDirectlyWhenTheSourceSaysSo(): void
    {
        define(KeyStore::CONSTANT, Crypto::generateKeyBase64());
        $this->settings(['anthropic_batch' => true, 'anthropic_api_key' => KeyStore::crypto()->encrypt('sk-test')]);
        $this->registerSource(false);
        $id = $this->episode(['source_type' => 'taeglich']);
        Wp::$http = static fn (): array => ['response' => ['code' => 200], 'body' => (string) json_encode([
            'content'     => [['type' => 'text', 'text' => 'Antwort']],
            'stop_reason' => 'end_turn',
            'usage'       => ['input_tokens' => 10, 'output_tokens' => 5],
        ])];

        $response = BatchGate::complete(AnthropicClient::fromSettings('redigat'), $id, 'redigat', 'hook', 'system', 'user');

        self::assertSame('Antwort', $response->text);
        self::assertSame(AnthropicClient::ENDPOINT, Wp::$requests[0][0], 'No batch was submitted.');
        self::assertSame([], Wp::$scheduled);
    }
}
