<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Processing;

use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\Contracts\Outbound\TgSenderContract;
use BAGArt\TelegramBot\Contracts\Processing\Processors\TgModuleProcessorContract;
use BAGArt\TelegramBot\Contracts\TgApi\TgApiTypeDTOContract;
use BAGArt\TelegramBot\Modules\TgCommandRegistry;
use BAGArt\TelegramBot\Processing\BotProcessorContext;
use BAGArt\TelegramBot\Processing\ErrorHandling\ProcessorErrorContext;
use BAGArt\TelegramBot\TgApi\Methods\DTO\SendMessageMethodDTO;
use BAGArt\TelegramBot\TgApi\Methods\Enum\ParseModeEnum;
use BAGArt\TelegramBot\TgApi\Types\DTO\MessageTypeDTO;
use BAGArt\TelegramBotStt\Models\SttToken;
use BAGArt\TelegramBotStt\ModuleFactory;
use BAGArt\TelegramBotStt\Provider\ProviderRegistry;
use BAGArt\TelegramBotStt\Settings\SttSettings;
use BAGArt\TelegramBotStt\Ui\PendingInputService;
use Throwable;

/**
 * Consumes pending text inputs (template editor, token paste, custom-provider
 * JSON) from plain non-command messages — the Summarizer pending-input
 * pattern. support() stays cheap: one cache probe per text message, only for
 * messages carrying text from a user with an armed input flow.
 */
class PendingInputProcessor implements TgModuleProcessorContract
{
    private function __construct(
        private readonly ProviderRegistry $providers,
        private readonly TgSenderContract $sender,
    ) {}

    public static function moduleId(): string
    {
        return ModuleFactory::MODULE_ID;
    }

    public static function build(BotProcessorContext $context): self
    {
        return new self(ModuleFactory::providers(), $context->tgSender);
    }

    public function support(
        TgApiTypeDTOContract $dto,
        TgBotConfig $botConfig,
        ?string $action = null,
    ): bool {
        if (! ModuleFactory::inLaravel()
            || ! $dto instanceof MessageTypeDTO
            || $dto->text === null
            || $dto->from === null
            || TgCommandRegistry::parseCommandName($dto->text) !== null) {
            return false;
        }

        return ModuleFactory::pending()->peek(
            (string) $botConfig->botId,
            (int) $dto->chat->id,
            (int) $dto->from->id,
        );
    }

    public function isStrictOrdered(
        TgApiTypeDTOContract $dto,
        TgBotConfig $botConfig,
        ?string $action = null,
    ): bool {
        return false;
    }

    public function process(
        TgApiTypeDTOContract $dto,
        TgBotConfig $botConfig,
        ?string $action = null,
    ): void {
        assert($dto instanceof MessageTypeDTO);

        if (! ModuleFactory::inLaravel() || ! $dto instanceof MessageTypeDTO || $dto->from === null || $dto->text === null) {
            return;
        }

        $botId = (string) $botConfig->botId;
        $chatId = (int) $dto->chat->id;

        try {
            $pending = ModuleFactory::pending()->pop($botId, $chatId, (int) $dto->from->id);

            if ($pending === null) {
                return;
            }

            match ($pending['action']) {
                PendingInputService::ACTION_TEMPLATE => $this->applyTemplate($botConfig, $chatId, trim((string) $dto->text)),
                PendingInputService::ACTION_PROVIDER_JSON => $this->applyProviderJson($botConfig, $chatId, (string) $dto->text),
                PendingInputService::ACTION_TOKEN => $this->applyToken(
                    $botConfig,
                    $chatId,
                    (int) $dto->from->id,
                    $pending['payload'],
                    preg_replace('/\s+/', '', (string) $dto->text) ?? '',
                ),
                default => null,
            };
        } catch (Throwable $e) {
            report($e);

            $this->reply($botConfig, $chatId, '⚠️ STT input error');
        }
    }

    private function applyTemplate(TgBotConfig $botConfig, int $chatId, string $template): void
    {
        if ($template === '' || mb_strlen($template) > SttSettings::TEMPLATE_MAX_CHARS * 2) {
            $this->reply($botConfig, $chatId, '⚠️ Template is empty or too long. Press the button again.');

            return;
        }

        ModuleFactory::settings()->patch((string) $botConfig->botId, $chatId, [
            'template' => mb_substr($template, 0, SttSettings::TEMPLATE_MAX_CHARS),
        ]);

        $this->reply($botConfig, $chatId, '✅ Template saved');
    }

    private function applyProviderJson(TgBotConfig $botConfig, int $chatId, string $json): void
    {
        try {
            $validated = $this->providers->validateCustomConfig($json);
        } catch (\InvalidArgumentException $e) {
            $this->reply($botConfig, $chatId, '⚠️ '.htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));

            return;
        }

        ModuleFactory::settings()->patch((string) $botConfig->botId, $chatId, [
            'provider_key' => ProviderRegistry::CUSTOM_KEY,
            'custom_provider' => $validated,
        ]);

        $this->reply($botConfig, $chatId, '✅ Custom provider saved');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyToken(TgBotConfig $botConfig, int $chatId, int $creatorTgId, array $payload, string $token): void
    {
        $providerKey = (string) ($payload['provider_key'] ?? '');

        if ($providerKey === '' || ! $this->providers->has($providerKey)) {
            $this->reply($botConfig, $chatId, '⚠️ Unknown provider for token paste.');

            return;
        }

        if ($token === '') {
            $this->reply($botConfig, $chatId, '⚠️ Empty key. Press the button again.');

            return;
        }

        SttToken::query()->updateOrCreate(
            ['bot_id' => (string) $botConfig->botId, 'provider_key' => $providerKey],
            [
                // encrypted cast; the full value is never shown back in UI
                'token' => $token,
                'created_by_tg_id' => $creatorTgId,
            ],
        );

        $this->reply($botConfig, $chatId, '✅ Key stored ('.SttToken::mask($token).')');
    }

    private function reply(TgBotConfig $botConfig, int $chatId, string $text): void
    {
        $this->sender->send($botConfig, new SendMessageMethodDTO(
            chatId: (string) $chatId,
            text: $text,
            parseMode: ParseModeEnum::HTML,
        ));
    }

    public function onException(ProcessorErrorContext $context): void {}
}
