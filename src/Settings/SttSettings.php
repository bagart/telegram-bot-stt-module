<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Settings;

/**
 * Effective per-chat STT settings resolved from
 * tg_module_enablements.module_settings with platform defaults applied (§8).
 * All raw values are clamped here — the rest of the module trusts this DTO.
 */
final readonly class SttSettings
{
    public const DEFAULT_TEMPLATE = "🎙 {text}\n— {lang} · {dur}s";

    public const TEMPLATE_MAX_CHARS = 512;

    public const REPLY_MAX_CHARS = 4096;

    public const TRIGGER_ALL = 'all';

    public const TRIGGER_REPLY_BOT = 'reply_bot';

    public const TRIGGER_MENTION = 'mention';

    public const GROUP_TRIGGERS = [self::TRIGGER_ALL, self::TRIGGER_REPLY_BOT, self::TRIGGER_MENTION];

    public const ERROR_SILENT = 'silent';

    public const ERROR_EMOJI = 'emoji';

    public const ERROR_MESSAGE = 'message';

    public const ERROR_MODES = [self::ERROR_SILENT, self::ERROR_EMOJI, self::ERROR_MESSAGE];

    public const LOCALES = ['ru', 'en'];

    /**
     * @param  array<string, mixed>|null  $customProvider  validated custom provider config
     */
    public function __construct(
        public bool $autoEnabled,
        public string $groupTrigger,
        public string $providerKey,
        public ?string $language,
        public int $maxDurationSec,
        public int $maxFileMb,
        public string $template,
        public bool $replyThreaded,
        public string $onError,
        public int $dailyQuota,
        public string $locale,
        public bool $noticeShown,
        public ?array $customProvider,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw, bool $isPrivateChat): self
    {
        $trigger = (string) ($raw['group_trigger'] ?? self::TRIGGER_ALL);
        $onError = (string) ($raw['on_error'] ?? ($isPrivateChat ? self::ERROR_MESSAGE : self::ERROR_EMOJI));
        $locale = (string) ($raw['locale'] ?? 'ru');
        $language = $raw['language'] ?? null;
        $custom = is_array($raw['custom_provider'] ?? null) ? $raw['custom_provider'] : null;

        return new self(
            autoEnabled: (bool) ($raw['auto_enabled'] ?? $isPrivateChat),
            groupTrigger: in_array($trigger, self::GROUP_TRIGGERS, true) ? $trigger : self::TRIGGER_ALL,
            providerKey: mb_substr((string) ($raw['provider_key'] ?? 'groq-whisper-v3'), 0, 64),
            language: is_string($language) && trim($language) !== '' ? strtolower(mb_substr(trim($language), 0, 8)) : null,
            maxDurationSec: max(5, min(1200, (int) ($raw['max_duration_sec'] ?? 120))),
            maxFileMb: max(1, min(19, (int) ($raw['max_file_mb'] ?? 19))),
            template: mb_substr((string) ($raw['template'] ?? self::DEFAULT_TEMPLATE), 0, self::TEMPLATE_MAX_CHARS),
            replyThreaded: (($raw['reply_mode'] ?? 'reply') === 'reply'),
            onError: in_array($onError, self::ERROR_MODES, true) ? $onError : self::ERROR_EMOJI,
            dailyQuota: max(0, min(5000, (int) ($raw['daily_quota'] ?? 50))),
            locale: in_array($locale, self::LOCALES, true) ? $locale : 'ru',
            noticeShown: (bool) ($raw['notice_shown'] ?? false),
            customProvider: $custom,
        );
    }
}
