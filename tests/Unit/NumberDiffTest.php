<?php
declare(strict_types=1);

namespace Sonoquill\Tests\Unit;

use Sonoquill\Numbers\NumberDiff;
use PHPUnit\Framework\TestCase;

final class NumberDiffTest extends TestCase
{
    /**
     * Himmelsvorschau November 2026: sieben Abweichungen, alle Fehlalarme.
     */
    public function testSignedMagnitudesAndThousandAreClean(): void
    {
        $result = NumberDiff::compare(
            'Am Morgen seht ihr sie schon mit -4,4 mag. Am 29. November erreicht sie mit −4,9 mag ihren größten Glanz. '
                . 'Er ist mit -0,6 mag schon recht hell, mit bis zu -2,2 mag. UV Ceti ist der Prototyp von über 1000 bekannten Flarestars.',
            'Am Morgen seht ihr sie schon mit minus vier Komma vier Magnituden. Am neunundzwanzigsten November erreicht sie mit minus vier Komma neun Magnituden ihren größten Glanz. '
                . 'Er ist mit minus null Komma sechs Magnituden schon recht hell, mit bis zu minus zwei Komma zwei Magnituden. UV Ceti ist der Prototyp von über tausend bekannten Flarestars.'
        );

        self::assertFalse($result->isBlocking(), (string) wp_json_encode($result->toArray()));
    }

    public function testDroppedSignIsStillFound(): void
    {
        $result = NumberDiff::compare('mit -4,4 mag', 'mit vier Komma vier Magnituden');

        self::assertTrue($result->isBlocking());
    }

    public function testIdenticalTextsAreClean(): void
    {
        $result = NumberDiff::compare('Am 23. September um 2 Uhr 05', 'Am dreiundzwanzigsten September um zwei Uhr fünf');

        self::assertTrue($result->isClean());
        self::assertFalse($result->isBlocking());
    }

    public function testInventedNumberIsFound(): void
    {
        $result = NumberDiff::compare('Am 23. September', 'Am dreiundzwanzigsten September, siebenundvierzig Grad');

        self::assertCount(1, $result->invented);
        self::assertSame('zahl:47', $result->invented[0]->key);
        self::assertTrue($result->isBlocking());
    }

    public function testInventedNumberCannotBeAcknowledged(): void
    {
        $result = NumberDiff::compare('Am 23. September', 'Am dreiundzwanzigsten September, siebenundvierzig Grad');

        self::assertTrue(
            $result->isBlockingAfter(['zahl:47']),
            'Eine erfundene Zahl darf sich nicht wegklicken lassen.'
        );
    }

    public function testDroppedNumberIsFound(): void
    {
        $result = NumberDiff::compare('40 Bogensekunden und 25 Prozent', 'vierzig Bogensekunden');

        self::assertCount(1, $result->missing);
        self::assertSame('zahl:25', $result->missing[0]->key);
        self::assertTrue($result->isBlocking());
    }

    public function testDroppedNumberCanBeAcknowledged(): void
    {
        $result = NumberDiff::compare('40 Bogensekunden und 25 Prozent', 'vierzig Bogensekunden');

        self::assertFalse($result->isBlockingAfter(['zahl:25']));
    }

    public function testRoundingIsReportedAsChangedNotInvented(): void
    {
        $result = NumberDiff::compare('152 Millionen Kilometer', 'gut hundertfünfzig Millionen Kilometer');

        self::assertSame([], $result->invented, 'Eine Rundung ist keine Halluzination.');
        self::assertSame([], $result->missing);
        self::assertCount(1, $result->changed);
        self::assertSame('zahl:150000000', $result->changed[0]->key);
        self::assertSame('152000000', $result->changed[0]->counterpart, 'Der Vorlagenwert muss sichtbar bleiben.');
        self::assertTrue($result->isBlocking());
    }

    public function testRoundingCanBeAcknowledged(): void
    {
        $result = NumberDiff::compare('152 Millionen Kilometer', 'gut hundertfünfzig Millionen Kilometer');

        self::assertFalse($result->isBlockingAfter(['zahl:150000000']));
    }

    public function testFarOffNumberStaysInvented(): void
    {
        // Halbierung ist keine Rundung.
        $result = NumberDiff::compare('152 Millionen Kilometer', 'siebzig Millionen Kilometer');

        self::assertCount(1, $result->invented);
        self::assertSame([], $result->changed);
        self::assertTrue($result->isBlockingAfter(['zahl:70000000']));
    }

