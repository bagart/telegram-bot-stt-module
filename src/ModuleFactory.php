<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt;

use BAGArt\TelegramBot\Contracts\ApiCommunication\TgBotApiDTOClientContract;
use BAGArt\TelegramBot\Contracts\Outbound\TgSenderContract;
use BAGArt\TelegramBotStt\Access\AccessService;
use BAGArt\TelegramBotStt\Guard\ChatSemaphore;
use BAGArt\TelegramBotStt\Guard\GlobalConcurrency;
use BAGArt\TelegramBotStt\Guard\ProviderBreaker;
use BAGArt\TelegramBotStt\Guard\QuotaCounter;
use BAGArt\TelegramBotStt\Media\FfmpegConverter;
use BAGArt\TelegramBotStt\Media\FileDownloader;
use BAGArt\TelegramBotStt\Processing\VoiceTranscriptionService;
use BAGArt\TelegramBotStt\Provider\ConfigResolver;
use BAGArt\TelegramBotStt\Provider\ProviderRegistry;
use BAGArt\TelegramBotStt\Provider\SttProviderContract;
use BAGArt\TelegramBotStt\Settings\SttSettingsService;
use BAGArt\TelegramBotStt\Support\SttStats;
use BAGArt\TelegramBotStt\Support\TemplateRenderer;
use BAGArt\TelegramBotStt\Support\TranscriptionRecorder;
use BAGArt\TelegramBotStt\Support\VaultTokenResolver;
use BAGArt\TelegramBotStt\Ui\MenuRenderer;
use BAGArt\TelegramBotStt\Ui\PendingInputService;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory;

/**
 * Service-graph builder for the module (Summarizer ModuleFactory mechanics).
 * Container-managed contracts come from app(); everything module-internal is
 * constructed here — nothing module-internal is auto-bound.
 */
final class ModuleFactory
{
    public const MODULE_ID = SttModuleId::ID;

    public static function inLaravel(): bool
    {
        return \function_exists('app') && app()->bound(TgSenderContract::class);
    }

    public static function settings(): SttSettingsService
    {
        return app(SttSettingsService::class);
    }

    public static function access(): AccessService
    {
        return new AccessService(app(TgBotApiDTOClientContract::class));
    }

    public static function providers(): ProviderRegistry
    {
        return new ProviderRegistry();
    }

    public static function menuRenderer(): MenuRenderer
    {
        return new MenuRenderer(self::providers());
    }

    public static function pending(): PendingInputService
    {
        /** @var CacheRepository $cache */
        $cache = app('cache')->store();

        return new PendingInputService($cache, (int) config('stt.pending_input_ttl_minutes', 15));
    }

    public static function configResolver(): ConfigResolver
    {
        return new ConfigResolver(
            registry: self::providers(),
            defaultTimeoutSeconds: (int) config('stt.timeout_seconds', 20),
            defaultMaxResponseBytes: (int) config('stt.max_response_bytes', 8388608),
        );
    }

    public static function converter(): FfmpegConverter
    {
        return new FfmpegConverter((string) config('stt.ffmpeg_path', ''));
    }

    public static function transcription(TgSenderContract $sender): VoiceTranscriptionService
    {
        /** @var CacheRepository $cache */
        $cache = app('cache')->store();

        /** @var Factory $http */
        $http = app(Factory::class);

        return new VoiceTranscriptionService(
            settingsService: self::settings(),
            configResolver: self::configResolver(),
            provider: app(SttProviderContract::class),
            downloader: new FileDownloader(
                api: app(TgBotApiDTOClientContract::class),
                http: $http,
                tmpDirOverride: self::tmpDir(),
            ),
            converter: self::converter(),
            recorder: new TranscriptionRecorder(),
            renderer: new TemplateRenderer(),
            quota: new QuotaCounter($cache),
            semaphore: new ChatSemaphore($cache),
            global: new GlobalConcurrency($cache, (int) config('stt.global_concurrency', 4)),
            breaker: new ProviderBreaker($cache),
            sender: $sender,
            tokens: new VaultTokenResolver(),
            stats: new SttStats($cache),
            budgetSeconds: (int) config('stt.budget_seconds', 30),
        );
    }

    private static function tmpDir(): ?string
    {
        $dir = (string) config('stt.tmp_dir', '');

        return $dir === '' ? null : $dir;
    }
}
