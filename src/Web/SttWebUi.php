<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Web;

use BAGArt\TelegramBotMenu\Contracts\TgSettingsFormContract;
use BAGArt\TelegramBotMenu\Contracts\TgWebUiContract;
use BAGArt\TelegramBotMenu\Manifest\TgWebUiManifest;
use BAGArt\TelegramBotMenu\Manifest\UiAudience;
use BAGArt\TelegramBotMenu\Manifest\UiEntry;
use BAGArt\TelegramBotMenu\Manifest\UiField;
use BAGArt\TelegramBotMenu\Manifest\UiFieldType;
use BAGArt\TelegramBotMenu\Manifest\UiGroup;
use BAGArt\TelegramBotMenu\Manifest\UiKind;
use BAGArt\TelegramBotStt\Provider\ProviderRegistry;
use BAGArt\TelegramBotStt\Settings\SttSettings;
use BAGArt\TelegramBotStt\SttModuleId;
use InvalidArgumentException;

/**
 * Menu-hub settings surface for STT (menu_integration.md M-3a): the /text
 * in-chat panel mirrored as a declarative schema manifest + §8.3 settings
 * form. One class serves both contracts (§18: "the same or a separate
 * class"). API keys stay out of the schema by design — they live encrypted
 * in stt_tokens and are managed via the in-chat «add token» flow only
 * (custom provider JSON likewise stays in-chat for now).
 */
