<?php

declare(strict_types=1);

use App\Models\User;
use BAGArt\ASKClient\Contracts\Pipeline\ASKFutureContract;
use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\Configs\TgServiceConfig;
use BAGArt\TelegramBot\Contracts\ApiCommunication\TgBotApiDTOClientContract;
use BAGArt\TelegramBot\Contracts\Modules\ModuleEnablementContract;
use BAGArt\TelegramBot\Contracts\Outbound\TgSenderContract;
use BAGArt\TelegramBot\Contracts\TgApi\TgApiMethodDTOContract;
use BAGArt\TelegramBot\Http\Pure\TgApiResponse;
use BAGArt\TelegramBot\Modules\TgCommandRegistry;
use BAGArt\TelegramBot\Modules\TgModuleRegistry;
use BAGArt\TelegramBot\Processing\RegisteredUpdateProcessorSelector;
use BAGArt\TelegramBot\TgApi\Methods\DTO\GetFileMethodDTO;
use BAGArt\TelegramBot\TgApi\Methods\DTO\SendMessageMethodDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\CallbackQueryTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\ChatTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\FileTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\MessageTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\UpdateTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\UserTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\VoiceTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\Enum\ChatPropTypeEnum;
use BAGArt\TelegramBot\TgBotSetupFactory;
use BAGArt\TelegramBotManagement\Models\TgBot;
use BAGArt\TelegramBotStt\Models\SttTranscription;
use BAGArt\TelegramBotStt\ModuleFactory;
use BAGArt\TelegramBotStt\Processing\TextCommandProcessor;
use BAGArt\TelegramBotStt\Processing\TranscribeProcessor;
use BAGArt\TelegramBotStt\Provider\Dto\SttRequest;
use BAGArt\TelegramBotStt\Provider\Dto\SttResult;
use BAGArt\TelegramBotStt\Provider\SttProviderContract;
use BAGArt\TelegramBotStt\Support\SttStats;
use BAGArt\TelegramBotStt\Ui\CallbackRoute;
use Illuminate\Http\Client\Factory;

/*
 * STT module E2E (RFC §14 Feature/E2E row): webhook-shaped voice updates
 * through the real settings/enablement/dedupe stack with a sender spy, a
 * canned provider and an HTTP-faked Telegram file download.
 */

beforeEach(function () {
    config('telegram.modules'); // force module config scan
    config(['stt.superadmins' => []]);
    config(['stt.tmp_dir' => sys_get_temp_dir().'/stt-e2e-'.bin2hex(random_bytes(4))]);

    $this->box = new class
    {
        public int $calls = 0;
    };

    $this->app->instance(TgBotApiDTOClientContract::class, sstFakeApiClient());
    $this->app->instance(SttProviderContract::class, sstFakeProvider($this->box));

    /** @var Factory $http */
    $http = app(Factory::class);
    $http->fake([
        // callable fake: streamed sink consumes the response body once per request
        'api.telegram.org/file/*' => fn () => $http->response(str_repeat('O', 1024), 200),
        '*' => fn () => $http->response(['ok' => true], 200),
    ]);

    TgBot::create(['bot_id' => 'test_bot', 'token' => '123:test']);

    ModuleFactory::settings()->patch('test_bot', 777, ['enabled' => true]);
});

afterEach(function () {
    if (is_dir((string) config('stt.tmp_dir'))) {
        @rmdir((string) config('stt.tmp_dir'));
    }
});

function sstFakeApiClient(): TgBotApiDTOClientContract
{
    return new class implements TgBotApiDTOClientContract
    {
        public function request(TgBotConfig $botConfig, TgApiMethodDTOContract $dto, ?int $timeout = null): TgApiResponse
        {
            if ($dto instanceof GetFileMethodDTO) {
                return new TgApiResponse(
                    ok: true,
                    possibleResultTypes: [FileTypeDTO::class],
                    result: new FileTypeDTO(fileId: 'F1', fileUniqueId: 'U1', fileSize: '1024', filePath: 'voice/file.oga'),
                );
            }

            return new TgApiResponse(ok: false, possibleResultTypes: [], result: null, errorCode: 400);
        }

        public function requestAsync(TgBotConfig $botConfig, TgApiMethodDTOContract $dto, ?int $timeout = null): ASKFutureContract
        {
            throw new RuntimeException('not used in tests');
        }

        public function tickable(): array
        {
            return [];
        }
    };
}

function sstFakeProvider(object $box): SttProviderContract
{
    return new class($box) implements SttProviderContract
    {
        public function __construct(
            private readonly object $box,
        ) {}

        public function transcribe(SttRequest $request): SttResult
        {
            $this->box->calls++;

            return new SttResult(
                text: 'привет из фейка',
                language: null,
                durationSec: null,
                providerKey: $request->provider->key,
                latencyMs: 40,
            );
        }
    };
}

