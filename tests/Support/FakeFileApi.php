<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Tests\Support;

use BAGArt\ASKClient\Contracts\Pipeline\ASKFutureContract;
use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\Contracts\ApiCommunication\TgBotApiDTOClientContract;
use BAGArt\TelegramBot\Contracts\TgApi\TgApiMethodDTOContract;
use BAGArt\TelegramBot\Http\Pure\TgApiResponse;
use BAGArt\TelegramBot\TgApi\Methods\DTO\GetFileMethodDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\FileTypeDTO;

/**
 * Shared hand-written fakes for standalone unit tests (no Laravel app).
 * Strict-contract fakes only — no partial mocks.
 */
final class FakeFileApi implements TgBotApiDTOClientContract
{
    public function __construct(
        private readonly FileTypeDTO $file,
    ) {}

    public function request(TgBotConfig $botConfig, TgApiMethodDTOContract $dto, ?int $timeout = null): TgApiResponse
    {
        if ($dto instanceof GetFileMethodDTO) {
            return new TgApiResponse(ok: true, possibleResultTypes: [FileTypeDTO::class], result: $this->file);
        }

        return new TgApiResponse(ok: false, possibleResultTypes: [], result: null, errorCode: 400);
    }

    public function requestAsync(TgBotConfig $botConfig, TgApiMethodDTOContract $dto, ?int $timeout = null): ASKFutureContract
    {
        throw new \RuntimeException('not used in tests');
    }

    public function tick(int $systemPressure): void {}

    public function pressure(): int
    {
        return 0;
    }

    public function tickable(): array
    {
        return [];
    }
}
