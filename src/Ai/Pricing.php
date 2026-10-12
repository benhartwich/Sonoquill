<?php
declare(strict_types=1);

namespace Sonoquill\Ai;

/**
 * List prices of the Anthropic API in US dollars per million tokens.
 *
 * A rough estimate, not billing — usage is counted per run so that an
 * accidentally repeated run stands out. Until 25.09.2026 the plugin
 * applied Opus 5 prices across the board and displayed the result in euros;
 * both were wrong.
 *
 * Surcharges and discounts as published: cache writes 1.25x, cache reads
 * 0.1x, batch 50 % off everything.
 */
final class Pricing
{
    /**
     * Model prefix => [input, output] in US dollars per million tokens, as
     * published on 06.10.2026. Haiku 5.5: the price for prompts up to 100K
     * tokens (longer ones cost five times as much).
     *
     * @var array<string,array{0:float,1:float}>
     */
    private const RATES = [
        'claude-fable-5-1'  => [10.0, 50.0],
        'claude-fable-5'    => [10.0, 50.0],
        'claude-opus-5-5'   => [4.0, 20.0],
        'claude-opus-5'     => [5.0, 25.0],
        'claude-sonnet-5-5' => [2.0, 10.0],
        'claude-sonnet-5'   => [2.0, 10.0],
        'claude-haiku-5-5'  => [0.1, 0.5],
        'claude-haiku-4-5'  => [1.0, 5.0],
    ];

    /** @var array<string,true> Unknown models already warned about in this request. */
    private static array $warned = [];

    public static function cents(string $model, int $input, int $output, int $cacheWrite = 0, int $cacheRead = 0, bool $batch = false): float
    {
        [$in, $out] = self::rates($model);

        $usd = ($input * $in + $cacheWrite * $in * 1.25 + $cacheRead * $in * 0.1 + $output * $out) / 1000000;
        if ($batch) {
            $usd *= 0.5;
        }

        return round($usd * 100, 2);
    }

    /**
     * The price table, extended or corrected by the filter
     * `sonoquill_model_rates` (model prefix => [input, output] per million tokens).
     *
     * @return array<string,array{0:float,1:float}>
     */
    public static function table(): array
    {
        $table = function_exists('apply_filters') ? apply_filters('sonoquill_model_rates', self::RATES) : self::RATES;

        $clean = [];
        foreach ((array) $table as $prefix => $rates) {
            if (is_string($prefix) && $prefix !== '' && is_array($rates) && count($rates) >= 2) {
                $rates = array_values($rates);
                $clean[$prefix] = [(float) $rates[0], (float) $rates[1]];
            }
        }

        return $clean !== [] ? $clean : self::RATES;
    }

    public static function isKnown(string $model): bool
    {
        return self::match($model, self::table()) !== null;
    }

    /**
     * @return array{0:float,1:float}
     */
    public static function rates(string $model): array
    {
        $table = self::table();
        $best = self::match($model, $table);
        if ($best !== null) {
            return $table[$best];
        }

        // Unknown model: rather overestimate than underestimate the costs —
        // the most expensive known rate, with a warning so that the table gets updated.
        if (!isset(self::$warned[$model])) {
            self::$warned[$model] = true;
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- A warning for the site's error log, not debug output.
            error_log(sprintf('Sonoquill: no price known for model "%s"; estimating with the most expensive known rate. Add it via the filter sonoquill_model_rates.', $model));
        }

        $max = null;
        foreach ($table as $rates) {
            if ($max === null || $rates[1] > $max[1] || ($rates[1] === $max[1] && $rates[0] > $max[0])) {
                $max = $rates;
            }
        }

        return $max ?? [10.0, 50.0];
    }

    /**
     * Unknown models warned about in this request. For tests.
     *
     * @return list<string>
     */
    public static function warnings(): array
    {
        return array_keys(self::$warned);
    }

    /** For tests only. */
    public static function reset(): void
    {
        self::$warned = [];
    }

    /**
     * Longest matching prefix: "claude-opus-5-5" before "claude-opus-5".
     *
     * @param array<string,array{0:float,1:float}> $table
     */
    private static function match(string $model, array $table): ?string
    {
        $best = null;
        foreach (array_keys($table) as $prefix) {
            if (str_starts_with($model, $prefix) && ($best === null || strlen($prefix) > strlen($best))) {
                $best = $prefix;
            }
        }

        return $best;
    }
}
