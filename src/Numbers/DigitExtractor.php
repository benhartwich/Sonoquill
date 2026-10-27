<?php
declare(strict_types=1);

namespace Sonoquill\Numbers;

/**
 * Extracts typed values from the fact script, i.e. from text containing digits.
 *
 * The order of the patterns is essential and has been calibrated against real
 * source texts. Times must match first: in "am 30. schon um 2 Uhr 52." the
 * period after 52 ends the sentence and does not mark an ordinal. Likewise, in
 * "der andere nur 105." the 105 is a number of light years, not a date —
 * which is why a digit sequence followed by a period only counts as a day if a
 * suitable word precedes it or a month name follows it.
 */
final class DigitExtractor
{
    /** Words that are followed by an ordinal number. */
    private const DAY_LEAD = 'am|vom|zum|dem|den|des|seit|ab|bis|auf';

    /**
     * A sign in German text: the word "minus", or a hyphen, minus sign or en
     * dash directly in front of a digit. "-4,4 mag" in the fact script is the
     * spoken "minus vier Komma vier"; read without the sign, the number gate
     * reported both as invented (sky preview November 2026). Not after a
     * letter or digit, so "S. 234–251", "4-5" and "M-31" stay ranges and
     * names.
     */
    private const SIGN = '(?<![\p{L}\p{N}])[\-\x{2212}\x{2013}](?=\d)|minus\s+';

    /**
     * @return list<NumberValue>
     */
    public static function extract(string $text, string $language = 'de'): array
    {
        return ($language === 'en' ? self::runEnglish($text) : self::run($text))[0];
    }

    /**
     * The text with all recognised digit positions blanked out.
     *
     * The number-word extractor then runs on this, so that the same
     * position is not counted twice.
     */
    public static function stripped(string $text, string $language = 'de'): string
    {
        return ($language === 'en' ? self::runEnglish($text) : self::run($text))[1];
    }

    /**
     * English digit formats: 12-hour times with a.m./p.m., 24-hour times,
     * "October 4th" / "4 October", decimal point, comma as thousands separator.
     *
     * @return array{0:list<NumberValue>,1:string}
     */
    private static function runEnglish(string $text): array
    {
        $values = [];
        $rest = $text;
        $months = NumberValue::monthLookup('en');
        $monthPattern = implode('|', array_keys($months));
        $ampm = '([ap])\.?\s?m\.?(?![a-z])';

        $rest = self::consume($rest, '/(\d{1,2}):(\d{2})\s*' . $ampm . '/iu', static function (array $m) use (&$values): void {
            $values[] = NumberValue::time(self::to24((int) $m[1], strtolower($m[3])), (int) $m[2], trim($m[0]));
        });
        $rest = self::consume($rest, '/(?<![\d:])(\d{1,2})\s*' . $ampm . '/iu', static function (array $m) use (&$values): void {
            $values[] = NumberValue::time(self::to24((int) $m[1], strtolower($m[2])), 0, trim($m[0]));
        });
        $rest = self::consume($rest, '/(?<![\d:])([01]?\d|2[0-3]):([0-5]\d)(?![\d:])/u', static function (array $m) use (&$values): void {
            $values[] = NumberValue::time((int) $m[1], (int) $m[2], trim($m[0]));
        });
        $rest = self::consume($rest, '/\b(' . $monthPattern . ')\s+(\d{1,2})(?:st|nd|rd|th)?\b/iu', static function (array $m) use (&$values, $months): void {
            $values[] = NumberValue::month($months[strtolower($m[1])], trim($m[0]));
            $values[] = NumberValue::day((int) $m[2], trim($m[0]));
        });
        $rest = self::consume($rest, '/\b(\d{1,2})(?:st|nd|rd|th)?\s+(?:of\s+)?(' . $monthPattern . ')\b/iu', static function (array $m) use (&$values, $months): void {
            $values[] = NumberValue::day((int) $m[1], trim($m[0]));
            $values[] = NumberValue::month($months[strtolower($m[2])], trim($m[0]));
        });
        $rest = self::consume($rest, '/\b(\d{1,2})(?:st|nd|rd|th)\b/iu', static function (array $m) use (&$values): void {
            $values[] = NumberValue::day((int) $m[1], trim($m[0]));
        });
        // A month on its own; "May" alone is too often the verb.
        $rest = self::consume($rest, '/\b(' . implode('|', array_diff(array_keys($months), ['may'])) . ')\b/iu', static function (array $m) use (&$values, $months): void {
            $values[] = NumberValue::month($months[strtolower($m[1])], trim($m[0]));
        });
        $rest = self::consume($rest, '/(-|minus\s+)?(\d+(?:\.\d+)?)\s*(billion|million|thousand)\b/iu', static function (array $m) use (&$values): void {
            $factor = EnglishNumberParser::SCALES[strtolower($m[3])];
            $value = (float) $m[2] * $factor;
            $values[] = NumberValue::number(($m[1] ?? '') !== '' ? -$value : $value, trim($m[0]));
        });
        $rest = self::consume($rest, '/(-|minus\s+)?\b\d{1,3}(?:,\d{3})+(?:\.\d+)?\b/iu', static function (array $m) use (&$values): void {
            $number = (float) str_replace([',', '-', 'minus'], '', strtolower($m[0]));
            $values[] = NumberValue::number(($m[1] ?? '') !== '' ? -abs($number) : $number, trim($m[0]));
        });
        $rest = self::consume($rest, '/(-|minus\s+)?(\d+)\.(\d+)/iu', static function (array $m) use (&$values): void {
            $value = (float) ($m[2] . '.' . $m[3]);
            $values[] = NumberValue::number(($m[1] ?? '') !== '' ? -$value : $value, trim($m[0]));
        });
        $rest = self::consume($rest, '/(-|minus\s+)?(\d+)/iu', static function (array $m) use (&$values): void {
            $value = (float) $m[2];
            $values[] = NumberValue::number(($m[1] ?? '') !== '' ? -$value : $value, trim($m[0]));
        });

        return [$values, $rest];
    }

