<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Processing;

use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\Contracts\Processing\Processors\TgModuleProcessorContract;
use BAGArt\TelegramBot\Contracts\TgApi\TgApiTypeDTOContract;
use BAGArt\TelegramBot\Processing\BotProcessorContext;
use BAGArt\TelegramBot\Processing\ErrorHandling\ProcessorErrorContext;
use BAGArt\TelegramBot\TgApi\Types\DTO\MessageTypeDTO;
use BAGArt\TelegramBotStt\ModuleFactory;
use BAGArt\TelegramBotStt\Settings\SttSettings;

/**
 * Auto-mode transcription entry: any voice message in a chat where auto mode
 * is on (private default-on; groups opt-in with a trigger filter, §7bis).
 * Unexpected errors propagate to the router's ProcessorErrorContext — the
 * webhook path stays up (US5).
 */
class TranscribeProcessor implements TgModuleProcessorContract
{
    public function __construct(
        private readonly VoiceTranscriptionService $transcription,
    ) {}

    public static function moduleId(): string
    {
        return ModuleFactory::MODULE_ID;
    }

    public static function build(BotProcessorContext $context): self
    {
        return new self(ModuleFactory::transcription($context->tgSender));
    }

    public function support(
        TgApiTypeDTOContract $dto,
        TgBotConfig $botConfig,
        ?string $action = null,
    ): bool {
        return $dto instanceof MessageTypeDTO
            && $dto->voice !== null
            && $dto->from !== null
            && ! $dto->from->isBot
            && $this->autoModeAllows($botConfig, $dto);
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

        if ($dto->voice === null) {
            return;
        }

        $this->transcription->transcribe($botConfig, $dto, $dto->voice, 'auto');
    }

    public function onException(ProcessorErrorContext $context): void {}

    /**
     * Private chats: auto mode setting decides. Groups: opt-in + trigger
     * (all | reply_bot | mention) evaluated against the message shape.
     */
    private function autoModeAllows(TgBotConfig $botConfig, MessageTypeDTO $dto): bool
    {
        if (! ModuleFactory::inLaravel()) {
            return false;
        }

        $chatId = (int) $dto->chat->id;
        $isPrivate = $dto->chat->type?->value === 'private';

        $settings = ModuleFactory::settings()->get((string) $botConfig->botId, $chatId, $isPrivate);

        if (! $settings->autoEnabled) {
            return false;
        }

        return $isPrivate || $this->groupTriggerMatches($botConfig, $dto, $settings);
    }

    private function groupTriggerMatches(TgBotConfig $botConfig, MessageTypeDTO $dto, SttSettings $settings): bool
    {
        $replyTarget = $dto->replyToMessage;
        $replyToBot = $replyTarget !== null && (bool) $replyTarget->from?->isBot;
        $mentionsBot = str_contains(
            mb_strtolower($dto->text ?? ''),
            '@'.mb_strtolower((string) ($this->botUsername($botConfig) ?? "\0")),
        );

        return match ($settings->groupTrigger) {
            SttSettings::TRIGGER_ALL => true,
            SttSettings::TRIGGER_REPLY_BOT => $replyToBot,
            SttSettings::TRIGGER_MENTION => $mentionsBot || $replyToBot,
            default => false,
        };
    }

    private function botUsername(TgBotConfig $botConfig): ?string
    {
        return ModuleFactory::access()->botUsername($botConfig);
    }
}
