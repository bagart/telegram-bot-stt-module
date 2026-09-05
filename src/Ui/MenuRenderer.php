<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Ui;

use BAGArt\TelegramBot\TgApi\Types\DTO\InlineKeyboardButtonTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\InlineKeyboardMarkupTypeDTO;
use BAGArt\TelegramBotStt\I18n\Strings;
use BAGArt\TelegramBotStt\Provider\ProviderRegistry;
use BAGArt\TelegramBotStt\Settings\SttSettings;
use BAGArt\TelegramBotStt\Ui\CallbackRoute as Route;

/**
 * Builds settings-panel texts + inline keyboards (§2.2). Pure formatting —
 * no I/O. Every callback payload must stay within the 64-byte cap.
 */
class MenuRenderer
{
    private const LANGUAGE_CHOICES = ['auto', 'ru', 'en', 'uk', 'de', 'es', 'fr', 'it', 'pl'];

    public function __construct(
        private readonly ProviderRegistry $providers,
    ) {
    }

    /**
     * @return array{text: string, keyboard: InlineKeyboardMarkupTypeDTO}
     */
    public function main(int $chatId, SttSettings $settings, bool $isPrivateChat): array
    {
        $t = fn (string $key, array $replace = []): string => Strings::t($settings->locale, $key, $replace);
        $providerName = $this->providerName($settings->providerKey);

        if ($isPrivateChat) {
            $text = '<b>'.$t('panel.title')."</b>\n"
                .$t('panel.auto').': '.($settings->autoEnabled ? '✅ ON' : '⛔️ OFF')."\n"
                .$t('panel.provider').": {$providerName}\n"
                .$t('panel.language').': '.$this->currentLanguage($settings)."\n"
                .$t('panel.template').': <code>'.htmlspecialchars(mb_substr($settings->template, 0, 40), ENT_QUOTES, 'UTF-8').'</code>';
        } else {
            $text = '<b>'.$t('panel.title')."</b>\n"
                .$t('panel.auto').': '.($settings->autoEnabled ? '✅ ON' : '⛔️ OFF')."\n"
                .$t('panel.provider').": {$providerName}\n"
                .$t('panel.trigger').': '.$t('trigger.'.$settings->groupTrigger)."\n"
                .$t('panel.onerror').': '.$settings->onError;
        }

        $rows = [
            [$this->button(
                ($settings->autoEnabled ? '⛔️ OFF' : '✅ ON'),
                $chatId,
                $settings->autoEnabled ? Route::VERB_AUTO_OFF : Route::VERB_AUTO_ON,
            )],
            [$this->button('🧠 '.$t('panel.provider'), $chatId, Route::VERB_PAGE_PROVIDERS)],
        ];

        if (! $isPrivateChat) {
            $rows[] = [$this->button('👥 '.$t('panel.trigger'), $chatId, Route::VERB_PAGE_TRIGGER)];
            $rows[] = [$this->button('🚦 '.$t('panel.onerror'), $chatId, Route::VERB_PAGE_ERROR)];
        } else {
            $rows[] = [$this->button('🌐 '.$t('panel.language'), $chatId, Route::VERB_PAGE_LANGUAGE)];
        }

        $rows[] = [$this->button('📝 '.$t('panel.template'), $chatId, Route::VERB_EDIT_TEMPLATE)];
        $rows[] = [$this->button('✖️ '.$t('panel.close'), $chatId, Route::VERB_CLOSE)];

        return [
            'text' => $text."\n<i>/text + reply to voice — transcribe.</i>",
            'keyboard' => new InlineKeyboardMarkupTypeDTO(inlineKeyboard: $rows),
        ];
    }

    /**
     * @return array{text: string, keyboard: InlineKeyboardMarkupTypeDTO}
     */
    public function providers(int $chatId, SttSettings $settings): array
    {
        $rows = [];

        foreach ($this->providers->all() as $preset) {
            $marker = $settings->providerKey === $preset->key ? ' ●' : '';
            $needsKey = $preset->needsToken ? ' 🔑' : '';
            $cost = $preset->costLabel !== '' ? " ({$preset->costLabel})" : '';
            $rows[] = [$this->button("{$preset->name}{$cost}{$needsKey}{$marker}", $chatId, Route::VERB_SELECT_PROVIDER, $preset->key)];
        }

        $customMarker = $settings->providerKey === ProviderRegistry::CUSTOM_KEY ? ' ●' : '';
        $rows[] = [$this->button('🛠 ✏️ custom JSON…'.$customMarker, $chatId, Route::VERB_CUSTOM_PROVIDER)];

        if ($settings->providerKey !== ProviderRegistry::CUSTOM_KEY && ! $this->providers->has($settings->providerKey)) {
            $rows[] = [$this->button('⚠️ unknown: '.$settings->providerKey, $chatId, Route::VERB_SELECT_PROVIDER, $settings->providerKey)];
        }

        $rows[] = [$this->back($chatId)];

        return [
            'text' => '🧠 <b>STT provider</b>'."\n"
                .$this->providerName($settings->providerKey)
                ."\n🔑 — needs an API key (paste once via «add token» after switching).",
            'keyboard' => new InlineKeyboardMarkupTypeDTO(inlineKeyboard: $rows),
        ];
    }

