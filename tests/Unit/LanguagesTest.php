<?php
declare(strict_types=1);

namespace Sonoquill\Tests\Unit;

use Sonoquill\Ai\FactCheckPrompt;
use Sonoquill\Ai\MetadataPrompt;
use Sonoquill\Ai\Prompts;
use Sonoquill\Db\EpisodeRepository;
use Sonoquill\Health\Checks\LanguageCheck;
use Sonoquill\Health\Result;
use Sonoquill\Numbers\NumberDiff;
use Sonoquill\Pipeline\Gate;
use Sonoquill\Settings\Options;
use Sonoquill\Source\AbstractSource;
use Sonoquill\Source\Sources;
use Sonoquill\Tests\WpTestCase;

/**
 * Podcast languages beyond German and English: language codes, prompt
 * lookup, wording of the plugin's own prompt parts, and the number gate.
 */
final class LanguagesTest extends WpTestCase
{
    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
        parent::tearDown();
    }

    public function testAnyLanguageCodeIsAccepted(): void
    {
        $this->settings(['podcast_language' => 'uk']);
        self::assertSame('uk', Options::language());

        $this->settings(['podcast_language' => 'pt_BR']);
        self::assertSame('pt-br', Options::language());
        self::assertSame('pt', Options::baseLanguage());
    }

    public function testEmptyOrInvalidFallsBackAsBefore(): void
    {
        $this->settings(['podcast_language' => '']);
        self::assertSame('en', Options::language(), 'Without get_locale the site counts as English.');

        $this->settings(['podcast_language' => '../etc']);
        self::assertSame('en', Options::language());
    }

    public function testOnlyGermanCountsAsGerman(): void
    {
        $this->settings(['podcast_language' => 'de']);
        self::assertTrue(Options::isGerman());
        $this->settings(['podcast_language' => 'de-at']);
        self::assertTrue(Options::isGerman());
        $this->settings(['podcast_language' => 'uk']);
        self::assertFalse(Options::isGerman());
    }

    public function testInternalWordingIsEnglishForEveryNonGermanLanguage(): void
    {
        $this->settings(['podcast_language' => 'uk']);
        self::assertStringStartsWith('## Source, the fact script', FactCheckPrompt::user('a', 'b'));
        self::assertStringContainsString('### Section 1', MetadataPrompt::user(['x']));

        $this->settings(['podcast_language' => 'de']);
        self::assertStringStartsWith('## Vorlage', FactCheckPrompt::user('a', 'b'));
    }

    public function testBuiltinPromptFallsBackToEnglish(): void
    {
        self::assertStringEndsWith('/prompts/en/script.md', Prompts::builtinPath('script', 'uk'));
        self::assertStringEndsWith('/prompts/de/script.md', Prompts::builtinPath('script', 'de-at'));
        self::assertStringEndsWith('/prompts/de/script.md', Prompts::builtinPath('script', 'de'));
    }

    public function testPromptLookupOrder(): void
    {
        $this->dir = sys_get_temp_dir() . '/sq-prompts-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/uk', 0777, true);
        $this->settings(['podcast_language' => 'uk', 'prompt_dir' => $this->dir]);

        // 4/5: nothing configured — bundled English.
        self::assertSame('builtin', Prompts::raw('script')['source']);
        self::assertStringEndsWith('/prompts/en/script.md', Prompts::raw('script')['path']);

        // 3: file directly in the directory.
        file_put_contents($this->dir . '/script.md', 'flat');
        self::assertSame('flat', Prompts::raw('script')['text']);

        // 2: language subfolder wins over the flat file.
        file_put_contents($this->dir . '/uk/script.md', 'ukrainian');
        self::assertSame('ukrainian', Prompts::raw('script')['text']);
        self::assertSame('directory', Prompts::raw('script')['source']);

        // 1: the version edited in the backend wins over everything.
        update_option(Prompts::OPTION, ['uk' => ['script' => 'override']]);
        self::assertSame('override', Prompts::raw('script')['text']);

        // A German podcast does not pick up the Ukrainian subfolder.
        self::assertSame('flat', Prompts::raw('script', 'de')['text']);
    }

    public function testEditorOffersThePodcastLanguage(): void
    {
        $this->settings(['podcast_language' => 'uk']);
        self::assertSame(['de', 'en', 'uk'], Prompts::editableLanguages());
    }

    public function testNumberDiffSupportsOnlyGermanAndEnglish(): void
    {
        self::assertTrue(NumberDiff::supports('de'));
        self::assertTrue(NumberDiff::supports('en-gb'));
        self::assertFalse(NumberDiff::supports('uk'));
    }

    public function testUnsupportedLanguageProducesNoFalseMissingFindings(): void
    {
        $this->settings(['podcast_language' => 'uk']);
        $id = $this->episode(['source_text' => 'Температура 23 градуси, 15 відсотків.', 'script_text' => 'Температура двадцять три градуси.']);

        $result = Gate::evaluate($id);

        self::assertFalse($result['blocking']);
        self::assertSame([], $result['diff']['fehlt']);
        self::assertSame(Gate::GATE_OFF_LANGUAGE, $result['diff']['aus']);
    }

    public function testGermanStillFindsMissingFigures(): void
    {
        $this->settings(['podcast_language' => 'de']);
        $id = $this->episode(['source_text' => '40 Bogensekunden und 25 Prozent', 'script_text' => 'vierzig Bogensekunden']);

        $result = Gate::evaluate($id);

        self::assertTrue($result['blocking']);
        self::assertNotSame([], $result['diff']['fehlt']);
        self::assertArrayNotHasKey('aus', $result['diff']);
    }

    public function testSettingSwitchesTheGateOff(): void
    {
        $this->settings(['podcast_language' => 'de', 'number_gate' => false]);
        $id = $this->episode(['source_text' => '40 Bogensekunden und 25 Prozent', 'script_text' => 'vierzig Bogensekunden']);

        $result = Gate::evaluate($id);

        self::assertFalse($result['blocking']);
        self::assertSame(Gate::GATE_OFF_SETTING, $result['diff']['aus']);
        self::assertSame('wartet_auf_textfreigabe', EpisodeRepository::find($id)['status']);
    }

    public function testSourceCanSwitchTheGateOff(): void
    {
        $this->settings(['podcast_language' => 'de']);
        Sources::register(new class extends AbstractSource {
            public function id(): string { return 'brief'; }
            public function label(): string { return 'Letter'; }
            public function description(): string { return ''; }
            public function renderStartForm(): void {}
            public function usesNumberGate(array $episode): bool { return false; }
        });
        $id = $this->episode(['source_type' => 'brief', 'source_text' => '40 Bogensekunden', 'script_text' => 'vierzig']);

        self::assertSame(Gate::GATE_OFF_SOURCE, Gate::evaluate($id)['diff']['aus']);
    }

    public function testHealthCheckWarnsForUnsupportedLanguage(): void
    {
        $this->settings(['podcast_language' => 'uk']);
        $result = (new LanguageCheck())->run();

        self::assertSame(Result::WARN, $result->status);
        self::assertStringContainsString('number check is off', $result->message);
        self::assertStringContainsString('English ones apply', $result->detail);

        $this->settings(['podcast_language' => 'de']);
        self::assertSame(Result::OK, (new LanguageCheck())->run()->status);
    }
}
