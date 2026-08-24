<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Tests\Support;

use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\Contracts\Outbound\TgSenderContract;
use BAGArt\TelegramBot\Contracts\TgApi\TgApiMethodDTOContract;
use BAGArt\TelegramBot\TgApi\Methods\DTO\SendMessageMethodDTO;

/** Collecting sender spy — asserts what WOULD go to Telegram. */
final class SenderSpy implements TgSenderContract
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
        $out = [];

        foreach ($this->sent as $dto) {
            if ($dto instanceof SendMessageMethodDTO) {
                $out[] = (string) $dto->text;
            }
        }

        return $out;
    }
}
