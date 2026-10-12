<?php
declare(strict_types=1);

namespace Sonoquill\Tests\Unit;

use Sonoquill\Podlove\SlugBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SlugBuilderTest extends TestCase
{
    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function titles(): array
    {
        return [
            'inhaltsgetriebener Titel' => [
                'Venus im größten Glanz, Mondbedeckung am Tag — September 2026',
                'AASPodcastSeptember2026',
            ],
            'Monat am Anfang' => ['September 2026: Herbstbeginn', 'AASPodcastSeptember2026'],
            'oesterreichischer Monat' => ['Quadrantiden über den Alpen — Jänner 2027', 'AASPodcastJaenner2027'],
            'Maerz mit Umlaut' => ['Totale Mondfinsternis — März 2026', 'AASPodcastMaerz2026'],
            'Dezember' => ['Geminiden und Wintersternbilder — Dezember 2026', 'AASPodcastDezember2026'],
        ];
    }

    #[DataProvider('titles')]
    public function testBuildsFromMonthAndYear(string $title, string $expected): void
    {
        self::assertSame($expected, SlugBuilder::fromTitle($title, 'AASPodcast'));
    }

    public function testFallsBackWhenNoMonthIsRecognisable(): void
    {
        $slug = SlugBuilder::fromTitle('Sonderfolge zur Sonnenfinsternis', 'AASPodcast');

        self::assertStringStartsWith('AASPodcast', $slug);
        self::assertSame(1, preg_match('/^[A-Za-z0-9]+$/', $slug), 'Der Slug bestimmt den Dateinamen und muss dateisicher sein: ' . $slug);
    }

    public function testResultIsAlwaysFileSafe(): void
    {
        foreach ([
            'Folge mit / Schrägstrich und ? Fragezeichen',
            'Umlaute: Ärger, Öfen, Übermut',
            'Sehr langer Titel mit vielen Wörtern die alle mitgenommen werden könnten und noch mehr',
            '',
        ] as $title) {
            $slug = SlugBuilder::fromTitle($title, 'AASPodcast');
            self::assertSame(1, preg_match('/^[A-Za-z0-9]+$/', $slug), $title . ' → ' . $slug);
        }
    }

    public function testPrefixCanBeChanged(): void
    {
        self::assertSame('TestSeptember2026', SlugBuilder::fromTitle('Etwas — September 2026', 'Test'));
    }

    public function testConfiguredPrefixKeepsItsSpelling(): void
    {
        self::assertSame('AASPodcast', SlugBuilder::prefix('AASPodcast'));
        self::assertSame('MeinPodcast', SlugBuilder::prefix('Mein Podcast'));
        self::assertSame('SternueberWien', SlugBuilder::prefix('Stern über-Wien!'));
        self::assertSame('', SlugBuilder::prefix(' — '));
    }

    public function testAsciifyFoldsUmlauts(): void
    {
        self::assertSame('AergerUeberOefen', SlugBuilder::asciify('Ärger über Öfen'));
    }

    public function testEnglishMonthsAndWholeWordsOnly(): void
    {
        self::assertSame('PodcastOctober2026', SlugBuilder::fromTitle('Saturn at opposition — October 2026', 'Podcast'));
        self::assertSame('PodcastMay2027', SlugBuilder::fromTitle('Eta Aquariids — May 2027', 'Podcast'));
        // "Mailand" ist kein Mai; ohne Monat bleibt nur die dateisichere Titelform.
        self::assertStringNotContainsString('Mai2026', SlugBuilder::fromTitle('Sternwarte bei Mailand 2026', 'Podcast'));
    }

    public function testCyrillicTitlesAreTransliterated(): void
    {
        self::assertSame('BriefLystDliaOleny', SlugBuilder::fromTitle('Лист для Олени', 'Brief'));
        self::assertSame('ShchoZhYeYizhak', SlugBuilder::asciify('що ж є їжак'));
    }

    public function testNothingUsableFallsBackToPrefixAndDate(): void
    {
        $time = gmmktime(12, 0, 0, 10, 12, 2026);

        self::assertSame('Brief20261012', SlugBuilder::fromTitle('— ? —', 'Brief', $time));
        self::assertSame('Brief20261012', SlugBuilder::fromTitle('', 'Brief', $time));
    }
}

