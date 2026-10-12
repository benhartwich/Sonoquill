<?php
declare(strict_types=1);

namespace Sonoquill\Tests\Unit;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Sonoquill\Ai\AnthropicClient;
use Sonoquill\Support\Crypto;
use Sonoquill\Support\KeyStore;
use Sonoquill\Tests\WpTestCase;

/**
 * One model per pipeline step: settings, filter, fromSettings(step), withModel().
 */
final class ModelPerStepTest extends WpTestCase
{
    public function testEmptyStepSettingMeansMainModel(): void
    {
        $this->settings(['anthropic_model' => 'claude-opus-5-5']);

        self::assertSame('claude-opus-5-5', AnthropicClient::modelForStep('faktenpruefung'));
        self::assertSame('claude-opus-5-5', AnthropicClient::modelForStep('redigat'));
        self::assertSame('claude-opus-5-5', AnthropicClient::modelForStep(null));
    }

    public function testStepSettingsWin(): void
    {
        $this->settings([
            'anthropic_model'            => 'claude-opus-5-5',
            'anthropic_model_factcheck'  => 'claude-fable-5-1',
            'anthropic_model_metadata'   => 'claude-haiku-5-5',
            'anthropic_model_dictionary' => 'claude-sonnet-5-5',
        ]);

        self::assertSame('claude-fable-5-1', AnthropicClient::modelForStep('faktenpruefung'));
        self::assertSame('claude-haiku-5-5', AnthropicClient::modelForStep('metadaten'));
        self::assertSame('claude-sonnet-5-5', AnthropicClient::modelForStep('woerterbuch'));
        self::assertSame('claude-sonnet-5', AnthropicClient::modelForStep('research'), 'Existing research setting, default value.');
    }

    public function testFilterGetsTheLastWord(): void
    {
        $this->settings(['anthropic_model' => 'claude-opus-5-5']);
        add_filter('sonoquill_model_for_step', static fn (string $model, string $step): string => $step === 'metadaten' ? 'claude-haiku-5-5' : $model, 10, 2);

        self::assertSame('claude-haiku-5-5', AnthropicClient::modelForStep('metadaten'));
        self::assertSame('claude-opus-5-5', AnthropicClient::modelForStep('redigat'));
    }

    #[RunInSeparateProcess]
    public function testFromSettingsBuildsTheClientForTheStep(): void
    {
        define(KeyStore::CONSTANT, Crypto::generateKeyBase64());
        $this->settings([
            'anthropic_api_key'         => KeyStore::crypto()->encrypt('sk-test'),
            'anthropic_model'           => 'claude-opus-5-5',
            'anthropic_model_factcheck' => 'claude-sonnet-5-5',
        ]);

        self::assertSame('claude-opus-5-5', AnthropicClient::fromSettings()->model());
        $client = AnthropicClient::fromSettings('faktenpruefung');
        self::assertSame('claude-sonnet-5-5', $client->model());
        self::assertSame('claude-sonnet-5-5', $client->payload('s', 'u')['model']);

        $other = $client->withModel('claude-haiku-5-5');
        self::assertSame('claude-haiku-5-5', $other->payload('s', 'u')['model']);
        self::assertSame('claude-sonnet-5-5', $client->model(), 'withModel() returns a copy.');
    }
}
