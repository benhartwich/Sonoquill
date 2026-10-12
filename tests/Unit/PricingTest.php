<?php
declare(strict_types=1);

namespace Sonoquill\Tests\Unit;

use Sonoquill\Ai\AnthropicResponse;
use Sonoquill\Ai\Pricing;
use PHPUnit\Framework\TestCase;

/**
 * Kostenschätzung: bis 25.09.2026 pauschal mit Opus-5-Preisen und als Euro
 * angezeigt. Jetzt nach Modell, Cache und Batch, in US-Cent.
 */
final class PricingTest extends TestCase
{
    public function testOpusFiveMatchesTheOldFlatRate(): void
    {
        // Redigat der Folge Oktober 2026: 16964 ein, 20663 aus, 60,14 Cent.
        self::assertSame(60.14, Pricing::cents('claude-opus-5', 16964, 20663));
    }

    public function testOpusFiveFiveIsTwentyPercentCheaper(): void
    {
        self::assertSame(48.11, Pricing::cents('claude-opus-5-5', 16964, 20663));
    }

    public function testLongestPrefixWins(): void
    {
        self::assertSame([4.0, 20.0], Pricing::rates('claude-opus-5-5'));
        self::assertSame([5.0, 25.0], Pricing::rates('claude-opus-5'));
        self::assertSame([2.0, 10.0], Pricing::rates('claude-sonnet-5'));
    }

    public function testBatchHalvesAndCacheIsWeighted(): void
    {
        // 1 Mio. Eingabe als Cache-Lesen kostet ein Zehntel, im Batch die Hälfte davon.
        self::assertSame(20.0, Pricing::cents('claude-opus-5-5', 0, 0, 0, 1000000, true));
        self::assertSame(500.0, Pricing::cents('claude-opus-5-5', 0, 0, 1000000, 0, false));
    }

    public function testBatchAnswerIsBookedOnce(): void
    {
        $response = new AnthropicResponse('x', 1000000, 0, 'end_turn', 'claude-sonnet-5', 0, 0, true);

        self::assertSame(100.0, $response->centsToBook());
        self::assertSame(0.0, $response->markBooked()->centsToBook());
        self::assertSame(100.0, $response->markBooked()->estimatedCents());
    }

    public function testCurrentModelsHaveTheirOwnRates(): void
    {
        self::assertSame([2.0, 10.0], Pricing::rates('claude-sonnet-5-5'));
        self::assertSame([0.1, 0.5], Pricing::rates('claude-haiku-5-5'));
        self::assertSame([10.0, 50.0], Pricing::rates('claude-fable-5-1'));
        self::assertTrue(Pricing::isKnown('claude-haiku-5-5'));
    }

    public function testUnknownModelUsesTheMostExpensiveRateAndWarns(): void
    {
        Pricing::reset();
        $previous = ini_set('error_log', '/dev/null');
        try {
            self::assertFalse(Pricing::isKnown('gpt-something'));
            self::assertSame([10.0, 50.0], Pricing::rates('gpt-something'));
            self::assertSame(['gpt-something'], Pricing::warnings());
        } finally {
            ini_set('error_log', (string) $previous);
        }
    }

    public function testRatesCanBeFiltered(): void
    {
        \Sonoquill\Tests\Wp::reset();
        add_filter('sonoquill_model_rates', static fn (array $rates): array => ['claude-opus-6' => [3.0, 15.0]] + $rates);

        self::assertSame([3.0, 15.0], Pricing::rates('claude-opus-6'));
        self::assertSame([4.0, 20.0], Pricing::rates('claude-opus-5-5'));

        \Sonoquill\Tests\Wp::reset();
    }
}
