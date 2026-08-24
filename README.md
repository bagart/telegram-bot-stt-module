# telegram-bot-stt-module

Speech-to-text module for the [BAGArt Telegram bot platform](../../../): turns
voice messages into threaded text replies.

- **`/text`** — reply to a voice → transcription; bare `/text` → settings panel.
- **Auto mode** — private chats on by default; groups opt-in with a trigger
  (`all` / `reply_bot` / `mention`).
- **Providers** — one `openai-stt` wire driver covers Groq Whisper (free tier),
  OpenAI Whisper and any self-hosted OpenAI-compatible endpoint (speaches,
  whisper-asr-webservice, LocalAI). New vendor = preset row.
- Disabled by default (`defaultEnabled: false`); enable per bot/chat via
  `tg:module:enable stt`.

RFC: `docs/tasks/todo.stt.md`. Sibling TTS module is fully independent
(separate repo/prefixes/tables/command namespace).

## Install

```bash
# path repository + autoload entries live in the host composer.json
composer dump-autoload
php artisan migrate          # stt_tokens, stt_transcriptions
```

Register in `bootstrap/providers.php`:

```php
BAGArt\TelegramBotStt\TelegramBotSttServiceProvider::class,
```

## Usage

1. Enable the module for a bot: `php artisan tg:module:enable stt --bot=…`
2. In a private chat send bare `/text`, open *Provider*, pick e.g.
   `groq-whisper-v3`, paste the API key when prompted (stored encrypted).
3. Reply `/text` to a voice message → threaded transcription. Or just send a
   voice in a private chat (auto mode).
4. Health: `php artisan stt:doctor [--bot=…] [--net]`.

### Schedule

Add to host `routes/console.php` (already wired):

```php
$schedule->command('stt:prune')->daily()->withoutOverlapping()
    ->when(fn (): bool => (bool) config('telegram.schedule_stt_prune_enabled', true));
```

## Architecture

```
src/
├── SttModule.php                  descriptor + registration (TgModuleContract)
├── ModuleFactory.php              service graph; container pulls only here
├── Processing/
│   ├── TranscribeProcessor.php    auto-mode voice messages
│   ├── TextCommandProcessor.php   /text reply→transcribe · bare→panel · cancel
│   ├── PendingInputProcessor.php  consumes template/token/JSON text inputs
│   ├── MenuProcessor.php          callback router ("tc:<chatId>:<verb>[:arg]")
│   └── VoiceTranscriptionService  §7bis step machine (budget watchdog)
├── Provider/
│   ├── ProviderRegistry.php       presets + SSRF-guarded custom JSON validator
│   ├── ConfigResolver.php         settings+preset+vault → runtime config
│   └── Adapter/OpenAiCompatibleStt.php  single wire driver for all presets
├── Media/FileDownloader.php       getFile → disk-streamed sink download (≤19 MB)
├── Guard/                         quota · chat semaphore · global cap · breaker
│                                  (Redis-backed readonly counters; fail-open)
├── Settings/SttSettings(Service)  inheritance + transactional patch + cache bust
├── Support/                       TemplateRenderer · TranscriptionRecorder (dedupe)
└── I18n/Strings.php               ru/en catalog
```

Key invariants (see RFC for full rationale):

- **Stream, don't buffer** — voice downloads go through `->sink()` to a 0600
  tmpfile; a 19 MB voice never sits in PHP memory; tmpfiles are unlinked in
  `finally` and swept by `stt:prune`.
- **Dedupe serializer** — `stt_transcriptions` unique `(bot_id, file_unique_id)`
  row is reserved *before* slow work; Telegram redelivery collapses into an
  instant cached reply.
- **Token hygiene** — provider keys use the Eloquent `encrypted` cast and are
  decrypted only inside `VaultTokenResolver`; Telegram file URLs embed the bot
  token and are never logged or surfaced in exceptions.
- **SSRF guard** — custom provider `base_url`: https required, http only for
  loopback/RFC1918/ULA targets, link-local & metadata ranges rejected.
- **Degraded Redis posture** — guards fail-open per §9 matrix (they protect
  free tiers/FPM capacity, not money or data); the breaker still bounds blast
  radius.
- **Budget** — wall-clock watchdog (default 30 s) aborts with `UNAVAILABLE`;
  download ≤8 s, provider call ≤20 s.

## Testing

```bash
composer test                          # from this directory (host vendor)
# or from the platform root:
php artisan test --testsuite=SttModule
```

54 tests / 208 assertions: callback grammar edges, settings clamps, renderer
escaping/truncation, provider catalog + SSRF rejection matrix, breaker
transitions, quota enforcement/fail-open, adapter contract (multipart fields,
Bearer, error taxonomy, size caps, token-leak guard), menu keyboard layout,
access rules incl. the private-chat peer rule.

Plus orchestration units (`VoiceTranscriptionOrchestrationTest`): §7bis step
machine against contract fakes — caps, silent/emoji/message surfacing modes,
quota refusal + stats, open-breaker abort, AUTH failure tripping the breaker,
zero-budget watchdog.

Host-side Feature/E2E (`tests/Feature/Modules/SttModuleE2ETest.php`,
`SttPruneCommandTest.php`): full settings/enablement/dedupe stack with a
sender spy — auto-mode threading + one-time privacy notice, redelivery → one
provider call + `(cached)` replay, panel render/denial, quota across voices,
callback verb through the real selector, `stt_*` series through
`/health/metrics`, prune sweep.

## Bench

Real-network latency profile (never in CI):

```bash
php scripts/bench-latency.php \
  --url=https://api.groq.com/openai/v1 \
  --token=gsk_... \
  --model=whisper-large-v3 \
  --file=voice.ogg \
  --iterations=5
```

Reports per-iteration status and min/p50/p95/max against the module SLO
(p95 ≤ 25 s).

## Metrics

`SttStats` counters are appended into the host `/health/metrics` endpoint
(additive `class_exists`-guarded block in `HealthController::metrics()`):

```
stt_total{bot_id,provider,status}          # ok | empty | <error_code>
stt_quota_blocked_total{bot_id}
stt_latency_bucket{provider,le}            # coarse 250ms..25s buckets (gauge)
stt_breaker{provider}                      # 0 closed | 1 open | 2 half-open
```
