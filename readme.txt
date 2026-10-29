=== Sonoquill – AI Podcast Production ===
Contributors: yoursql719
Tags: podcast, text to speech, voice clone, podlove, auphonic
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turns a fact script or a post into a finished podcast episode spoken with your own voice clone — checked number by number, with two human approvals.

== Description ==

Sonoquill turns written text into a finished podcast episode, spoken with your own voice clone. You keep writing — a fact script, a blog post, a page of your site — and Sonoquill does the rest: it rewrites the text for the ear, checks every number against your source, synthesises the audio, assembles music and chapters, has it mastered and hands you a ready Podlove draft.

Nothing goes online on its own. There are two human approvals: you approve the spoken text, and you approve the finished audio.

Sonoquill grew out of the monthly sky-preview podcast of an astronomy club, where it produces every episode. It is released as open source in the hope that it is useful to others.

= How an episode is made =

1. **Source.** Upload a DOCX, paste text, or pick a post or page of your site. Developers can add their own sources through a hook.
2. **Spoken script.** A language model (Anthropic Claude) rewrites the text for listening: numbers as words, spoken transitions, a fixed opening and sign-off. The prompts are editable, in German and English.
3. **Checks.** A deterministic number check compares every number, time and date of the source with the spoken script. A second model call looks for changed, missing or invented statements.
4. **Your first approval.** You read and edit the script in the browser. An e-mail tells you when it is waiting.
5. **Voice.** ElevenLabs speaks the script segment by segment with your voice clone. A pronunciation dictionary with phonetic (IPA) rules grows with every episode. Single passages can be re-recorded with your own voice.
6. **Assembly.** Pauses, opener, rotating music bridges between chapters, outro, chapter marks and a WebVTT transcript — with ffmpeg on your server, or in plain PHP with Auphonic adding the music.
7. **Mastering and draft.** Auphonic masters the episode; Sonoquill creates a Podlove draft with title, descriptions, chapters, transcript and an AI disclosure.
8. **Your second approval.** You listen and publish the episode in Podlove yourself.

= Highlights =

* **Numbers you can trust.** The number check is plain code, not a model: a date that changed between source and script blocks the approval until you confirm it.
* **Your voice, your rules.** Works with your own ElevenLabs voice clone. With Eleven v4, phonetic (IPA) pronunciation rules take effect in German and other languages, not only in English.
* **Transparent about AI.** Every episode carries a disclosure in the show notes, optionally also spoken at the end.
* **Runs on ordinary hosting.** No ffmpeg? Sonoquill joins the audio in PHP and lets Auphonic mix the music. Health checks show what works on your server.
* **Costs under control.** Model calls can run through the Anthropic Batch API at half price, and every episode shows what its model calls cost.

= Requirements =

* Podlove Podcast Publisher
* API keys for Anthropic, ElevenLabs (with a voice clone you have the rights to) and Auphonic
* An encryption key in wp-config.php: `define('AASPF_ENCRYPTION_KEY', '…');` — 32 random bytes, base64 encoded. The settings page suggests one.
* Optional: ffmpeg and exec() for mixing the music on your own server