    public function testWrongTimeIsFoundInBothDirections(): void
    {
        $result = NumberDiff::compare('um 20 Uhr 04', 'um zwanzig Uhr vierzig');

        self::assertCount(1, $result->invented, 'zwanzig Uhr vierzig steht nicht in der Vorlage');
        self::assertCount(1, $result->missing, 'zwanzig Uhr vier fehlt im Skript');
        self::assertSame('zeit:20:40', $result->invented[0]->key);
        self::assertSame('zeit:20:04', $result->missing[0]->key);
    }

    public function testTransposedTimeIsNeverTreatedAsRounding(): void
    {
        // Ein Zahlendreher bei einer Uhrzeit ist der gefährliche Fall und darf
        // sich nicht als Rundung wegklicken lassen.
        $result = NumberDiff::compare('um 20 Uhr 04', 'um zwanzig Uhr vierzig');

        self::assertSame([], $result->changed);
        self::assertTrue($result->isBlockingAfter(['zeit:20:40', 'zeit:20:04']));
    }

    public function testWrongMonthIsFound(): void
    {
        $result = NumberDiff::compare('am 23. September', 'am dreiundzwanzigsten Oktober');

        self::assertSame('monat:10', $result->invented[0]->key);
        self::assertSame('monat:9', $result->missing[0]->key);
    }

    public function testRepetitionIsOnlyAFrequencyNote(): void
    {
        $result = NumberDiff::compare(
            'Der September bringt viel.',
            'Himmelsvorschau für den Monat September. Der September bringt viel. Einen schönen September.'
        );

        self::assertSame([], $result->invented);
        self::assertSame([], $result->missing);
        self::assertCount(1, $result->frequency);
        self::assertSame('monat:9', $result->frequency[0]->key);
        self::assertSame(1, $result->frequency[0]->sourceCount);
        self::assertSame(3, $result->frequency[0]->scriptCount);
        self::assertFalse($result->isBlocking(), 'Häufigkeit allein darf nicht blockieren.');
    }

    public function testFindingCarriesCounts(): void
    {
        $result = NumberDiff::compare('40 Bogensekunden', 'vierzig Bogensekunden und vierzig Grad');

        self::assertSame(1, $result->frequency[0]->sourceCount);
        self::assertSame(2, $result->frequency[0]->scriptCount);
    }

    /**
     * Drei Fälle aus der ersten Himmelsvorschau (Oktober 2026), die das
     * Sprechskript richtig wiedergab und der Diff trotzdem als Abweichung
     * meldete.
     */
    public function testScalesAndMultiplesFromTheOctoberEpisode(): void
    {
        $source = 'Der Mond steht 369 Tausend Kilometer entfernt. M 31 ist 2,5 Millionen Lichtjahre entfernt. '
            . 'Scheat übertrifft die Sonne 160-mal an Durchmesser. Man filmt ein paar tausend Bilder.';
        $script = 'Der Mond steht dreihundertneunundsechzigtausend Kilometer entfernt. M einunddreißig ist zwei Komma fünf '
            . 'Millionen Lichtjahre entfernt. Scheat übertrifft die Sonne hundertsechzigmal an Durchmesser. '
            . 'Man filmt ein paar tausend Bilder.';

        $result = NumberDiff::compare($source, $script)->toArray();

        self::assertSame([], $result['erfunden']);
        self::assertSame([], $result['fehlt']);
    }

    public function testEinmalStaysLanguage(): void
    {
        // "noch einmal" darf nicht als erfundene Eins gelten.
        $result = NumberDiff::compare('Schaut am 5. hin.', 'Schaut noch einmal am fünften hin.')->toArray();

        self::assertSame([], $result['erfunden']);
    }

    public function testDecimalPointInEquipmentNames(): void
    {
        // Himmelsvorschau Oktober 2026: Objektiv aus der Galerie.
        $result = NumberDiff::compare(
            'Aufgenommen mit einem Sigma 14mm f/1.8 Art. Am 5.10. war es klar.',
            'Aufgenommen mit einem Sigma Art Objektiv mit vierzehn Millimetern Brennweite und Blende eins Komma acht. Am fünften Oktober war es klar.'
        )->toArray();

        self::assertSame([], $result['erfunden']);
        self::assertSame([], $result['fehlt']);
    }
}
