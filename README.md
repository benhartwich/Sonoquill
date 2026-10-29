# Sonoquill

Turns a fact script, a WordPress post or your own source into a finished podcast episode — spoken with a clone of your own voice, checked number by number, mastered and handed to [Podlove](https://podlove.org/) as a draft. Two steps always stay with a human: approving the text and approving the audio.

Sonoquill grew out of the monthly sky-preview podcast of an astronomy club and is released as open source in the hope that it is useful to others. **There is no support.** Issues and pull requests are welcome, but nobody is obliged to answer them.

## What it does

1. **Source → fact script.** Upload a DOCX or paste text, pick a post or page of the site, or plug in your own source (see *Sources* below).
2. **Fact script → spoken script.** A language model (Anthropic Claude) rewrites the text for the ear: numbers as words, spoken transitions, a fixed opening and sign-off.
3. **Checks before you approve.** A deterministic number gate compares every number, time and date of the source with the spoken script (German and English; off for other languages). A second model call looks for changed, missing or invented statements. Nothing blocking goes through unconfirmed.
4. **Your approval.** You read, edit and approve the spoken script in WordPress. You get an e-mail when it is waiting.
5. **Speech synthesis** with your ElevenLabs voice clone, segment by segment, with a pronunciation dictionary that grows with every episode. Single segments can be regenerated or re-recorded with your own voice (speech-to-speech).
6. **Assembly.** Segments, pauses, opener, rotating music bridges between chapters, outro, chapter marks and a WebVTT transcript.
7. **Mastering** with Auphonic (or, in the production mode *direct*, the assembly as it is), then a **Podlove draft** with title, descriptions, chapters, transcript and an AI disclosure.
8. **Your second approval** — the episode goes online only when you publish it.

## Two assembly paths

| | ffmpeg on the server | without ffmpeg (typical shared hosting) |
|---|---|---|
| Joining segments | ffmpeg, pauses filled with room tone | plain PHP, frame by frame; pauses are silent MP3 frames |
| Opener, bridges, outro | mixed by the plugin like a radio feature (bridges fade under the next chapter) | added by Auphonic: intro with overlap and ducking, bridges as inserts, outro |
| Chapter times | calculated | calculated, then taken from the chapter marks Auphonic writes into the file |

The setting *Assembly* chooses automatically; a health check shows which path is active and why.

## Requirements

- WordPress 6.5+, PHP 8.2+ with `sodium`, `dom`, `mbstring`
- [Podlove Podcast Publisher](https://wordpress.org/plugins/podlove-podcasting-plugin-for-wordpress/)
- Accounts and API keys: [Anthropic](https://www.anthropic.com/), [ElevenLabs](https://elevenlabs.io/) (with a voice clone of the host — you need the rights to that voice), [Auphonic](https://auphonic.com/)
- Optional: `ffmpeg`/`ffprobe` and `exec()` or `proc_open()` for the ffmpeg path
- An encryption key in `wp-config.php` for the stored API keys:
  ```php
  define('AASPF_ENCRYPTION_KEY', '…32 random bytes, base64 encoded…');
  ```
  e.g. `php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"`

## Installation

From a release ZIP: upload it under *Plugins → Add New → Upload*. From source:

```sh
git clone https://github.com/benhartwich/sonoquill.git wp-content/plugins/sonoquill
cd wp-content/plugins/sonoquill && composer install --no-dev
```

Then open *Sonoquill → Settings*: podcast details (name, host, sign-off, language), API keys, voice, Auphonic preset. The health checks at the top tell you what is still missing.

## Prompts and languages

The prompts live in `prompts/de/` and `prompts/en/` and can be edited in the backend (*Sonoquill → Prompts*) or overridden by files in a directory of your choice — `<dir>/<lang>/<name>.md` first, then `<dir>/<name>.md`. The podcast language can be any language code (`uk`, `fr`, `pt-br` …); without prompts of its own the English ones apply, and the number gate is off (the health check says so). Placeholders `{podcast}`, `{host}`, `{editor}`, `{sign_off}` are filled from the settings. The interface is English with a German translation; other languages can be added via `languages/sonoquill.pot`.

## Sources

A source brings an episode to an imported fact script; from there the chain is the same for all. Add-ons register their own:

```php
add_action('sonoquill_register_sources', static function (): void {
    \Sonoquill\Source\Sources::register(new My\Plugin\CalendarSource());
});
```

Extend `Sonoquill\Source\AbstractSource` and implement `id()`, `label()`, `description()` and `renderStartForm()`; create episodes with `Sonoquill\Source\EpisodeFactory::fromDocument()`. Sources that work in the background can report progress, contribute checklist items, block the approval, add show-notes sections and more — see `src/Source/Source.php`. Through `Sonoquill\Source\SourcePreferences` (implemented by `AbstractSource`) a source can switch the number gate off for its episodes (`usesNumberGate()`) and choose the Batch API (`prefersBatch()`). Blocks of type `SourceBlock::CONTEXT` are background for the script: the model sees them, the fact check and the number gate do not.

## Hooks

Actions:

| Hook | Arguments | When |
|---|---|---|
| `sonoquill_register_sources` | — | first access to the sources |
| `sonoquill_status_changed` | `int $id, string $old, string $new` | every status change (`EpisodeRepository::update`) |
| `sonoquill_text_ready` | `int $id, string $status` | script, title and fact check are complete; once per script version |
| `sonoquill_text_approved` | `int $id, string $actor` | after the text approval |
| `sonoquill_montage_done` | `int $id, string $relativePath` | end of the montage (path relative to the storage directory) |
| `sonoquill_settings_sections` | `array $values` | settings page |
| `sonoquill_daily` | — | daily run |
| `sonoquill_settings_imported` | `int $version, array $changed` | after an import through the import interface (setting names only) |

Filters:

| Hook | Value, arguments |
|---|---|
| `sonoquill_model_for_step` | `string $model, string $step` (`redigat`, `metadaten`, `faktenpruefung`, `woerterbuch`, `research`) |
| `sonoquill_model_rates` | `array $rates` model prefix => [input, output] USD per million tokens |
| `sonoquill_use_batch` | `bool $use, int $episodeId, string $step` |
| `sonoquill_music_for_episode` | `array $music` (opener, outro, trenner), `int $episodeId` |
| `sonoquill_episode_slug` | `string $slug, array $episode` — only when the slug is first generated |
| `sonoquill_podcast_languages` | `array $choices` language code => name for the settings |
| `sonoquill_storage_dir`, `sonoquill_dictionary_file`, `sonoquill_podlove_asset` | paths and the Podlove asset |
| `sonoquill_option_defaults`, `sonoquill_settings_fields`, `sonoquill_health_checks`, `sonoquill_overview_cards`, `sonoquill_overview_panels` | settings, health checks, overview |

The text approval is available as an API: `Sonoquill\Pipeline\Approval::approveText(int $id, string $actor = 'admin', bool $ignoreGate = false): bool` (reason in `Approval::lastError()`). It runs the same checks as the button and starts the automatic chain.

## Import interface

A platform that prepares a podcast (concept, style, voice, dictionary) can hand the settings over in one request:

```http
POST /wp-json/sonoquill/v1/import
Authorization: Basic <user:application password>   (user with manage_options)

{
  "schema": 1,
  "version": 4,
  "quelle": "radioplattform",
  "podcast": { "name": "…", "moderation": "…", "schlussgruss": "…", "sprache": "de" },
  "konzept": "…",
  "stil": "…",
  "stimme": { "voice_id": "…", "modell": "eleven_multilingual_v2" },
  "woerterbuch": { "id": "…", "version": "…" },
  "auphonic": { "preset": "…" },
  "schluessel": { "elevenlabs": "…", "anthropic": "…", "auphonic": "…" }
}
```

Every field except `schema` and `version` is optional; what is not sent stays as it is. Unknown fields make the whole request fail (HTTP 400 with the reasons in `data.fehler`). The version must increase: an older version is refused (409), the same version with the same content is answered as unchanged, with other content refused (409). Credentials are encrypted with `AASPF_ENCRYPTION_KEY` (without it nothing is saved) and never returned — the response only says `"gesetzt": true` and whether each field changed (`geaendert`, `unveraendert`, `nicht_gesendet`). `GET /wp-json/sonoquill/v1/import/status` returns the imported version, the current voice, model, dictionary and preset, whether each key is set and the last log entries. The field names follow the German contract of radioplattform.eu.

## Development

```sh
composer install
vendor/bin/phpunit
```

See [CONTRIBUTING.md](CONTRIBUTING.md) and [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Privacy

Sonoquill sends the texts of an episode to Anthropic, the spoken texts (and your re-recordings) to ElevenLabs and the assembled audio with its metadata to Auphonic — only when you start the corresponding step. Details and links to the providers' terms are in `readme.txt` under *External services*.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
