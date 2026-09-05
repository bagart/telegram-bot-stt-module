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
use BAGArt\TelegramBotStt\I18n\Strings;
use BAGArt\TelegramBotStt\ModuleFactory;

/**
 * "/text" — dual purpose (S8): replied to a voice (or carrying one) →
 * transcribe; bare (no voice) → settings panel. Group panel is admin-gated;
 * in a private chat the peer manages their own settings (S9).
 * "/text_cancel" aborts a pending text-input flow.
 */
class TextCommandProcessor implements TgModuleProcessorContract
{
    public const NAME = 'text';

    public const CANCEL_NAME = 'text_cancel';

    public function __construct(
        private readonly VoiceTranscriptionService $transcription,
        private readonly TgSenderContract $sender,
    ) {
    }

    public static function moduleId(): string
    {
        return ModuleFactory::MODULE_ID;
    }

    public static function build(BotProcessorContext $context): self
    {
        return new self(ModuleFactory::transcription($context->tgSender), $context->tgSender);
    }

    public function support(
        TgApiTypeDTOContract $dto,
        TgBotConfig $botConfig,
        ?string $action = null,
    ): bool {
        return $dto instanceof MessageTypeDTO
            && $dto->text !== null
            && in_array(TgCommandRegistry::parseCommandName($dto->text), [self::NAME, self::CANCEL_NAME], true);
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

        if (! ModuleFactory::inLaravel() || $dto->from === null) {
            return;
        }

        $command = TgCommandRegistry::parseCommandName((string) $dto->text);

        if ($command === self::CANCEL_NAME) {
            ModuleFactory::pending()->cancel(
                (string) $botConfig->botId,
                (int) $dto->chat->id,
                (int) $dto->from->id,
            );
            $this->sendLocalized($botConfig, $dto, 'cancelled');

            return;
        }

        // /text replied to a voice → transcribe that voice (US1). The
        // reply_to_message DTO carries the full voice object.
        if ($dto->replyToMessage?->voice !== null) {
            $this->transcription->transcribe($botConfig, $dto, $dto->replyToMessage->voice, 'command');

            return;
        }

        if ($dto->voice !== null) {
            $this->transcription->transcribe($botConfig, $dto, $dto->voice, 'command');

            return;
        }

        // Replied to a NON-voice message → usage hint (§2.1); truly bare /text → panel.
        if ($dto->replyToMessage !== null) {
            $this->sendLocalized($botConfig, $dto, 'usage.reply_voice');

            return;
        }

        $this->openPanel($botConfig, $dto);
    }

    private function openPanel(TgBotConfig $botConfig, MessageTypeDTO $dto): void
    {
        $chatId = (int) $dto->chat->id;
        $isPrivate = $dto->chat->type?->value === 'private';

        if (! ModuleFactory::access()->canManage($botConfig, $chatId, $dto->from, $isPrivate)) {
            $this->sendLocalized($botConfig, $dto, 'panel.denied_group');

            return;
        }

        $settings = ModuleFactory::settings()->get((string) $botConfig->botId, $chatId, $isPrivate);
        $page = ModuleFactory::menuRenderer()->main($chatId, $settings, $isPrivate);

        $this->sender->send($botConfig, new SendMessageMethodDTO(
            chatId: (string) $chatId,
            text: $page['text'],
            parseMode: ParseModeEnum::HTML,
            replyMarkup: $page['keyboard'],
        ));
    }

    /**
     * Misplaced /text (no voice, no panel access context) — short usage hint (Q3: no limiter in v1).
     */
    private function sendLocalized(TgBotConfig $botConfig, MessageTypeDTO $dto, string $catalogKey): void
    {
        $locale = ModuleFactory::settings()
            ->get((string) $botConfig->botId, (int) $dto->chat->id, $dto->chat->type?->value === 'private')
            ->locale;

        $this->sender->send($botConfig, new SendMessageMethodDTO(
            chatId: (string) $dto->chat->id,
            text: Strings::t($locale, $catalogKey),
            parseMode: ParseModeEnum::HTML,
        ));
    }

    public function onException(ProcessorErrorContext $context): void
    {
    }
}