Sonoquill is an open-source project without support. Bug reports and pull requests are welcome on [GitHub](https://github.com/benhartwich/sonoquill).

== Installation ==

1. Install Podlove Podcast Publisher.
2. Upload and activate Sonoquill.
3. Add the encryption key to wp-config.php (see Requirements).
4. Open Sonoquill → Settings, enter the podcast details and API keys. The health checks show what is still missing.

== Frequently Asked Questions ==

= Does it work without ffmpeg? =

Yes. Segments are then joined in PHP, and Auphonic adds opener, bridges and outro. The setting "Assembly" and a health check show which path is used.

= Which languages are supported? =

The interface is English with a German translation. Prompts and the number check exist for German and English.

= Can I use a voice that is not mine? =

Only if you have the rights to it. The voice clone is created in your own ElevenLabs account; Sonoquill only uses it.

= Are the raw recordings private? =

Segment audio, transcripts and the pronunciation file are stored in `wp-content/uploads/sonoquill/data-<random>` with an .htaccess and an index.php. Apache honours the .htaccess; nginx does not. The storage health check tests with a probe file whether the folder can be downloaded and tells you what to do. On nginx, block it with a rule such as `location ~ ^/wp-content/uploads/sonoquill/data- { deny all; }`, or move the storage outside the web root with the `sonoquill_storage_dir` filter.

= What does an episode cost? =

That depends on your plans with Anthropic, ElevenLabs and Auphonic. Speech synthesis is billed per character of the spoken script, the model calls per token. Each episode lists the costs of its model calls, and the ElevenLabs health check shows the remaining character quota.

= Does it publish anything automatically? =

No. If you enable it, Sonoquill runs the chain on its own up to the text approval and again up to the Podlove draft, but the approvals and the publishing are always yours.

= Is there support? =

No. Sonoquill is maintained as a side project. Issues and pull requests on GitHub are welcome; answers may take a while.

== External services ==

This plugin connects to the following services. Nothing is sent before you enter the corresponding API key, and each request happens only when the respective step runs (started by you or by the automatic chain you enabled).

= Anthropic (Claude API) =

Used to turn the fact script into a spoken script, to create title, descriptions and chapters, to check the spoken script against the source and to suggest pronunciation rules. Sent: the fact script, the spoken script, your prompts and the list of dictionary terms. Endpoint: api.anthropic.com.
Terms: https://www.anthropic.com/legal/commercial-terms — Privacy: https://www.anthropic.com/legal/privacy

= ElevenLabs =

Used for speech synthesis with your voice clone, for speech-to-speech re-recordings, to maintain the pronunciation dictionary and to show the remaining character quota. Sent: the spoken texts segment by segment, your re-recorded audio, dictionary rules. Endpoint: api.elevenlabs.io.
Terms: https://elevenlabs.io/terms-of-use — Privacy: https://elevenlabs.io/privacy-policy

= Auphonic =

Used to master the assembled episode and, without ffmpeg, to add opener, bridges and outro. Sent: the assembled audio, the music files, title, descriptions, keywords and chapter marks; Auphonic calls back a webhook URL of your site when the production is done. Endpoint: auphonic.com.
Terms: https://auphonic.com/terms_of_service — Privacy: https://auphonic.com/privacy

== Changelog ==

= 0.5.0 =
* Import interface for platforms: POST /wp-json/sonoquill/v1/import takes over podcast name, host, sign-off, language, concept, style, voice, model, pronunciation dictionary (ID and pinned version), Auphonic preset and the ElevenLabs, Anthropic and Auphonic credentials. Only for users with manage_options (application password); strict schema; settings that are not sent stay as they are; credentials are stored encrypted and never returned. Versioned and idempotent, logged without credentials; GET /wp-json/sonoquill/v1/import/status reports the state. Action sonoquill_settings_imported.
* New settings "Style and way of speaking" and "Concept of the podcast": appended to the prompt for the spoken script; the prompt's rules take precedence.
* When the dictionary or its version changes through an import, the local PLS file is set aside and loaded again from the bound version.

= 0.4.1 =
* German number check: a sign written as a character ("-4,4 mag", also with the minus sign or an en dash) counts as negative, as "minus" already did. Before, "-4,4" in the fact script against "minus vier Komma vier" in the spoken script was reported as an invented number and blocked text approval. Ranges such as "234–251" and names such as "M-31" are unaffected.
* German number check: "tausend" on its own ("über tausend") is read as 1000.

= 0.4.0 =
* Podcast languages beyond German and English: any language code; prompts per language (backend, prompt directory subfolder, bundled, English fallback). The number check runs only for German and English, can be switched off (setting or per source) and the health check says when it is off.
* Actions for add-ons: sonoquill_status_changed, sonoquill_text_ready, sonoquill_text_approved, sonoquill_montage_done.
* Public approval API (Sonoquill\Pipeline\Approval::approveText) and, opt-in, a signed one-click approval link in the notification mail (confirmation page, valid 48 hours, void after an edit).
* Production mode "direct": the montage goes to Podlove without Auphonic.
* Model per step (fact check, metadata, dictionary) and the filter sonoquill_model_for_step; prices for Claude Sonnet 5.5, Haiku 5.5 and Fable 5.1, filter sonoquill_model_rates; unknown models are estimated at the most expensive rate.
* Approval is blocked while the pronunciation dictionary has no pinned version; "Pin latest version" button.
* Context blocks in the source: background for the script that is neither fact-checked nor number-checked.
* Storage hardening: index.html in the storage and music folders, server snippets in the storage check, warning about Podlove download redirects next to a protected feed.
* Opener and outro variants (opener-1 … opener-5, outro-1 … outro-5) used fixed, in turn or randomly per episode; filter sonoquill_music_for_episode.
* Batch API per source and per call (filter sonoquill_use_batch).
* Filter sonoquill_episode_slug; Cyrillic titles are transliterated, and an empty slug becomes prefix plus date.

= 0.3.0 =
* Renamed to Sonoquill.
* Episode data is stored in uploads/sonoquill/ (protected, movable with the sonoquill_storage_dir filter); the storage check tests whether the folder can be downloaded from the web.
* Admin texts are sanitised with an allowlist; admin output is escaped with wp_kses().
* DOCX files are read without a bundled document library.
* Database tables use the aaspf_ prefix; the upgrade works on MySQL as well as MariaDB.
* Requires WordPress 6.9 (Action Scheduler 4.2).
* Supports ElevenLabs Eleven v4: phonetic (IPA) dictionary rules in German and other languages, and neighbouring text for smoother transitions between segments.

= 0.2.0 =
* First public release: pluggable sources (upload, WordPress post), editable prompts in German and English, English number check, assembly with or without ffmpeg, English interface with German translation.
