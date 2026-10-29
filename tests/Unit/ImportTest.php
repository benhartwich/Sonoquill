<?php
declare(strict_types=1);

namespace Sonoquill\Tests\Unit;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Sonoquill\Ai\PromptLoader;
use Sonoquill\Import\SettingsImport;
use Sonoquill\Rest\ImportEndpoint;
use Sonoquill\Settings\Options;
use Sonoquill\Support\Crypto;
use Sonoquill\Support\KeyStore;
use Sonoquill\Tests\Wp;
use Sonoquill\Tests\WpTestCase;

/**
 * Import interface for platforms: strict schema, merge without losing other
 * settings, credentials only encrypted and never in the response, versions
 * and idempotency, log without credentials.
 */
final class ImportTest extends WpTestCase
{
    private const EL_KEY = 'el_test_placeholder_0123456789';
    private const CLAUDE_KEY = 'sk-ant-test-placeholder-0123456789';

    /**
     * @param array<string,mixed> $override
     *
     * @return array<string,mixed>
     */
    private function payload(array $override = []): array
    {
        return array_replace([
            'schema'      => 1,
            'version'     => 1,
            'quelle'      => 'radioplattform',
            'podcast'     => ['name' => 'WPcast', 'moderation' => 'Ben', 'schlussgruss' => 'Macht’s gut!', 'sprache' => 'de'],
            'konzept'     => "Thema: WordPress\nSegmente:\n1. News",
            'stil'        => "Anrede: ihr\nSprechduktus: ruhig, kurze Pausen vor Pointen",
            'stimme'      => ['voice_id' => 'gRJR8Fqocw86Vixo4cZV', 'modell' => 'eleven_multilingual_v2'],
            'woerterbuch' => ['id' => 'dictAbc12345', 'version' => 'verAbc12345'],
            'auphonic'    => ['preset' => 'PresetAbc123'],
            'schluessel'  => ['elevenlabs' => self::EL_KEY, 'anthropic' => self::CLAUDE_KEY],
        ], $override);
    }

    public function testValidPayloadIsNormalised(): void
    {
        $check = SettingsImport::validate($this->payload(['podcast' => ['name' => " <b>WPcast</b>\n ", 'sprache' => 'DE']]));

        self::assertSame([], $check['errors']);
        self::assertSame(1, $check['version']);
        self::assertSame('WPcast', $check['values']['podcast_name']);
        self::assertSame('de', $check['values']['podcast_language']);
        self::assertSame("Anrede: ihr\nSprechduktus: ruhig, kurze Pausen vor Pointen", $check['values']['style_guide']);
        self::assertSame(self::EL_KEY, $check['keys']['elevenlabs_api_key']);
    }

    public function testSchemaIsStrict(): void
    {
        $cases = [
            'unknown top level'  => [$this->payload(['extra' => 1]), 'Unknown field "extra"'],
            'unknown nested'     => [$this->payload(['stimme' => ['voice_id' => 'gRJR8Fqocw86Vixo4cZV', 'stability' => 1]]), 'Unknown field "stimme.stability"'],
            'wrong schema'       => [$this->payload(['schema' => 2]), '"schema" must be 1'],
            'missing version'    => [$this->payload(['version' => '3']), '"version" must be a positive integer'],
            'bad voice'          => [$this->payload(['stimme' => ['voice_id' => '../etc']]), '"stimme.voice_id" has an invalid format'],
            'dictionary no pin'  => [$this->payload(['woerterbuch' => ['id' => 'dictAbc12345']]), 'belong together'],
            'list instead'       => [$this->payload(['podcast' => ['WPcast']]), '"podcast" must be an object'],
            'empty concept'      => [$this->payload(['konzept' => '   ']), '"konzept" must have 1 to 20000'],
            'bad language'       => [$this->payload(['podcast' => ['sprache' => 'deutsch!']]), 'language code'],
        ];
        foreach ($cases as $name => [$payload, $expected]) {
            $check = SettingsImport::validate($payload);
            self::assertStringContainsString($expected, implode(' | ', $check['errors']), $name);
            self::assertSame([], $check['values'], $name . ': nothing from an invalid payload');
        }

        self::assertNotSame([], SettingsImport::validate('nope')['errors']);
        self::assertNotSame([], SettingsImport::validate([1, 2])['errors']);
    }

