<?php
declare(strict_types=1);

namespace Sonoquill\Podlove;

/**
 * Builds the identifier under which the episode is stored in Podlove.
 *
 * It determines the public file name — for previous episodes, for example
 * `AASPodcastJuli2026cutted.mp3`. That is why it is built from the month and
 * year in the title rather than from the whole title: the title is now
 * content-driven and changes completely every month.
 *
 * Titles in other scripts are transliterated (Cyrillic by a fixed table,
 * anything else via intl if available). If nothing usable is left, the slug
 * is the prefix plus the date — a daily episode then still gets a unique,
 * readable file name.
 *
 * This class has no knowledge of WordPress (wp_date() is used when present)
 * and can be tested without WordPress.
 */
final class SlugBuilder
{
    private const MONTHS = [
        'jänner' => 'Jaenner', 'januar' => 'Januar', 'februar' => 'Februar', 'feber' => 'Feber',
        'märz' => 'Maerz', 'april' => 'April', 'mai' => 'Mai', 'juni' => 'Juni',
        'juli' => 'Juli', 'august' => 'August', 'september' => 'September',
        'oktober' => 'Oktober', 'november' => 'November', 'dezember' => 'Dezember',
        'january' => 'January', 'february' => 'February', 'march' => 'March', 'may' => 'May',
        'june' => 'June', 'july' => 'July', 'october' => 'October', 'december' => 'December',
    ];

    /** Ukrainian national transliteration (2010), plus the Russian letters. */
    private const CYRILLIC = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'h', 'ґ' => 'g', 'д' => 'd', 'е' => 'e', 'є' => 'ie',
        'ж' => 'zh', 'з' => 'z', 'и' => 'y', 'і' => 'i', 'ї' => 'i', 'й' => 'i', 'к' => 'k', 'л' => 'l',
        'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
        'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch', 'ь' => '', 'ю' => 'iu',
        'я' => 'ia', 'ы' => 'y', 'э' => 'e', 'ё' => 'e', 'ъ' => '', '’' => '', 'ʼ' => '',
    ];

    /**
     * @param int|null $time Timestamp for the date fallback; default: now.
     */
    public static function fromTitle(string $title, string $prefix = 'Podcast', ?int $time = null): string
    {
        $month = '';
        foreach (self::MONTHS as $needle => $canonical) {
            // Whole words only: otherwise "mai" would also match "Mailand" and "may" would match "Mayor".
            if (preg_match('/(?<![\p{L}])' . preg_quote($needle, '/') . '(?![\p{L}])/iu', $title) === 1) {
                $month = $canonical;
                break;
            }
        }

        $year = preg_match('/\b(20\d{2})\b/', $title, $m) === 1 ? $m[1] : '';

        if ($month !== '' && $year !== '') {
            return $prefix . $month . $year;
        }

        // No recognizable month: build a file-safe form from the title.
        $fallback = self::asciify($title);
        if ($fallback !== '') {
            return $prefix . $fallback;
        }

        // Nothing usable in the title: prefix and date.
        $time ??= time();
        $date = function_exists('wp_date') ? (string) wp_date('Ymd', $time) : gmdate('Ymd', $time);

        return $prefix . $date;
    }

    /**
     * Latin letters for Cyrillic (and, with intl, other scripts).
     */
    public static function transliterate(string $text): string
    {
        // At the start of a word these letters are written differently: "Їжак" → "Yizhak".
        $initial = ['є' => 'ye', 'ї' => 'yi', 'й' => 'y', 'ю' => 'yu', 'я' => 'ya'];
        $text = (string) preg_replace_callback(
            '/(?<!\p{L})([єїйюяЄЇЙЮЯ])/u',
            static function (array $m) use ($initial): string {
                $lower = mb_strtolower($m[1], 'UTF-8');

                return $lower === $m[1] ? $initial[$lower] : ucfirst($initial[$lower]);
            },
            $text
        );

        $map = self::CYRILLIC;
        foreach (self::CYRILLIC as $letter => $latin) {
            $map[mb_strtoupper($letter, 'UTF-8')] = ucfirst($latin);
        }
        $text = strtr($text, $map);

        if (preg_match('/[^\x00-\x7F]/', $text) === 1 && class_exists('\\Transliterator')) {
            $transliterator = \Transliterator::create('Any-Latin; Latin-ASCII');
            if ($transliterator !== null) {
                $converted = $transliterator->transliterate($text);
                if (is_string($converted)) {
                    $text = $converted;
                }
            }
        }

        return $text;
    }

    /**
     * A configured prefix, made file-safe without touching its spelling:
     * "AASPodcast" stays "AASPodcast" (asciify() would turn it into
     * "Aaspodcast"). Umlauts are folded, everything else but letters and
     * digits is dropped.
     */
    public static function prefix(string $text): string
    {
        $text = strtr($text, [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
            'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue',
        ]);

        return preg_replace('/[^A-Za-z0-9]/', '', $text) ?? '';
    }

    public static function asciify(string $text): string
    {
        $text = strtr($text, [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
            'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue',
        ]);
        $text = self::transliterate($text);

        $words = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $parts = array_map(
            static fn (string $w): string => ucfirst(mb_strtolower($w, 'UTF-8')),
            array_slice($words, 0, 6)
        );

        return preg_replace('/[^A-Za-z0-9]/', '', implode('', $parts)) ?? '';
    }
}
