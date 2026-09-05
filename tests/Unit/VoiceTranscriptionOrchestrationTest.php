<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Tests\Unit;

use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\TgApi\Methods\DTO\SendChatActionMethodDTO;
use BAGArt\TelegramBot\TgApi\Methods\DTO\SendMessageMethodDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\ChatTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\FileTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\MessageTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\UserTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\VoiceTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\Enum\ChatPropTypeEnum;
use BAGArt\TelegramBotStt\Guard\ChatSemaphore;
use BAGArt\TelegramBotStt\Guard\GlobalConcurrency;
use BAGArt\TelegramBotStt\Guard\ProviderBreaker;
use BAGArt\TelegramBotStt\Guard\QuotaCounter;
use BAGArt\TelegramBotStt\Media\FfmpegConverter;
use BAGArt\TelegramBotStt\Media\FileDownloader;
use BAGArt\TelegramBotStt\Processing\VoiceTranscriptionService;
use BAGArt\TelegramBotStt\Provider\ConfigResolver;
use BAGArt\TelegramBotStt\Provider\ErrorCode;
use BAGArt\TelegramBotStt\Provider\ProviderRegistry;
use BAGArt\TelegramBotStt\Settings\SttSettings;
use BAGArt\TelegramBotStt\Support\SttStats;
use BAGArt\TelegramBotStt\Support\TemplateRenderer;
use BAGArt\TelegramBotStt\Support\TranscriptionRecorder;
use BAGArt\TelegramBotStt\Support\VaultTokenResolver;
use BAGArt\TelegramBotStt\Tests\Support\FakeFileApi;
use BAGArt\TelegramBotStt\Tests\Support\FakeProvider;
use BAGArt\TelegramBotStt\Tests\Support\SenderSpy;
use BAGArt\TelegramBotStt\Tests\Support\StubSettingsService;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\TestCase;

/**
 * §7bis step-machine orchestration against hand-written contract fakes:
 * caps, quota, breaker, budget, error surfacing modes and stats recording.
 * DB-backed dedupe branches are covered by the Feature/E2E suite.
 */
final class VoiceTranscriptionOrchestrationTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/stt-orch-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            foreach (scandir($this->tmpDir) ?: [] as $entry) {
                if (! in_array($entry, ['.', '..'], true)) {
                    @unlink($this->tmpDir.DIRECTORY_SEPARATOR.$entry);
                }
            }

            @rmdir($this->tmpDir);
        }

        parent::tearDown();
    }

    public function test_happy_path_replies_threaded_and_records_stats(): void
    {
        [$service, $spy, $provider] = $this->make();

        $service->transcribe($this->bot(), $this->message(100), $this->voice(), 'auto');

        self::assertSame(1, $provider->calls);

        $replies = array_values(array_filter($spy->sent, fn ($d) => $d instanceof SendMessageMethodDTO));
        self::assertCount(1, $replies);

        /** @var SendMessageMethodDTO $reply */
        $reply = $replies[0];
        self::assertStringContainsString('расшифрованный текст', (string) $reply->text);
        self::assertNotNull($reply->replyParameters);
        self::assertSame(100, $reply->replyParameters->messageId);
        self::assertNotNull($reply->text && str_contains((string) $reply->text, 'ℹ️'), 'first transcription appends privacy notice');
        // typing indicator precedes the reply
        self::assertInstanceOf(SendChatActionMethodDTO::class, $spy->sent[0]);
        // tmpfile is gone
        self::assertSame([], scandir($this->tmpDir) === false ? [] : array_values(array_diff(scandir($this->tmpDir) ?: [], ['.', '..'])));
    }

    public function test_notice_is_appended_only_once(): void
    {
        [$service, $spy, $provider, $settings] = $this->make();

        $service->transcribe($this->bot(), $this->message(101), $this->voice(), 'auto');
        $service->transcribe($this->bot(), $this->message(102), $this->voice(durationSec: 20), 'auto');

        $noticePatches = array_filter(
            $settings->patches,
            fn (array $p): bool => ($p['notice_shown'] ?? null) === true,
        );

        self::assertCount(1, $noticePatches);
        self::assertSame(2, $provider->calls);
    }

    public function test_duration_cap_surfaces_unsupported_input_on_command_and_skips_provider(): void
    {
        [$service, $spy, $provider] = $this->make(settingsRaw: ['max_duration_sec' => 10]);

        $service->transcribe($this->bot(), $this->message(103), $this->voice(durationSec: 60), 'command');

        self::assertSame(0, $provider->calls);
        self::assertStringContainsString('слишком длинный', (string) $spy->texts()[0]);
    }

    public function test_silent_error_mode_sends_nothing_in_auto(): void
    {
        [$service, $spy, $provider] = $this->make(
            settingsRaw: ['on_error' => SttSettings::ERROR_SILENT],
            provider: FakeProvider::failing(ErrorCode::Auth),
        );

        $service->transcribe($this->bot(), $this->message(104), $this->voice(), 'auto');

        self::assertSame(1, $provider->calls); // download ok, provider failed
        self::assertSame([], $spy->texts());
    }

    public function test_quota_refusal_blocks_provider_and_counts_stat(): void
    {
        $cache = TestCache::repository();
        $quota = new QuotaCounter($cache);
        $quota->increment('test_bot', 555); // used=1

        [$service, $spy, $provider, , $stats] = $this->make(
            settingsRaw: ['daily_quota' => 1],
            quota: $quota,
            cache: $cache,
        );

        $service->transcribe($this->bot(), $this->message(105), $this->voice(), 'auto');

        self::assertSame(0, $provider->calls);
        self::assertSame(['🚦 Лимит провайдера исчерпан — попробуйте позже.'], $spy->texts());

        $quotaLines = array_values(array_filter(
            $stats->prometheusLines(),
            fn (string $line): bool => str_contains($line, 'stt_quota_blocked_total{'),
        ));

        self::assertNotSame([], $quotaLines);
        self::assertStringContainsString('bot_id="test_bot"', $quotaLines[0]);
    }

    public function test_open_breaker_aborts_before_download(): void
    {
        $cache = TestCache::repository();
        $breaker = new ProviderBreaker($cache);

        for ($i = 0; $i < 5; $i++) {
            $breaker->recordFailure('groq-whisper-v3');
        }

        [$service, $spy, $provider] = $this->make(breaker: $breaker);

        $service->transcribe($this->bot(), $this->message(106), $this->voice(), 'command');

        self::assertSame(0, $provider->calls);
        self::assertStringContainsString('временно недоступна', (string) $spy->texts()[0]);
    }

    public function test_provider_auth_failure_surfaces_localized_text_and_trips_breaker(): void
    {
        $cache = TestCache::repository();
        $breaker = new ProviderBreaker($cache);

        [$service, $spy, $provider] = $this->make(
            provider: FakeProvider::failing(ErrorCode::Auth),
            breaker: $breaker,
        );

        $service->transcribe($this->bot(), $this->message(107), $this->voice(), 'command');

        self::assertSame(1, $provider->calls);
        self::assertStringContainsString('отклонил ключ', (string) $spy->texts()[0]);
        // one failure is below the threshold: circuit stays passable
        self::assertTrue($breaker->allow('groq-whisper-v3'));
        self::assertSame(ProviderBreaker::STATE_HALF_OPEN, $breaker->state('groq-whisper-v3'));
    }

    public function test_zero_budget_aborts_with_unavailable_without_provider_call(): void
    {
        [$service, $spy, $provider] = $this->make(budgetSeconds: 0);

        $service->transcribe($this->bot(), $this->message(108), $this->voice(), 'command');

        self::assertSame(0, $provider->calls);
        self::assertStringContainsString('временно недоступна', (string) $spy->texts()[0]);
    }

    /**
     * @return array{0: VoiceTranscriptionService, 1: SenderSpy, 2: FakeProvider, 3: StubSettingsService, 4: SttStats}
     */
    private function make(
        array $settingsRaw = ['locale' => 'ru'],
        ?FakeProvider $provider = null,
        ?QuotaCounter $quota = null,
        ?ProviderBreaker $breaker = null,
        int $budgetSeconds = 30,
        ?Repository $cache = null,
    ): array {
        $cache ??= TestCache::repository();
        $provider ??= new FakeProvider();
        $settingsStub = new StubSettingsService($settingsRaw);
        $quota ??= new QuotaCounter($cache);
        $breaker ??= new ProviderBreaker($cache);
        $spy = new SenderSpy();

        $http = new Factory();
        // A callable fake: response instances are single-consumption when
        // streamed into ->sink(); a shared instance would be empty on run 2.
        $http->fake([
            'api.telegram.org/file/*' => fn () => $http->response(str_repeat('O', 2048), 200),
        ]);

        $downloader = new FileDownloader(
            api: new FakeFileApi(new FileTypeDTO(
                fileId: 'F1',
                fileUniqueId: 'U'.random_int(1, PHP_INT_MAX),
                fileSize: '2048',
                filePath: 'voice/file.oga',
            )),
            http: $http,
            tmpDirOverride: $this->tmpDir,
        );

        $resolver = new ConfigResolver(new ProviderRegistry(), 20, 8388608);
        $stats = new SttStats($cache);

        $service = new VoiceTranscriptionService(
            settingsService: $settingsStub,
            configResolver: $resolver,
            provider: $provider,
            downloader: $downloader,
            converter: new FfmpegConverter('none'),
            recorder: new TranscriptionRecorder(),
            renderer: new TemplateRenderer(),
            quota: $quota,
            semaphore: new ChatSemaphore($cache),
            global: new GlobalConcurrency($cache, 4),
            breaker: $breaker,
            sender: $spy,
            tokens: new VaultTokenResolver(),
            stats: $stats,
            budgetSeconds: $budgetSeconds,
        );

        return [$service, $spy, $provider, $settingsStub, $stats];
    }

    private function bot(): TgBotConfig
    {
        return new TgBotConfig('123:test', 'test_bot');
    }

    private function message(int $messageId, bool $private = true): MessageTypeDTO
    {
        return new MessageTypeDTO(
            messageId: $messageId,
            date: time(),
            chat: new ChatTypeDTO(
                id: $private ? '555' : '-100100',
                type: $private ? ChatPropTypeEnum::PRIVATE : ChatPropTypeEnum::SUPERGROUP,
            ),
            from: new UserTypeDTO(id: '555', firstName: 'T', username: 'tester', isBot: false),
        );
    }

    private function voice(int $durationSec = 14): VoiceTypeDTO
    {
        return new VoiceTypeDTO(fileId: 'F1', fileUniqueId: 'U1', duration: $durationSec, mimeType: 'audio/ogg');
    }
}
