<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt;

use BAGArt\TelegramBot\Modules\TgModuleCapability;
use BAGArt\TelegramBot\Modules\TgModuleContract;
use BAGArt\TelegramBot\Modules\TgModuleDescriptor;
use BAGArt\TelegramBot\Modules\TgModuleRegistrar;
use BAGArt\TelegramBot\TgApi\Types\DTO\CallbackQueryTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\MessageTypeDTO;
use BAGArt\TelegramBotStt\Processing\MenuProcessor;
use BAGArt\TelegramBotStt\Processing\PendingInputProcessor;
use BAGArt\TelegramBotStt\Processing\TextCommandProcessor;
use BAGArt\TelegramBotStt\Processing\TranscribeProcessor;

/**
 * STT module (todo.stt.md): voice → threaded text via /text and an opt-in
 * auto mode. Disabled by default — nothing runs until a bot owner enables it
 * per bot/chat (`tg:module:enable stt`).
 */
class SttModule implements TgModuleContract
{
    public static function descriptor(): TgModuleDescriptor
    {
        return new TgModuleDescriptor(
            id: SttModuleId::ID,
            name: 'Text from Voice (STT)',
            version: '1.0.0',
            capabilities: [
                TgModuleCapability::Processor,
                TgModuleCapability::Command,
            ],
            defaultEnabled: false,
            failClosed: false,
        );
    }

    public static function register(TgModuleRegistrar $registrar): void
    {
        $registrar->processor(MessageTypeDTO::class, TranscribeProcessor::class);
        $registrar->processor(MessageTypeDTO::class, PendingInputProcessor::class);
        $registrar->processor(CallbackQueryTypeDTO::class, MenuProcessor::class);
        $registrar->command(TextCommandProcessor::NAME, TextCommandProcessor::class);
    }
}
