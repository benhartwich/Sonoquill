# Architecture

## The chain

```
Source ──► fact script (PARSED) ──► spoken script (Redigat) ──► checks ──► text approval
                                                                               │
Podlove draft ◄── Auphonic* ◄── assembly (Montage) ◄── speech synthesis ◄──────┘
      │
      └──► audio approval ──► you publish
```

\* In the production mode `direct` (setting `production_mode`) Auphonic is skipped: `AutoChain::afterMontage` queues `Publish`, which takes the montage MP3, its duration and chapters, and creates the Podlove draft.

Every step is a background job (Action Scheduler, group `sonoquill`). Each step is idempotent: it can run again and continues where it stopped. Model calls can go through the Message Batches API (half price); a step then ends with `PendingBatch`, a poll job fetches the result and triggers the step again, which now finds the answer. Whether a call goes through the batch is decided by `BatchGate::useBatch()`: setting `anthropic_batch`, the source's `prefersBatch()`, filter `sonoquill_use_batch`. The model per step comes from `AnthropicClient::modelForStep()` (optional per-step settings, filter `sonoquill_model_for_step`).

Status changes are announced by `EpisodeRepository::update` (`sonoquill_status_changed`); further actions: `sonoquill_text_ready` (Notifier), `sonoquill_text_approved` (`Pipeline\Approval`), `sonoquill_montage_done` (Montage).

## Sources (`src/Source`)

A source creates episodes and brings them to status `PARSED` with `source_text` and `source_blocks` (headings are chapter hints). Sources that need time set `SOURCE_RUNNING` and implement `resume()`. The core ships `UploadSource` (DOCX/text) and `PostSource` (a post rendered with blocks, shortcodes removed, parsed by `Text\HtmlParser`). Everything a source contributes to the UI — progress notice, panel, checklist items, blocking findings, show notes, mail lines — goes through the `Source` interface, so the core never knows a concrete source.

## Checks before the text approval

- **Number gate** (`src/Numbers`, applied by `Pipeline\Gate`): off if the setting `number_gate` is off, if the podcast language is neither German nor English, or if the source's `usesNumberGate()` says so (`diff_json.aus` records why). Otherwise both texts are reduced to typed values — times, days, months, numbers — from digits and number words (German: `GermanNumberParser`/`SpokenExtractor`, English: `EnglishNumberParser`/`EnglishSpokenExtractor`). Invented values block; missing or changed ones block until confirmed. Deterministic on purpose: a rule beats a model's good behaviour.
- **Script guard:** no digits outside break tags, at most five break tags.
- **Fact check:** a model call for changed, missing or invented statements; severe findings must be confirmed.
- **Source findings:** a source may add its own blocking keys.
- Context blocks (`SourceBlock::CONTEXT`) reach the script prompt but neither the fact check nor the number gate.

## Text approval

`Pipeline\Approval::approveText()` is the only path: the approve button, the signed mail link (`Pipeline\ApprovalLink`, `Admin\MailApproval`; opt-in `mail_approval_links`; HMAC over episode, script hash and expiry; GET shows a confirmation page, POST approves) and add-ons. It refuses when the dictionary has no pinned version (`Voice\DictionaryPin`), respects the gate unless `ignoreGate`, and starts the automatic chain.

## Voice

Segments are paragraphs (long ones split at sentence ends); each has a hash over text, seed, model, voice settings and the dictionary rules it touches, so only changed segments are synthesised again. The pronunciation dictionary is a local PLS file (source of truth, version-controllable) mirrored to ElevenLabs as new versions; every synthesis pins a version.

## Assembly (`src/Audio`, `Pipeline\Montage`)

`AudioEngine` picks the path (setting `montage_mode`).

- **ffmpeg:** concat with room tone, loudness matching of re-recorded segments, music mixed by ffmpeg — opener fading under the first words, bridges starting 0.4 s after a chapter and fading under the next one, outro after the last word.
- **PHP:** `Mp3::concat` appends frames; pauses are Layer III frames without data (digital silence) in the segments' own format. `montage_json` stores the plan for Auphonic: intro with overlap and ducking, bridges as inserts at the chapter gaps, outro. Chapter and transcript times are mapped to the final timeline in advance; after production the chapter marks Auphonic wrote into the file (`Id3Chapters`) are taken as the reference, because the API reports chapters unshifted.

## Prompts and language

`Ai\Prompts` resolves each prompt: backend edit (option `aaspf_prompts`, per language) → `<prompt_dir>/<lang>/<name>.md` → `<prompt_dir>/<name>.md` → bundled `prompts/<lang>/<name>.md` → bundled `prompts/en/<name>.md`. The podcast language (`podcast_language`, any BCP-47-style code) selects prompts and number parser; the plugin's own wording inside prompts is German for German podcasts and English otherwise; the interface language is WordPress's.

## Import interface

`Rest\ImportEndpoint` (`POST /sonoquill/v1/import`, `GET /sonoquill/v1/import/status`, `manage_options`) lets a platform hand over concept, style, voice, dictionary, preset and credentials. `Import\SettingsImport` is the WordPress-free part: strict schema (unknown keys fail), normalisation, merge into `aaspf_settings` (fields not sent stay), credentials through `Crypto` and only reported as `gesetzt`. The endpoint adds the version check (older refused, same version idempotent via an HMAC fingerprint), a 60-second lock, the log in the option `sonoquill_import` and sets the local PLS aside when the dictionary or its version changes. `style_guide` and `podcast_concept` are appended to the script prompt (`PromptLoader::styleSection`).

## Storage and secrets

Working files live in `uploads/sonoquill/data-<random>`, protected by `.htaccess`, `index.php` and `index.html`; the storage check probes them over HTTP (HEAD) and gives nginx/Apache snippets if they are served. The music folder only blocks listings (the settings page plays the files). The Podlove check warns when a protected feed sits next to Podlove's public download redirects. The filter `sonoquill_storage_dir` moves the working files elsewhere, e.g. outside the web root. API keys are encrypted with libsodium using `AASPF_ENCRYPTION_KEY` from `wp-config.php`; without the key nothing is stored.

## Two human gates

Nothing reaches the public without two approvals: the text before any audio is produced, and the audio before the Podlove draft is published — which is a manual step in Podlove.
