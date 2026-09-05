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

Dev mode (this monorepo): path repository + PSR-4 mapping live in the host
`composer.json`; provider is listed in `bootstrap/providers.php`.

```bash
composer dump-autoload
php artisan migrate          # stt_tokens, stt_transcriptions
```

Prod mode (servers): `cmd/deps/install --mode=prod` resolves
`bagart/telegram-bot-stt-module` from VCS sources via `composer.prod.json`.

## Usage

1. Enable the module for a bot: `php artisan tg:module:enable stt --bot=…`
2. In a private chat send bare `/text`, open *Provider*, pick e.g.
   `groq-whisper-v3`, paste the API key when prompted (stored encrypted).
3. Reply `/text` to a voice message → threaded transcription. Or just send a
   voice in a private chat (auto mode).
4. Health: `php artisan stt:doctor [--bot=…] [--net]`.

### Self-hosted Whisper via the platform docker stack

The platform ships an opt-in `whisper` compose service (speaches,
OpenAI-compatible) behind the `stt` Compose profile — see
`docs/docker/README.md`. It is off by default: nothing is pulled or started
until `STT_WHISPER_ENABLED=true` in `.env`, then `./cmd/docker/up stt`.
speaches expects full HuggingFace model IDs, so configure it through the
admin *custom provider* JSON rather than the `local-whisper` preset:

```json
{"name": "Local whisper", "base_url": "http://localhost:8000/v1",
 "model": "Systran/faster-whisper-small", "token": "<STT_WHISPER_API_KEY>"}
```

`small`/`base` fit the 30 s budget on CPU boxes (≈6 s p50 for an 11 s voice);
`large-v3` measures ≈28 s p50 — over budget. From inside the docker network
use `http://whisper:8000/v1`; the SSRF guard allows plain http for such
local targets.

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
  index is reserved *before* slow work; Telegram redelivery collapses into an
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

## Deviations from the original RFC (v1.0)

- No `stt_chat_access` table: the inviter branch was dropped from
  canManage(); group access = Telegram admins with delete rights +
  superadmins; private chat = peer user (keeps the schema at two tables).
- Pending text inputs live in the cache store (15-min TTL), not a DB table.
- Typing indicator is sent at pipeline start and before the provider call
  instead of a ~10 s refresh loop.
- Menu callback scope derives privacy from the embedded route chatId sign
  (positive = private): the parsed CallbackQuery DTO carries no usable
  originating-message payload in this platform's DTO layer.
- Deferred by default (RFC open questions): `audio`/`video_note`
  transcription, per-user quotas, verbose_json segments storage.

## Testing

```bash
composer test                          # from this directory (host vendor)
# or from the platform root:
php artisan test --testsuite=SttModule
```

75 tests / 280 assertions: callback grammar edges, settings clamps, renderer
escaping/truncation, provider catalog + SSRF rejection matrix, breaker
transitions, quota enforcement/fail-open, adapter contract (multipart fields,
Bearer, error taxonomy, size caps, token-leak guard), menu keyboard layout,
access rules incl. the private-chat peer rule.

`LoopbackWireTest` goes beyond `Http::fake()`: it forks a real loopback HTTP
server and runs admin custom-provider JSON → SSRF guard → ConfigResolver →
adapter over actual sockets — real multipart encoding, Bearer transmission,
JSON parsing, tokenless mode, revoked-key → `AUTH`.

Plus orchestration units (`VoiceTranscriptionOrchestrationTest`): §7bis step
machine against contract fakes — caps, silent/emoji/message surfacing modes,
quota refusal + stats, open-breaker abort, AUTH failure tripping the breaker,
zero-budget watchdog.

Feature/E2E (`tests/Feature/Stt/SttModuleE2ETest.php`,
`SttPruneCommandTest.php`): full settings/enablement/dedupe stack with a
sender spy — auto-mode threading + one-time privacy notice, redelivery → one
provider call + `(cached)` replay, panel render/denial, quota across voices,
callback verb through the real selector, `stt_*` series through
`/health/metrics`, prune sweep. Verified against the real Groq API and the
local speaches Whisper box (see docs/tasks/todo.stt.md QA results).

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

## Menu integration

Menu-hub surface per `telegram-platform-menu/docs/tasks/menu_integration.md` (M-3a):
`SttWebUi` — §8.3 schema form over the same raw keys `SttSettings::fromArray()`
reads. API tokens and custom-provider JSON stay in-chat (§8.5 secrets rule).
