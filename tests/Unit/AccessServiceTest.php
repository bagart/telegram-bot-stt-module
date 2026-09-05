<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Tests\Unit;

use BAGArt\ASKClient\Contracts\Pipeline\ASKFutureContract;
use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\Contracts\ApiCommunication\TgBotApiDTOClientContract;
use BAGArt\TelegramBot\Contracts\TgApi\TgApiMethodDTOContract;
use BAGArt\TelegramBot\Http\Pure\TgApiResponse;
use BAGArt\TelegramBot\TgApi\Types\DTO\UserTypeDTO;
use BAGArt\TelegramBotStt\Access\AccessService;
use PHPUnit\Framework\TestCase;

final class AccessServiceTest extends TestCase
{
    public function test_private_chat_peer_manages_own_settings(): void
    {
        $service = new AccessService(new NullApiClient());
        $user = $this->user(555);

        self::assertTrue($service->canManage($this->botConfig(), 555, $user, isPrivateChat: true));
    }

    public function test_private_chat_other_user_is_denied(): void
    {
        $service = new AccessService(new NullApiClient());

        self::assertFalse($service->canManage($this->botConfig(), 555, $this->user(777), isPrivateChat: true));
    }

    public function test_group_member_without_telegram_check_is_denied(): void
    {
        // NullApiClient never returns admin lists → fail closed for grants
        $service = new AccessService(new NullApiClient());

        self::assertFalse($service->canManage($this->botConfig(), -100999, $this->user(555), isPrivateChat: false));
    }

    private function botConfig(): TgBotConfig
    {
        return new TgBotConfig('123456789:AAExampleTokenExampleTokenExampleTok', '123456789');
    }

    private function user(int $id): UserTypeDTO
    {
        return new UserTypeDTO(
            id: (string) $id,
            firstName: 'Test',
            username: 'user'.$id,
            isBot: false,
        );
    }
}

/**
 * API client that never answers — models a dead Telegram API; privilege
 * grants must fail closed.
 */
final class NullApiClient implements TgBotApiDTOClientContract
{
    public function request(TgBotConfig $botConfig, TgApiMethodDTOContract $dto, ?int $timeout = null): TgApiResponse
    {
        throw new \RuntimeException('network disabled in unit tests');
    }

    public function requestAsync(TgBotConfig $botConfig, TgApiMethodDTOContract $dto, ?int $timeout = null): ASKFutureContract
    {
        throw new \RuntimeException('network disabled in unit tests');
    }

    public function tick(int $systemPressure): void
    {
    }

    public function pressure(): int
    {
        return 0;
    }

    public function tickable(): array
    {
        return [];
    }
}