function sstBotConfig(): TgBotConfig
{
    return new TgBotConfig(token: '123:test', botId: 'test_bot');
}

function sstUser(int $id): UserTypeDTO
{
    return new UserTypeDTO(id: (string) $id, isBot: false, firstName: 'Tester', username: 'tester');
}

function sstVoice(string $uniqueId = 'U1', int $duration = 14): VoiceTypeDTO
{
    return new VoiceTypeDTO(fileId: 'F1', fileUniqueId: $uniqueId, duration: $duration, mimeType: 'audio/ogg');
}

function sstPrivateVoice(int $userId, int $messageId, string $uniqueId = 'U1'): MessageTypeDTO
{
    return new MessageTypeDTO(
        messageId: $messageId,
        date: time(),
        chat: new ChatTypeDTO(id: (string) $userId, type: ChatPropTypeEnum::PRIVATE),
        from: sstUser($userId),
        voice: sstVoice($uniqueId),
    );
}

function sstGroupVoice(int $userId, int $messageId, string $uniqueId = 'U1'): MessageTypeDTO
{
    return new MessageTypeDTO(
        messageId: $messageId,
        date: time(),
        chat: new ChatTypeDTO(id: '-100100', type: ChatPropTypeEnum::SUPERGROUP),
        from: sstUser($userId),
        voice: sstVoice($uniqueId),
    );
}

function sstPrivateText(int $userId, string $text, ?MessageTypeDTO $replyTo = null): MessageTypeDTO
{
    return new MessageTypeDTO(
        messageId: random_int(1000, 9999),
        date: time(),
        chat: new ChatTypeDTO(id: (string) $userId, type: ChatPropTypeEnum::PRIVATE),
        from: sstUser($userId),
        text: $text,
        replyToMessage: $replyTo,
    );
}

function sstGroupText(int $userId, string $text, ?MessageTypeDTO $replyTo = null): MessageTypeDTO
{
    return new MessageTypeDTO(
        messageId: random_int(1000, 9999),
        date: time(),
        chat: new ChatTypeDTO(id: '-100100', type: ChatPropTypeEnum::SUPERGROUP),
        from: sstUser($userId),
        text: $text,
        replyToMessage: $replyTo,
    );
}

function sstSenderSpy(): TgSenderContract
{
    return new class implements TgSenderContract
    {
        /** @var list<TgApiMethodDTOContract> */
        public array $sent = [];

        public function send(TgBotConfig $botConfig, TgApiMethodDTOContract $dto): void
        {
            $this->sent[] = $dto;
        }

        /**
         * @return list<string>
         */
        public function texts(): array
        {
            return collect($this->sent)
                ->filter(fn ($dto) => $dto instanceof SendMessageMethodDTO)
                ->map(fn (SendMessageMethodDTO $dto) => (string) $dto->text)
                ->values()
                ->all();
        }
    };
}

function sstTranscriber(TgSenderContract $spy): TranscribeProcessor
{
    return new TranscribeProcessor(ModuleFactory::transcription($spy));
}

function sstTextCommand(TgSenderContract $spy): TextCommandProcessor
{
    return new TextCommandProcessor(ModuleFactory::transcription($spy), $spy);
}

it('discovers the stt module with /text registered and disabled by default', function () {
    expect(app(TgModuleRegistry::class)->has('stt'))->toBeTrue()
        ->and(app(TgModuleRegistry::class)->defaultEnabledOf('stt'))->toBeFalse()
        ->and(app(TgCommandRegistry::class)->has('text'))->toBeTrue();
});

it('transcribes a private auto-mode voice into a threaded reply with a one-time privacy notice (US1/US2)', function () {
    $spy = sstSenderSpy();

    sstTranscriber($spy)->process(sstPrivateVoice(777, 10), sstBotConfig());

    expect($this->box->calls)->toBe(1);

    $texts = $spy->texts();

    expect($texts)->toHaveCount(1)
        ->and($texts[0])->toContain('привет из фейка')
        ->and($texts[0])->toContain('ℹ️')
        ->and(SttTranscription::query()->where('bot_id', 'test_bot')->where('status', 'ok')->count())->toBe(1);

    // threading: reply targets the original voice message
    $reply = collect($spy->sent)->first(fn ($d) => $d instanceof SendMessageMethodDTO);
    expect($reply?->replyParameters?->messageId)->toBe(10);
});