final readonly class SttWebUi implements TgSettingsFormContract, TgWebUiContract
{
    public function __construct(
        private ProviderRegistry $providers = new ProviderRegistry,
    ) {}

    public static function manifest(): TgWebUiManifest
    {
        return new TgWebUiManifest(
            moduleId: SttModuleId::ID,
            title: 'Voice → Text',
            icon: '🎙',
            kind: UiKind::Setting,
            minAudience: UiAudience::Admin,
            description: 'Transcribe voice messages',
            entry: UiEntry::schema([
                UiGroup::of('transcription', 'Transcription', [
                    UiField::bool('auto_enabled', 'Auto-transcribe', default: true),
                    UiField::enum('group_trigger', 'Group trigger', options: [
                        ['value' => SttSettings::TRIGGER_ALL, 'label' => 'All messages'],
                        ['value' => SttSettings::TRIGGER_REPLY_BOT, 'label' => 'On reply to bot'],
                        ['value' => SttSettings::TRIGGER_MENTION, 'label' => 'On mention'],
                    ], default: SttSettings::TRIGGER_ALL),
                    UiField::enum('language', 'Spoken language', options: [
                        ['value' => '', 'label' => 'Auto-detect'],
                        ['value' => 'ru', 'label' => 'Russian'],
                        ['value' => 'en', 'label' => 'English'],
                    ], default: ''),
                ]),
                UiGroup::of('output', 'Output', [
                    UiField::bool('reply_mode', 'Reply in thread', default: true),
                    new UiField('template', 'Message template', UiFieldType::Text, default: SttSettings::DEFAULT_TEMPLATE, extra: ['maxLength' => SttSettings::TEMPLATE_MAX_CHARS]),
                    UiField::enum('on_error', 'On error', options: [
                        ['value' => SttSettings::ERROR_SILENT, 'label' => 'Silent'],
                        ['value' => SttSettings::ERROR_EMOJI, 'label' => 'Emoji reaction'],
                        ['value' => SttSettings::ERROR_MESSAGE, 'label' => 'Error message'],
                    ], default: SttSettings::ERROR_EMOJI),
                ]),
                UiGroup::of('limits', 'Provider and limits', [
                    UiField::enum('provider_key', 'Provider', options: self::providerOptions(), default: 'groq-whisper-v3'),
                    new UiField('max_duration_sec', 'Max clip length (sec)', UiFieldType::Int, default: 120, extra: ['min' => 5, 'max' => 1200]),
                    new UiField('max_file_mb', 'Max file size (MB)', UiFieldType::Int, default: 19, extra: ['min' => 1, 'max' => 19]),
                    new UiField('daily_quota', 'Daily quota per chat', UiFieldType::Int, default: 50, extra: ['min' => 0, 'max' => 5000]),
                ]),
            ]),
            sortKey: 'stt',
            memberReadVisible: true,
        );
    }

    /** @return array<string, array<string, string>> */
    public static function translations(): array
    {
        return [
            'ru' => [
                'Voice → Text' => 'Голос → текст',
                'Transcribe voice messages' => 'Расшифровка голосовых сообщений',
                'Auto-transcribe' => 'Расшифровывать автоматически',
                'Group trigger' => 'Триггер в группах',
                'Spoken language' => 'Язык речи',
                'Reply in thread' => 'Отвечать в треде',
                'Message template' => 'Шаблон сообщения',
                'On error' => 'При ошибке',
                'Provider' => 'Провайдер',
                'Max clip length (sec)' => 'Макс. длина клипа (сек)',
                'Max file size (MB)' => 'Макс. размер файла (МБ)',
                'Daily quota per chat' => 'Дневная квота на чат',
            ],
        ];
    }

    /**
     * Schema keys → module_settings keys consumed by SttSettings::fromArray.
     * Unknown keys are dropped; values are normalized to the same clamps the
     * bot side applies (the DTO clamps again on read — belt and suspenders).
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function validate(array $raw): array
    {
        $patch = [];

        if (array_key_exists('auto_enabled', $raw)) {
            $patch['auto_enabled'] = (bool) $raw['auto_enabled'];
        }

        if (array_key_exists('group_trigger', $raw)) {
            $trigger = (string) $raw['group_trigger'];

            if (! in_array($trigger, SttSettings::GROUP_TRIGGERS, true)) {
                throw new InvalidArgumentException('Invalid group_trigger value.');
            }

            $patch['group_trigger'] = $trigger;
        }

        if (array_key_exists('language', $raw)) {
            $language = trim((string) $raw['language']);
            $patch['language'] = $language === '' ? null : strtolower(mb_substr($language, 0, 8));
        }

        if (array_key_exists('reply_mode', $raw)) {
            $patch['reply_mode'] = ((bool) $raw['reply_mode']) ? 'reply' : 'direct';
        }

        if (array_key_exists('template', $raw)) {
            $template = (string) $raw['template'];

            if (mb_strlen($template) > SttSettings::TEMPLATE_MAX_CHARS) {
                throw new InvalidArgumentException('Template is too long.');
            }

            $patch['template'] = $template;
        }

        if (array_key_exists('on_error', $raw)) {
            $onError = (string) $raw['on_error'];

            if (! in_array($onError, SttSettings::ERROR_MODES, true)) {
                throw new InvalidArgumentException('Invalid on_error value.');
            }

            $patch['on_error'] = $onError;
        }

        if (array_key_exists('provider_key', $raw)) {
            $providerKey = (string) $raw['provider_key'];

            if (! $this->providers->has($providerKey)) {
                throw new InvalidArgumentException('Unknown provider_key value.');
            }

            $patch['provider_key'] = $providerKey;
        }

        foreach (['max_duration_sec' => [5, 1200], 'max_file_mb' => [1, 19], 'daily_quota' => [0, 5000]] as $key => [$min, $max]) {
            if (array_key_exists($key, $raw)) {
                $patch[$key] = max($min, min($max, (int) $raw[$key]));
            }
        }

        return $patch;
    }

    /**
     * The module is operational out of the box (language auto-detect + the
     * default preset); missing API keys surface as transcription errors, not
     * a broken config, so the web surface never blocks on needs_setup.
     */
    public function isConfigured(array $settings): bool
    {
        return true;
    }

    /** @return list<array{value: string, label: string}> */
    private static function providerOptions(): array
    {
        $options = [];

        foreach ((new ProviderRegistry)->all() as $preset) {
            $options[] = ['value' => $preset->key, 'label' => $preset->name];
        }

        $options[] = ['value' => ProviderRegistry::CUSTOM_KEY, 'label' => 'Custom (self-hosted)'];

        return $options;
    }
}
