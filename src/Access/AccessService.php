<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Access;

use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\Contracts\ApiCommunication\TgBotApiDTOClientContract;
use BAGArt\TelegramBot\TgApi\Methods\DTO\GetChatAdministratorsMethodDTO;
use BAGArt\TelegramBot\TgApi\Methods\DTO\GetMeMethodDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\ChatMemberAdministratorTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\ChatMemberOwnerTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\UserTypeDTO;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Decides who may open the STT settings panel in a chat (В§2.2):
 * - private chat: the peer user manages their OWN chat settings (S9 - there
 *   are no admins in a private chat);
 * - groups: Telegram admins holding the "delete messages of others" right
 *   and platform superadmins (STT_SUPERADMIN_TG_IDS).
 *
 * The summarizer's "inviter" branch is intentionally absent: the module's DB
 * schema is fixed at two tables (В§11) and has no chat-access table.
 */
class AccessService
{
    private const ADMIN_LIST_TTL = 300;

    private const BOT_ID_TTL = 3600;

    public function __construct(
        private readonly TgBotApiDTOClientContract $api,
    ) {}

    public function isSuperadmin(int|string $userTgId): bool
    {
        $ids = $this->appReady('config') ? (array) config('stt.superadmins', []) : [];

        return in_array((string) $userTgId, array_map('strval', $ids), true);
    }

    public function canManage(TgBotConfig $botConfig, int $chatId, UserTypeDTO $user, bool $isPrivateChat): bool
    {
        if ($this->isSuperadmin($user->id)) {
            return true;
        }

        if ($isPrivateChat) {
            return (int) $user->id === $chatId;
        }

        return $this->hasTelegramDeleteRights($botConfig, $chatId, (int) $user->id);
    }

    /**
     * Live Telegram check (cached): member must be owner or an administrator
     * holding can_delete_messages. API failure fails closed for grants.
     */
    public function hasTelegramDeleteRights(TgBotConfig $botConfig, int $chatId, int $userTgId): bool
    {
        $admins = $this->administrators($botConfig, $chatId);

        if ($admins === null) {
            return false;
        }

        foreach ($admins as $member) {
            if ((int) $member->user->id !== $userTgId) {
                continue;
            }

            if ($member instanceof ChatMemberOwnerTypeDTO) {
                return true;
            }

            return $member instanceof ChatMemberAdministratorTypeDTO && $member->canDeleteMessages;
        }

        return false;
    }

    /**
     * Telegram user id of the bot itself (needed by the mention trigger).
     */
    public function botUserId(TgBotConfig $botConfig): ?string
    {
        return $this->rememberBotIdentity($botConfig)?->id;
    }

    /**
     * Bot's own @username without the "@" (mention trigger matching).
     */
    public function botUsername(TgBotConfig $botConfig): ?string
    {
        return $this->rememberBotIdentity($botConfig)?->username;
    }

    private function rememberBotIdentity(TgBotConfig $botConfig): ?UserTypeDTO
    {
        $cacheKey = 'stt:bot-user:'.sha1($botConfig->token);

        try {
            $cached = $this->cacheAvailable() ? Cache::get($cacheKey) : null;

            if ($cached instanceof UserTypeDTO) {
                return $cached;
            }
        } catch (Throwable) {
            // cache unavailable - fall through to a direct call
        }

        try {
            $response = $this->api->request($botConfig, new GetMeMethodDTO);

            if (! $response->ok || ! $response->result instanceof UserTypeDTO) {
                return null;
            }

            if ($this->cacheAvailable()) {
                Cache::put($cacheKey, $response->result, self::BOT_ID_TTL);
            }

            return $response->result;
        } catch (Throwable $e) {
            $this->warn('STT: getMe failed', ['exception' => $e::class]);

            return null;
        }
    }

    /**
     * @return list<ChatMemberOwnerTypeDTO|ChatMemberAdministratorTypeDTO>|null
     */
    private function administrators(TgBotConfig $botConfig, int $chatId): ?array
    {
        $cacheKey = sprintf('stt:admins:%s:%d', (string) $botConfig->botId, $chatId);

        try {
            $cached = $this->cacheAvailable() ? Cache::get($cacheKey) : null;

            if (is_array($cached)) {
                return $cached;
            }
        } catch (Throwable) {
            // cache unavailable - fetch without caching below
        }

        try {
            $response = $this->api->request($botConfig, new GetChatAdministratorsMethodDTO(chatId: (string) $chatId));
        } catch (Throwable $e) {
            $this->warn('STT: getChatAdministrators failed', [
                'chat_id' => $chatId,
                'exception' => $e::class,
            ]);

            return null;
        }

        if (! $response->ok || ! is_array($response->result)) {
            return null;
        }

        $members = [];

        foreach ($response->result as $member) {
            if ($member instanceof ChatMemberOwnerTypeDTO || $member instanceof ChatMemberAdministratorTypeDTO) {
                $members[] = $member;
            }
        }

        if ($this->cacheAvailable()) {
            Cache::put($cacheKey, $members, self::ADMIN_LIST_TTL);
        }

        return $members;
    }

    private function cacheAvailable(): bool
    {
        return $this->appReady('cache');
    }

    /** True when the app container has the given service (standalone-safe). */
    private function appReady(string $service): bool
    {
        try {
            return \function_exists('app') && app()->bound($service);
        } catch (Throwable) {
            return false;
        }
    }

    private function warn(string $message, array $context): void
    {
        if ($this->appReady('log')) {
            Log::warning($message, $context);
        }
    }
}
