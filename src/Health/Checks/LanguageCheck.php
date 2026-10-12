<?php
declare(strict_types=1);

namespace Sonoquill\Health\Checks;

use Sonoquill\Ai\Prompts;
use Sonoquill\Health\CheckInterface;
use Sonoquill\Health\Result;
use Sonoquill\Numbers\NumberDiff;
use Sonoquill\Settings\Options;

/**
 * Podcast language, prompts and number gate.
 *
 * The number gate only understands German and English figures. For any
 * other language it is off — better no check than a check that reports
 * every figure as missing. This check says so before the first episode,
 * not in the middle of an approval.
 */
final class LanguageCheck implements CheckInterface
{
    public function id(): string
    {
        return 'sprache';
    }

    public function label(): string
    {
        return __('Language and number check', 'sonoquill');
    }

    public function run(): Result
    {
        $language = Options::language();
        $prompts = self::promptSources($language);

        if (!NumberDiff::supports($language)) {
            return Result::warn(
                /* translators: %s: language code, e.g. "uk" */
                sprintf(__('Podcast language "%s": the number check is off.', 'sonoquill'), $language),
                __('The number check only understands German and English figures; for this language it would report every figure as missing. Fact check and script checks still run — review the figures yourself before approving.', 'sonoquill')
                . ' ' . $prompts
            );
        }

        if (!Options::flag('number_gate')) {
            return Result::warn(
                __('The number check is switched off in the settings.', 'sonoquill'),
                __('Figures in the spoken script are not compared with the source. Fact check and script checks still run.', 'sonoquill')
            );
        }

        return Result::ok(
            /* translators: %s: language code, e.g. "de" */
            sprintf(__('Podcast language "%s", number check active.', 'sonoquill'), $language),
            $prompts
        );
    }

    /**
     * Which version of the prompts applies — for a language without bundled
     * prompts, whether there are own ones yet.
     */
    private static function promptSources(string $language): string
    {
        $builtinOnly = [];
        foreach (Prompts::NAMES as $name) {
            $raw = Prompts::raw($name, $language);
            if ($raw['source'] === 'builtin' && !str_contains($raw['path'], '/prompts/' . explode('-', $language)[0] . '/')) {
                $builtinOnly[] = $name;
            }
        }

        if ($builtinOnly === []) {
            return '';
        }

        return sprintf(
            /* translators: 1: comma-separated prompt names, 2: language code */
            __('No own prompts for %1$s yet — the English ones apply and ask the model to write in English unless you adapt them. Save versions for "%2$s" on the Prompts page or in the prompt directory.', 'sonoquill'),
            implode(', ', $builtinOnly),
            $language
        );
    }
}
