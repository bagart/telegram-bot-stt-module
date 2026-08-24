<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Console;

use BAGArt\TelegramBotStt\Guard\ProviderBreaker;
use BAGArt\TelegramBotStt\Media\FfmpegConverter;
use BAGArt\TelegramBotStt\Models\SttToken;
use BAGArt\TelegramBotStt\Models\SttTranscription;
use BAGArt\TelegramBotStt\Provider\ProviderRegistry;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Diagnostics (§12): migrations applied, ffmpeg presence/version, preset
 * sanity (+ optional --net reachability probe), vault tokens, breaker states,
 * Redis reachability, last-24h failure counts, budget config. Exit codes
 * follow the CLI contract (5 = policy failure).
 */
final class SttDoctorCommand extends Command
{
    /** @var list<string> */
    protected $signature = 'stt:doctor
                            {--bot= : Bot id to check the vault for}
                            {--net : Probe preset base URL reachability}';

    protected $description = 'STT module health diagnostics';

    public function handle(): int
    {
        /** @var CacheRepository $cache */
        $cache = app('cache')->store();

        $failures = 0;

        $failures += $this->checkMigrations();
        $failures += $this->checkFfmpeg();
        $failures += $this->checkPresets();
        $failures += $this->checkRedis();
        $failures += $this->checkVault();
        $this->checkBreakers($cache);
        $this->checkRecentFailures();
        $this->reportBudget();

        if ($failures > 0) {
            $this->error("stt:doctor — {$failures} failing check(s)");

            return 5;
        }

        $this->info('stt:doctor — all checks green');

        return 0;
    }

    private function checkMigrations(): int
    {
        $failures = 0;

        foreach (['stt_tokens', 'stt_transcriptions'] as $table) {
            $exists = false;

            try {
                $exists = DB::getSchemaBuilder()->hasTable($table);
            } catch (Throwable) {
            }

            if ($exists) {
                $this->line("✔ table {$table}");

                continue;
            }

            $this->warn("✖ table {$table} missing (run migrations)");
            $failures++;
        }

        return $failures;
    }

    private function checkFfmpeg(): int
    {
        $converter = new FfmpegConverter((string) config('stt.ffmpeg_path', ''));

        if ($converter->available()) {
            $version = implode(' ', $converter->version());
            $this->line("✔ ffmpeg available {$version}");

            return 0;
        }

        // Optional capability: only presets without container needs work without it.
        $this->line('• ffmpeg not available (optional; native ogg/opus providers still work)');

        return 0;
    }

    private function checkPresets(): int
    {
        $failures = 0;

        foreach ((new ProviderRegistry)->all() as $preset) {
            try {
                ProviderRegistry::assertSafeBaseUrl($preset->baseUrl);
                $this->line("✔ preset {$preset->key} → {$preset->baseUrl}");
            } catch (\InvalidArgumentException $e) {
                $this->warn("✖ preset {$preset->key}: {$e->getMessage()}");
                $failures++;
            }

            if ($this->option('net')) {
                $this->probeNet($preset->baseUrl);
            }
        }

        return $failures;
    }

    private function probeNet(string $baseUrl): void
    {
        try {
            $response = Http::timeout(5)->get($baseUrl);
            $this->line("  ↳ net probe: HTTP {$response->status()}");
        } catch (Throwable $e) {
            $this->line('  ↳ net probe failed: '.$e::class);
        }
    }

    private function checkRedis(): int
    {
        try {
            Redis::connection()->ping();
            $this->line('✔ redis reachable');

            return 0;
        } catch (Throwable $e) {
            $this->warn('✖ redis unreachable — guards will fail-open (§9 matrix)');

            return 1;
        }
    }

    private function checkVault(): int
    {
        $botId = (string) $this->option('bot');

        try {
            $query = SttToken::query();

            if ($botId !== '') {
                $query->where('bot_id', $botId);
            }

            $tokens = $query->get(['bot_id', 'provider_key']);

            if ($tokens->isEmpty()) {
                $this->line($botId !== '' ? "• no vault token for bot {$botId}" : '• vault empty');

                return 0;
            }

            foreach ($tokens as $token) {
                $this->line("✔ token present: bot {$token->bot_id} / {$token->provider_key}");
            }

            return 0;
        } catch (Throwable $e) {
            $this->warn('✖ vault unreadable: '.$e::class);

            return 1;
        }
    }

    private function checkBreakers(CacheRepository $cache): void
    {
        $breaker = new ProviderBreaker($cache);

        foreach ((new ProviderRegistry)->all() as $preset) {
            $state = match ($breaker->state($preset->key)) {
                ProviderBreaker::STATE_CLOSED => 'closed',
                ProviderBreaker::STATE_OPEN => 'OPEN',
                default => 'half-open',
            };

            $this->line("• breaker {$preset->key}: {$state}");
        }
    }

    private function checkRecentFailures(): void
    {
        try {
            $rows = SttTranscription::query()
                ->selectRaw('provider_key, status, count(*) as n')
                ->where('created_at', '>=', now()->subDay())
                ->groupBy('provider_key', 'status')
                ->get();

            if ($rows->isEmpty()) {
                $this->line('• last 24h: no transcriptions recorded');

                return;
            }

            foreach ($rows as $row) {
                $this->line("• last 24h: {$row->provider_key} {$row->status} = {$row->n}");
            }
        } catch (Throwable $e) {
            $this->line('• last 24h stats unavailable: '.$e::class);
        }
    }

    private function reportBudget(): void
    {
        $this->line(sprintf(
            '• budget: %ds total / %ds provider timeout / %d MB response cap / quota retention %dd',
            (int) config('stt.budget_seconds', 30),
            (int) config('stt.timeout_seconds', 20),
            (int) config('stt.max_response_bytes', 8388608) / 1024 / 1024,
            (int) config('stt.retention_days', 30),
        ));
    }
}