    public function testInvalidCredentialIsNeverEchoed(): void
    {
        $secret = 'geheim mit leerzeichen 0123456789';
        $check = SettingsImport::validate($this->payload(['schluessel' => ['elevenlabs' => $secret]]));

        self::assertStringContainsString('"schluessel.elevenlabs" has an invalid format', implode(' ', $check['errors']));
        self::assertStringNotContainsString($secret, (string) json_encode($check));
    }

    public function testApplyMergesOnlySentFieldsAndEncryptsKeys(): void
    {
        $crypto = new Crypto(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $current = ['podcast_name' => 'WPcast', 'notify_email' => 'ben@example.org', 'music_enabled' => true, 'anthropic_api_key' => $crypto->encrypt(self::CLAUDE_KEY)];
        $payload = $this->payload();
        unset($payload['auphonic']);
        $check = SettingsImport::validate($payload);
        self::assertSame([], $check['errors']);

        $result = SettingsImport::apply($current, $check['values'], $check['keys'], $crypto);

        self::assertSame('ben@example.org', $result['settings']['notify_email'], 'other settings stay');
        self::assertTrue($result['settings']['music_enabled']);
        self::assertSame('unveraendert', $result['felder']['podcast_name']);
        self::assertSame('geaendert', $result['felder']['style_guide']);
        self::assertSame('nicht_gesendet', $result['felder']['auphonic_preset']);
        self::assertSame(['gesetzt' => true, 'status' => 'geaendert'], $result['schluessel']['elevenlabs_api_key']);
        self::assertSame(['gesetzt' => true, 'status' => 'unveraendert'], $result['schluessel']['anthropic_api_key']);
        self::assertSame(['gesetzt' => false, 'status' => 'nicht_gesendet'], $result['schluessel']['auphonic_api_key']);
        self::assertSame($current['anthropic_api_key'], $result['settings']['anthropic_api_key'], 'unchanged key keeps its envelope');
        self::assertTrue(Crypto::looksEncrypted($result['settings']['elevenlabs_api_key']));
        self::assertSame(self::EL_KEY, $crypto->decrypt($result['settings']['elevenlabs_api_key']));
        self::assertStringNotContainsString(self::EL_KEY, (string) json_encode([$result['felder'], $result['schluessel'], $result['changed']]));
    }

    public function testKeysWithoutEncryptionAreRefused(): void
    {
        $this->expectException(\Sonoquill\Support\CryptoException::class);
        SettingsImport::apply([], [], ['elevenlabs_api_key' => self::EL_KEY], null);
    }

    public function testEndpointRefusesKeysWhenTheConstantIsMissing(): void
    {
        $result = ImportEndpoint::handle($this->payload(), 1);

        self::assertSame('sonoquill_import_no_key', $result['error'][0]);
        self::assertSame('', (string) (Options::all()['style_guide'] ?? ''), 'nothing saved');
    }

    public function testEndpointRejectsInvalidPayloadWithTheReasons(): void
    {
        $result = ImportEndpoint::handle($this->payload(['stimme' => ['voice_id' => 'x']]), 1);

        self::assertSame(['sonoquill_import_invalid', 400], [$result['error'][0], $result['error'][2]]);
        self::assertNotEmpty($result['error'][3]['fehler']);
    }

    #[RunInSeparateProcess]
    public function testEndpointImportsIsIdempotentAndRefusesOlderVersions(): void
    {
        define(KeyStore::CONSTANT, Crypto::generateKeyBase64());
        $this->settings(['notify_email' => 'ben@example.org', 'elevenlabs_dictionary_id' => 'oldDict12345', 'elevenlabs_dictionary_version_id' => 'oldVer12345']);
        $pls = \Sonoquill\Voice\DictionaryWriter::path();
        @mkdir(dirname($pls), 0777, true);
        file_put_contents($pls, '<lexicon/>');

        $first = ImportEndpoint::handle($this->payload(['version' => 3]), 7);

        self::assertTrue($first['ok']);
        self::assertSame(3, $first['version']);
        self::assertFalse($first['unveraendert']);
        self::assertSame('geaendert', $first['felder']['elevenlabs_dictionary_id']);
        self::assertSame(['gesetzt' => true, 'status' => 'geaendert'], $first['schluessel']['elevenlabs_api_key']);
        self::assertSame('ben@example.org', Options::get('notify_email'));
        self::assertSame('gRJR8Fqocw86Vixo4cZV', Options::get('elevenlabs_voice_id'));
        self::assertSame(self::EL_KEY, Options::secret('elevenlabs_api_key'));
        self::assertFileDoesNotExist($pls, 'local PLS of the old dictionary is set aside');
        self::assertCount(1, glob($pls . '.before-import-*') ?: []);

        $stored = (string) json_encode(Wp::$options);
        self::assertStringNotContainsString(self::EL_KEY, $stored, 'never stored in plain text');
        self::assertStringNotContainsString(self::CLAUDE_KEY, $stored);
        self::assertStringNotContainsString(self::EL_KEY, (string) json_encode($first));

        // Same version, same content: unchanged, nothing written again
        $again = ImportEndpoint::handle($this->payload(['version' => 3]), 7);
        self::assertTrue($again['unveraendert']);
        self::assertCount(1, get_option(ImportEndpoint::STATE_OPTION)['protokoll']);

        // Same version, other content; older version
        self::assertSame('sonoquill_import_version_taken', ImportEndpoint::handle($this->payload(['version' => 3, 'stil' => 'anders']), 7)['error'][0]);
        self::assertSame('sonoquill_import_outdated', ImportEndpoint::handle($this->payload(['version' => 2]), 7)['error'][0]);

        // Newer version with only one field: the rest stays
        $next = ImportEndpoint::handle(['schema' => 1, 'version' => 4, 'stil' => 'Anrede: du'], 7);
        self::assertSame(['style_guide'], get_option(ImportEndpoint::STATE_OPTION)['protokoll'][1]['geaendert']);
        self::assertSame('nicht_gesendet', $next['felder']['elevenlabs_voice_id']);
        self::assertSame('gRJR8Fqocw86Vixo4cZV', Options::get('elevenlabs_voice_id'));
        self::assertSame(['gesetzt' => true, 'status' => 'nicht_gesendet'], $next['schluessel']['elevenlabs_api_key']);

        $state = ImportEndpoint::state();
        self::assertSame(4, $state['version']);
        self::assertSame(['voice_id' => 'gRJR8Fqocw86Vixo4cZV', 'modell' => 'eleven_multilingual_v2'], $state['stimme']);
        self::assertSame(['id' => 'dictAbc12345', 'version' => 'verAbc12345'], $state['woerterbuch']);
        self::assertTrue($state['schluessel']['anthropic_api_key']['gesetzt']);
        self::assertFalse($state['schluessel']['auphonic_api_key']['gesetzt']);
        self::assertStringNotContainsString(self::EL_KEY, (string) json_encode($state));
        self::assertStringNotContainsString('v1.', (string) json_encode($state), 'not even the envelope');
        $fired = Wp::fired('sonoquill_settings_imported');
        self::assertCount(2, $fired, 'only real imports fire the action');
        self::assertSame([4, ['style_guide']], $fired[1]);
    }

    public function testStyleSectionIsAppendedOnlyWhenSet(): void
    {
        self::assertSame('', PromptLoader::styleSection('', '  ', true));

        $german = PromptLoader::styleSection('Anrede: ihr', 'Thema: WordPress', true);
        self::assertStringStartsWith('## STIL UND KONZEPT DES PODCASTS', $german);
        self::assertStringContainsString("<stil>\nAnrede: ihr\n</stil>", $german);
        self::assertStringContainsString("<konzept>\nThema: WordPress\n</konzept>", $german);
        self::assertStringContainsString('haben immer Vorrang', $german);

        self::assertStringStartsWith('## STYLE AND CONCEPT OF THE PODCAST', PromptLoader::styleSection('Address: you', '', false));
        self::assertStringNotContainsString('<konzept>', PromptLoader::styleSection('Address: you', '', false));
    }
}
