<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Settings;

use BAGArt\TelegramBot\Contracts\Modules\ModuleEnablementContract;
use BAGArt\TelegramBot\Contracts\Modules\ModuleSettingsContract;
use BAGArt\TelegramBotManagement\Models\TgModuleEnablement;
use BAGArt\TelegramBotStt\SttModuleId;
use Illuminate\Support\Facades\DB;

/**
 * Reads effective STT settings and persists chat-level patches into the
 * module enablement row (same row drives is_enabled), busting caches through
 * ModuleEnablementContract::refresh() — Summarizer settings mechanics.
 */
class SttSettingsService
{
    public function __construct(
        private readonly ModuleSettingsContract $settings,
        private readonly ModuleEnablementContract $enablement,
    ) {}

    public function get(string $botId, int $chatId, bool $isPrivateChat): SttSettings
    {
        return SttSettings::fromArray(
            $this->settings->settingsFor(SttModuleId::ID, $botId, $chatId),
            $isPrivateChat,
        );
    }

    public function isEnabled(string $botId, int $chatId): bool
    {
        return $this->enablement->isEnabled(SttModuleId::ID, $botId, $chatId);
    }

    /**
     * @param  array<string, mixed>  $patch  settings keys to merge into the chat-level row
     */
    public function patch(string $botId, int $chatId, array $patch): void
    {
        DB::transaction(function () use ($botId, $chatId, $patch): void {
            $row = TgModuleEnablement::query()
                ->where('bot_id', $botId)
                ->where('chat_id', $chatId)
                ->where('module_id', SttModuleId::ID)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                $row = new TgModuleEnablement([
                    'bot_id' => $botId,
                    'chat_id' => $chatId,
                    'module_id' => SttModuleId::ID,
                    'is_enabled' => true,
                    'module_settings' => [],
                ]);
            }

            $current = is_array($row->module_settings) ? $row->module_settings : [];
            $row->module_settings = array_merge($current, $patch);

            if (array_key_exists('enabled', $patch)) {
                $row->is_enabled = (bool) $patch['enabled'];
            }

            $row->save();
        });

        $this->enablement->refresh($botId, $chatId);
    }
}