    /**
     * @return array{text: string, keyboard: InlineKeyboardMarkupTypeDTO}
     */
    public function language(int $chatId, SttSettings $settings): array
    {
        $rows = [];
        $row = [];
        $current = $this->currentLanguage($settings);

        foreach (self::LANGUAGE_CHOICES as $choice) {
            $marker = $current === $choice ? ' ●' : '';
            $row[] = $this->button(ucfirst($choice).$marker, $chatId, Route::VERB_SET_LANGUAGE, $choice);

            if (count($row) === 3) {
                $rows[] = $row;
                $row = [];
            }
        }

        if ($row !== []) {
            $rows[] = $row;
        }

        $rows[] = [$this->back($chatId)];

        return [
            'text' => '🌐 <b>'.Strings::t($settings->locale, 'panel.language')."</b>\n"
                .Strings::t($settings->locale, 'lang.auto').' — provider detects the spoken language.',
            'keyboard' => new InlineKeyboardMarkupTypeDTO(inlineKeyboard: $rows),
        ];
    }

    /**
     * @return array{text: string, keyboard: InlineKeyboardMarkupTypeDTO}
     */
    public function trigger(int $chatId, SttSettings $settings): array
    {
        $rows = [];

        foreach (SttSettings::GROUP_TRIGGERS as $trigger) {
            $marker = $settings->groupTrigger === $trigger ? ' ●' : '';
            $rows[] = [$this->button(Strings::t($settings->locale, 'trigger.'.$trigger).$marker, $chatId, Route::VERB_SET_TRIGGER, $trigger)];
        }

        $rows[] = [$this->back($chatId)];

        return [
            'text' => '👥 <b>'.Strings::t($settings->locale, 'panel.trigger').'</b>',
            'keyboard' => new InlineKeyboardMarkupTypeDTO(inlineKeyboard: $rows),
        ];
    }

    /**
     * @return array{text: string, keyboard: InlineKeyboardMarkupTypeDTO}
     */
    public function errorMode(int $chatId, SttSettings $settings): array
    {
        $labels = [SttSettings::ERROR_SILENT => '🤫 silent', SttSettings::ERROR_EMOJI => '😕 emoji', SttSettings::ERROR_MESSAGE => '💬 message'];
        $rows = [];

        foreach (SttSettings::ERROR_MODES as $mode) {
            $marker = $settings->onError === $mode ? ' ●' : '';
            $rows[] = [$this->button(($labels[$mode] ?? $mode).$marker, $chatId, Route::VERB_SET_ERROR, $mode)];
        }

        $rows[] = [$this->back($chatId)];

        return [
            'text' => '🚦 <b>'.Strings::t($settings->locale, 'panel.onerror').'</b>',
            'keyboard' => new InlineKeyboardMarkupTypeDTO(inlineKeyboard: $rows),
        ];
    }

    public function providerName(string $providerKey): string
    {
        if ($providerKey === ProviderRegistry::CUSTOM_KEY) {
            return 'custom JSON';
        }

        return $this->providers->get($providerKey)?->name ?? $providerKey.' (?)';
    }

    private function currentLanguage(SttSettings $settings): string
    {
        return $settings->language ?? 'auto';
    }

    private function button(string $label, int $chatId, string $verb, ?string $arg = null): InlineKeyboardButtonTypeDTO
    {
        return new InlineKeyboardButtonTypeDTO(
            text: $label,
            callbackData: Route::encode($chatId, $verb, $arg),
        );
    }

    private function back(int $chatId): InlineKeyboardButtonTypeDTO
    {
        return new InlineKeyboardButtonTypeDTO(text: '⬅️ Back', callbackData: Route::encode($chatId, Route::VERB_MENU));
    }
}
