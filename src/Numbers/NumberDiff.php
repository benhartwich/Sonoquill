<?php
declare(strict_types=1);

namespace Sonoquill\Numbers;

/**
 * The hard gate for numbers: every figure in the script is checked against the source.
 *
 * Deterministic, without a model. Hallucination protection that rests on a rule
 * is preferable to protection that rests on the model behaving well.
 *
 * A subtlety that only showed up in a real case: a rounding produces a missing
 * value and an invented value at the same time. "152 Millionen" becomes
 * "gut hundertfünfzig Millionen" — the 152 is missing, the 150 is new. If that
 * were treated as a hallucination, a deliberate rounding could no longer be
 * approved. That is why such pairs are detected and listed as *changed*, with
 * both values shown.
 *
 * This deliberately applies only to quantities. For times of day, days and
 * months, a value that is just slightly off is exactly the dangerous case —
 * "zwanzig Uhr vierzig" instead of "zwanzig Uhr vier" is a transposed digit,
 * not a rounding. There, the values stay classified as invented and missing.
 */
final class NumberDiff
{
    /** Largest relative deviation that still counts as a rounding. */
    private const ROUNDING_TOLERANCE = 0.1;

    /** Languages whose spoken figures the extractors understand. */
    public const LANGUAGES = ['de', 'en'];

    /**
     * Can the number gate check texts in this language? Accepts full codes
     * ("de-at" counts as German).
     */
    public static function supports(string $language): bool
    {
        return in_array(explode('-', strtolower($language))[0], self::LANGUAGES, true);
    }

    public static function compare(string $source, string $script, string $language = 'de'): DiffResult
    {
        $sourceValues = self::index($source, $language);
        $scriptValues = self::index($script, $language);

        $invented = [];
        $missing = [];
        $frequency = [];

        foreach ($scriptValues as $key => $entry) {
            if (!isset($sourceValues[$key])) {
                $invented[$key] = $entry;
                continue;
            }

            if ($sourceValues[$key]['count'] !== $entry['count']) {
                $frequency[] = new DiffFinding(
                    $key,
                    $entry['label'],
                    $sourceValues[$key]['count'],
                    $entry['count'],
                    $entry['context']
                );
            }
        }

        foreach ($sourceValues as $key => $entry) {
            if (!isset($scriptValues[$key])) {
                $missing[$key] = $entry;
            }
        }

        [$changed, $invented, $missing] = self::pairRoundings($invented, $missing);

        return new DiffResult(
            self::toFindings($invented, false),
            self::toFindings($missing, true),
            $changed,
            $frequency
        );
    }

    /**
     * For each invented quantity, looks for a missing one that is close enough
     * that both are a rounding of the same figure.
     *
     * @param array<string,array{label:string,count:int,context:string,kind:string,numeric:?float}> $invented
     * @param array<string,array{label:string,count:int,context:string,kind:string,numeric:?float}> $missing
     *
     * @return array{0:list<DiffFinding>,1:array<string,mixed>,2:array<string,mixed>}
     */
    private static function pairRoundings(array $invented, array $missing): array
    {
        $changed = [];

        foreach ($invented as $key => $entry) {
            if ($entry['kind'] !== NumberValue::NUMBER || $entry['numeric'] === null) {
                continue;
            }

            $partner = null;
            $bestDistance = null;

            foreach ($missing as $otherKey => $other) {
                if ($other['kind'] !== NumberValue::NUMBER || $other['numeric'] === null) {
                    continue;
                }

                if (!self::isRounding($other['numeric'], $entry['numeric'])) {
                    continue;
                }

                $distance = abs($other['numeric'] - $entry['numeric']);
                if ($bestDistance === null || $distance < $bestDistance) {
                    $bestDistance = $distance;
                    $partner = $otherKey;
                }
            }

            if ($partner === null) {
                continue;
            }

            $changed[] = new DiffFinding(
                $key,
                $entry['label'],
                $missing[$partner]['count'],
                $entry['count'],
                $entry['context'],
                $missing[$partner]['label']
            );

            unset($invented[$key], $missing[$partner]);
        }

        return [$changed, $invented, $missing];
    }

    private static function isRounding(float $source, float $script): bool
    {
        if ($source === $script) {
            return false;
        }

        $scale = max(abs($source), abs($script));
        if ($scale === 0.0) {
            return false;
        }

        // A change of sign is not a rounding.
        if (($source < 0) !== ($script < 0)) {
            return false;
        }

        return abs($source - $script) / $scale <= self::ROUNDING_TOLERANCE;
    }

    /**
     * @param array<string,array{label:string,count:int,context:string,kind:string,numeric:?float}> $entries
     *
     * @return list<DiffFinding>
     */
    private static function toFindings(array $entries, bool $fromSource): array
    {
        $findings = [];

        foreach ($entries as $key => $entry) {
            $findings[] = new DiffFinding(
                $key,
                $entry['label'],
                $fromSource ? $entry['count'] : 0,
                $fromSource ? 0 : $entry['count'],
                $entry['context']
            );
        }

        return $findings;
    }

    /**
     * @return array<string,array{label:string,count:int,context:string,kind:string,numeric:?float}>
     */
    private static function index(string $text, string $language = 'de'): array
    {
        $index = [];

        foreach (NumberExtractor::extract($text, $language) as $value) {
            if (!isset($index[$value->key])) {
                $index[$value->key] = [
                    'label'   => $value->label,
                    'count'   => 0,
                    'context' => $value->context,
                    'kind'    => $value->kind,
                    'numeric' => $value->numeric,
                ];
            }

            $index[$value->key]['count']++;
        }

        return $index;
    }
}