it('collapses telegram redelivery into one provider call and replays the cached text', function () {
    $spy = sstSenderSpy();
    $processor = sstTranscriber($spy);

    $processor->process(sstPrivateVoice(777, 11), sstBotConfig());
    $processor->process(sstPrivateVoice(777, 12), sstBotConfig()); // same file_unique_id

    expect($this->box->calls)->toBe(1);

    $texts = $spy->texts();

    expect($texts)->toHaveCount(2)
        ->and($texts[1])->toContain('(cached)')
        ->and($texts[1])->not->toContain('ℹ️'); // privacy notice only once
});

it('renders the settings panel from bare /text in a private chat (US3)', function () {
    $spy = sstSenderSpy();

    sstTextCommand($spy)->process(sstPrivateText(777, '/text'), sstBotConfig());

    $texts = $spy->texts();

    expect($texts[0])->toContain('Текст из голосового');
});

it('shows a usage hint when /text replies to a non-voice message', function () {
    $target = new MessageTypeDTO(
        messageId: 5,
        date: time(),
        chat: new ChatTypeDTO(id: '777', type: ChatPropTypeEnum::PRIVATE),
        text: 'обычное сообщение',
    );

    $spy = sstSenderSpy();

    sstTextCommand($spy)->process(sstPrivateText(777, '/text', $target), sstBotConfig());

    expect($spy->texts()[0])->toContain('голосовое');
});

it('denies group panel to non-admins and keeps opt-out groups silent', function () {
    Cache::put('stt:admins:test_bot:-100100', [], 60);

    $spy = sstSenderSpy();

    sstTextCommand($spy)->process(sstGroupText(424242, '/text'), sstBotConfig());

    // group voice goes through the real selector: module not opted in there
    $update = new UpdateTypeDTO(updateId: 20, message: sstGroupVoice(424242, 21));

    $selector = new RegisteredUpdateProcessorSelector(
        serviceConfig: new TgServiceConfig,
        botSetup: app(TgBotSetupFactory::class)->create(serviceConfig: new TgServiceConfig),
        moduleEnablement: app(ModuleEnablementContract::class),
    );

    foreach ($selector->selectProcessors($update, sstBotConfig()) as $action => $processors) {
        foreach ($processors as $processor) {
            $processor->process($update->message, sstBotConfig(), $action);
        }
    }

    expect($spy->texts()[0])->toContain('админ')
        ->and($spy->texts())->toHaveCount(1)
        ->and($this->box->calls)->toBe(0);
});

it('enforces the daily quota across different voices', function () {
    ModuleFactory::settings()->patch('test_bot', 777, ['daily_quota' => 1]);
    $spy = sstSenderSpy();
    $processor = sstTranscriber($spy);

    $processor->process(sstPrivateVoice(777, 30, uniqueId: 'QA'), sstBotConfig());
    $processor->process(sstPrivateVoice(777, 31, uniqueId: 'QB'), sstBotConfig());

    expect($this->box->calls)->toBe(1)
        ->and(collect($spy->texts())->first(fn ($t) => str_contains((string) $t, 'Лимит')))->not->toBeNull();
});

it('routes the panel toggle callback through the selector (US2)', function () {
    $query = new CallbackQueryTypeDTO(
        id: 'cbq1',
        from: sstUser(777),
        chatInstance: 'ci',
        data: CallbackRoute::encode(777, CallbackRoute::VERB_AUTO_OFF),
    );

    $update = new UpdateTypeDTO(updateId: 9, callbackQuery: $query);

    $selector = new RegisteredUpdateProcessorSelector(
        serviceConfig: new TgServiceConfig,
        botSetup: app(TgBotSetupFactory::class)->create(serviceConfig: new TgServiceConfig),
        moduleEnablement: app(ModuleEnablementContract::class),
    );

    foreach ($selector->selectProcessors($update, sstBotConfig()) as $action => $processors) {
        foreach ($processors as $processor) {
            $processor->process($update->callbackQuery, sstBotConfig(), $action);
        }
    }

    expect(ModuleFactory::settings()->get('test_bot', 777, true)->autoEnabled)->toBeFalse();
});

it('exposes stt_* series through the /health/metrics hook (R1)', function () {
    SttStats::forCurrentStore()?->incTotal('metrics_bot', 'groq-whisper-v3', 'ok');
    SttStats::forCurrentStore()?->incQuotaBlocked('metrics_bot');
    SttStats::forCurrentStore()?->recordLatency('groq-whisper-v3', 700);

    $user = User::factory()->create();

    $this->actingAs($user)->get('/health/metrics')
        ->assertOk()
        ->assertSee('stt_total{bot_id="metrics_bot",provider="groq-whisper-v3",status="ok"} 1', false)
        ->assertSee('stt_quota_blocked_total{bot_id="metrics_bot"} 1', false)
        ->assertSee('stt_latency_bucket{provider="groq-whisper-v3",le="1000"} 1', false)
        ->assertSee('stt_breaker{provider=', false);
});
