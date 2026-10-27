<?php
declare(strict_types=1);

namespace Sonoquill\Numbers;

/**
 * Extracts typed values from numbers written out as words.
 *
 * Runs on both sides, not just on the spoken script: the fact script mixes
 * digits and words ("Sechs Tage vorher, am 17., …"). If the left side were
 * read for digits only and the right side for words only, the comparison
 * would flag every such spot as an invented number.
 *
 * Special case "ein" and "eine": in German these cannot be told apart from
 * indefinite articles. "eine neue Ausgabe" is not a one. These two forms
 * therefore only count as a number when followed by "Uhr", "Komma" or a
 * scale word. "eins" is always a number.
 */
final class SpokenExtractor
{
    private const SCALES = [
        'tausend'    => 1000.0,
        'million'    => 1000000.0,
        'millionen'  => 1000000.0,
        'milliarde'  => 1000000000.0,
        'milliarden' => 1000000000.0,
    ];

    private const AMBIGUOUS = ['ein', 'eine', 'einen', 'einem', 'einer'];

    /**
     * @return list<NumberValue>
     */
    public static function extract(string $text): array
    {
        $tokens = self::tokenize($text);
        $months = NumberValue::monthLookup();
        $values = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $lower = GermanNumberParser::normalize($token);

            if ($lower === '') {
                continue;
            }

            if (isset($months[$lower])) {
                $values[] = NumberValue::month($months[$lower], $token);
                continue;
            }

            // "Millionen" on its own is not a number but a unit. Otherwise
            // the parser would read it as one million. "tausend" on its own
            // is a number, though: "über 1000" becomes "über tausend" in the
            // spoken script (sky preview November 2026).
            if (isset(self::SCALES[$lower]) && $lower !== 'tausend') {
                continue;
            }

            $negative = false;
            if ($lower === 'minus' && $i + 1 < $count) {
                $negative = true;
                $i++;
                $token = $tokens[$i];
                $lower = GermanNumberParser::normalize($token);
            }

            $cardinal = GermanNumberParser::cardinal($lower);
            $ordinal = $cardinal === null ? GermanNumberParser::ordinal($lower) : null;

            // Multiples: "hundertsechzigmal" stands for "160-mal". Only from
            // thirteen upward — "einmal", "zweimal" are colloquial ("noch
            // einmal") and would otherwise show up as invented numbers.
            if ($cardinal === null && $ordinal === null && str_ends_with($lower, 'mal') && mb_strlen($lower) > 3) {
                $multiple = GermanNumberParser::cardinal(mb_substr($lower, 0, -3));
                if ($multiple !== null && $multiple >= 13) {
                    $cardinal = $multiple;
                }
            }

            if ($cardinal === null && $ordinal === null) {
                continue;
            }

            $next = self::normalizedAt($tokens, $i + 1);

            if ($cardinal !== null
                && in_array($lower, self::AMBIGUOUS, true)
                && !self::isNumberContext($next)) {
                continue;
            }

            // Ordinal number: a day of the month.
            if ($cardinal === null && $ordinal !== null) {
                $values[] = NumberValue::day($ordinal, $token);
                continue;
            }

            // Time of day: "zwei Uhr fünf" or "gegen zwei Uhr".
            if ($next === 'uhr') {
                $minute = 0;
                $consumed = 1;
                $after = self::normalizedAt($tokens, $i + 2);
                $minuteValue = $after === '' ? null : GermanNumberParser::cardinal($after);
                if ($minuteValue !== null && $minuteValue >= 0 && $minuteValue < 60 && !in_array($after, self::AMBIGUOUS, true)) {
                    $minute = (int) $minuteValue;
                    $consumed = 2;
                }

                $values[] = NumberValue::time((int) $cardinal, $minute, trim($token . ' Uhr'));
                $i += $consumed;
                continue;
            }

            // Decimal number: "vier Komma acht", also with a scale word
            // after it: "zwei Komma fünf Millionen".
            if ($next === 'komma') {
                [$value, $skip] = self::readDecimal($tokens, $i, $cardinal);
                $scale = self::normalizedAt($tokens, $i + $skip + 1);
                if ($skip > 0 && isset(self::SCALES[$scale])) {
                    $value *= self::SCALES[$scale];
                    $skip++;
                }
                $values[] = NumberValue::number($negative ? -$value : $value, $token . ' Komma …');
                $i += $skip;
                continue;
            }

            // Scale word: "einhundertneun Millionen".
            if (isset(self::SCALES[$next])) {
                $values[] = NumberValue::number(
                    ($negative ? -$cardinal : $cardinal) * self::SCALES[$next],
                    trim($token . ' ' . $tokens[$i + 1])
                );
                $i++;
                continue;
            }

            $values[] = NumberValue::number($negative ? -$cardinal : $cardinal, $token);
        }

        return $values;
    }

    /**
     * Reads the fractional part.
     *
     * Both forms occurring in the podcast are supported: digit by digit
     * ("acht sieben") and as a whole number ("siebenundachtzig").
     *
     * @param list<string> $tokens
     *
     * @return array{0:float,1:int} Value and number of additionally consumed words.
     */
    private static function readDecimal(array $tokens, int $index, float $whole): array
    {
        $digits = '';
        $consumed = 1; // the word "Komma"
        $position = $index + 2;
        $count = count($tokens);

        while ($position < $count) {
            $word = GermanNumberParser::normalize($tokens[$position]);
            $value = $word === '' ? null : GermanNumberParser::cardinal($word);

            // A scale word is not part of the fraction: in "zwei Komma fünf
            // Millionen" the parser would otherwise read "Millionen" as another digit.
            if ($value === null || $value < 0 || floor($value) !== $value
                || in_array($word, self::AMBIGUOUS, true) || isset(self::SCALES[$word])) {
                break;
            }

            $digits .= $value < 10 ? (string) (int) $value : (string) (int) $value;
            $consumed++;
            $position++;

            // A multi-digit number ends the fractional part.
            if ($value >= 10) {
                break;
            }
        }

        if ($digits === '') {
            return [$whole, 0];
        }

        return [(float) ($whole . '.' . $digits), $consumed];
    }

    private static function isNumberContext(string $next): bool
    {
        return $next === 'uhr' || $next === 'komma' || isset(self::SCALES[$next]);
    }

    /**
     * @param list<string> $tokens
     */
    private static function normalizedAt(array $tokens, int $index): string
    {
        return isset($tokens[$index]) ? GermanNumberParser::normalize($tokens[$index]) : '';
    }

    /**
     * @return list<string>
     */
    private static function tokenize(string $text): array
    {
        // Break tags contain digits and are not part of the spoken text.
        $clean = (string) preg_replace('/<break\b[^>]*>/iu', ' ', $text);
        $clean = (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $clean);

        $parts = preg_split('/\s+/u', trim($clean)) ?: [];

        return array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
    }
}