    private static function to24(int $hour, string $marker): int
    {
        if ($marker === 'a') {
            return $hour === 12 ? 0 : $hour;
        }

        return $hour === 12 ? 12 : $hour + 12;
    }

    /**
     * @return array{0:list<NumberValue>,1:string}
     */
    private static function run(string $text): array
    {
        $values = [];
        $rest = $text;
        $months = NumberValue::monthLookup();
        $monthPattern = implode('|', array_map(
            static fn (string $m): string => preg_quote($m, '/'),
            array_keys($months)
        ));

        // 1. Time with minutes: "2 Uhr 05", "20 Uhr 04".
        // "18:30 Uhr", "18:30" and "18.30 Uhr" — the most common German forms.
        // Before everything else: otherwise "18" becomes a number and "30 Uhr"
        // a time of 30 o'clock. "18.30" without "Uhr" stays a decimal.
        $rest = self::consume($rest, '/(?<![\d:.,])([01]?\d|2[0-4]):([0-5]\d)(?:\s*Uhr\b)?(?![\d:])/iu', static function (array $m) use (&$values): void {
            $values[] = NumberValue::time((int) $m[1], (int) $m[2], trim($m[0]));
        });
        $rest = self::consume($rest, '/(?<![\d:.,])([01]?\d|2[0-4])\.([0-5]\d)\s*Uhr\b/iu', static function (array $m) use (&$values): void {
            $values[] = NumberValue::time((int) $m[1], (int) $m[2], trim($m[0]));
        });
        $rest = self::consume($rest, '/(\d{1,2})\s*Uhr\s*(\d{1,2})(?!\d)/iu', static function (array $m) use (&$values): void {
            $values[] = NumberValue::time((int) $m[1], (int) $m[2], trim($m[0]));
        });

        // 2. Time without minutes: "gegen 2 Uhr".
        $rest = self::consume($rest, '/(\d{1,2})\s*Uhr\b/iu', static function (array $m) use (&$values): void {
            $values[] = NumberValue::time((int) $m[1], 0, trim($m[0]));
        });

        // 3. Day with month name: "23. September".
        $rest = self::consume($rest, '/(\d{1,2})\.\s*(' . $monthPattern . ')\b/iu', static function (array $m) use (&$values, $months): void {
            $values[] = NumberValue::day((int) $m[1], trim($m[0]));
            $values[] = NumberValue::month($months[mb_strtolower($m[2], 'UTF-8')] ?? 0, trim($m[0]));
        });

        // 4. Numeric date: "22.09.2026".
        $rest = self::consume($rest, '/(\d{1,2})\.(\d{1,2})\.(\d{4})/u', static function (array $m) use (&$values): void {
            $values[] = NumberValue::day((int) $m[1], trim($m[0]));
            $values[] = NumberValue::month((int) $m[2], trim($m[0]));
            $values[] = NumberValue::number((float) $m[3], trim($m[0]));
        });

        // 4b. Numeric date without year: "5.10.", "vom 8. auf 9.10.".
        //     Real months only: anything that doesn't fit is left for the later
        //     patterns and gets counted instead of silently disappearing.
        $rest = self::consume($rest, '/(?<![\d.])(\d{1,2})\.(0?[1-9]|1[0-2])\.(?!\d)/u', static function (array $m) use (&$values): void {
            $values[] = NumberValue::day((int) $m[1], trim($m[0]));
            $values[] = NumberValue::month((int) $m[2], trim($m[0]));
        });

        // 5. Day from context: "am 17.", "bis zum 30.".
        $rest = self::consume($rest, '/\b(?:' . self::DAY_LEAD . ')\s+(\d{1,2})\./iu', static function (array $m) use (&$values): void {
            $values[] = NumberValue::day((int) $m[1], trim($m[0]));
        });

        // 6. Month name on its own: "Der September 2026".
        $rest = self::consume($rest, '/\b(' . $monthPattern . ')\b/iu', static function (array $m) use (&$values, $months): void {
            $values[] = NumberValue::month($months[mb_strtolower($m[1], 'UTF-8')] ?? 0, trim($m[0]));
        });

        // 7. Digits with a magnitude word: "109 Millionen", "1,5 Milliarden",
        //    "369 Tausend". The spoken script uses a single word for this
        //    ("dreihundertneunundsechzigtausend"); without the thousands, this
        //    would compare 369 against 369000 (sky preview October 2026).
        $rest = self::consume($rest, '/(' . self::SIGN . ')?(\d+(?:,\d+)?)\s*(Milliarden?|Millionen?|Tausend)\b/iu', static function (array $m) use (&$values): void {
            $value = (float) str_replace(',', '.', $m[2]);
            $factor = match (true) {
                stripos($m[3], 'milliard') === 0 => 1000000000.0,
                stripos($m[3], 'million') === 0  => 1000000.0,
                default                          => 1000.0,
            };
            if (($m[1] ?? '') !== '') {
                $value = -$value;
            }
            $values[] = NumberValue::number($value * $factor, trim($m[0]));
        });

        // 8. Decimal number with comma, sign taken into account: "minus 4,8", "-4,8".
        $rest = self::consume($rest, '/(' . self::SIGN . ')?(\d+),(\d+)/iu', static function (array $m) use (&$values): void {
            $value = (float) ($m[2] . '.' . $m[3]);
            if (($m[1] ?? '') !== '') {
                $value = -$value;
            }
            $values[] = NumberValue::number($value, trim($m[0]));
        });

        // 9. Thousands grouping: "27.000".
        $rest = self::consume($rest, '/(' . self::SIGN . ')?\b(\d{1,3}(?:\.\d{3})+)\b/iu', static function (array $m) use (&$values): void {
            $value = (float) str_replace('.', '', $m[2]);
            $values[] = NumberValue::number(($m[1] ?? '') !== '' ? -$value : $value, trim($m[0]));
        });

        // 9b. Decimal point, as in equipment names from the gallery: "f/1.8";
        //     "Lacerta 72/432" is unaffected. German would be "1,8"; the spoken
        //     script says "eins Komma acht". Without this pattern, "1.8" would
        //     count as 1 and 8 (sky preview October 2026). A period after or
        //     before it (date "5.10.") rules the pattern out.
        $rest = self::consume($rest, '/(?<![\d.])(' . self::SIGN . ')?(\d+)\.(\d{1,2})(?![\d.])/iu', static function (array $m) use (&$values): void {
            $value = (float) ($m[2] . '.' . $m[3]);
            $values[] = NumberValue::number(($m[1] ?? '') !== '' ? -$value : $value, trim($m[0]));
        });

        // 10. All remaining digit sequences.
        $rest = self::consume($rest, '/(' . self::SIGN . ')?(\d+)/iu', static function (array $m) use (&$values): void {
            $value = (float) $m[2];
            if (($m[1] ?? '') !== '') {
                $value = -$value;
            }
            $values[] = NumberValue::number($value, trim($m[0]));
        });

        return [$values, $rest];
    }

    /**
     * Applies a pattern and replaces the matches with spaces so that
     * later patterns do not capture the same text again.
     *
     * @param callable(array<int,string>):void $handler
     */
    private static function consume(string $text, string $pattern, callable $handler): string
    {
        return (string) preg_replace_callback(
            $pattern,
            static function (array $matches) use ($handler): string {
                $handler($matches);

                return str_repeat(' ', mb_strlen($matches[0]));
            },
            $text
        );
    }
}
