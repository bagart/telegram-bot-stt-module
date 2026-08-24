<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\I18n;

use BAGArt\TelegramBotStt\Provider\ErrorCode;

/**
 * ru/en string catalog (~§7 failure texts + panel labels). Values may contain
 * {placeholders}; callers are responsible for HTML-escaping substituted user
 * content (TemplateRenderer) — catalog strings are trusted markup.
 */
final class Strings
{
    /** @var array<string, array<string, string>> */
    private const CATALOG = [
        'ru' => [
            'panel.title' => '🎙 Текст из голосового',
            'panel.auto' => 'Автотранскрипция',
            'panel.provider' => 'Провайдер',
            'panel.language' => 'Язык (подсказка)',
            'panel.trigger' => 'Триггер в группах',
            'panel.template' => 'Шаблон ответа',
            'panel.onerror' => 'При ошибке',
            'panel.quota' => 'Лимит сегодня',
            'panel.close' => 'Закрыть',
            'panel.back' => '⬅️ Назад',
            'panel.denied_group' => '⛔️ Панель доступна админам чата с правом удаления сообщений.',
            'lang.auto' => 'авто',
            'trigger.all' => 'все голосовые',
            'trigger.reply_bot' => 'ответ боту',
            'trigger.mention' => 'упоминание бота',
            'error.silent' => '',
            'error.emoji' => '😕',
            'err.AUTH' => '🔒 Провайдер отклонил ключ. Проверьте токен в настройках.',
            'err.QUOTA_PROVIDER' => '🚦 Лимит провайдера исчерпан — попробуйте позже.',
            'err.RATE_LIMITED' => '🚦 Провайдер перегружен — попробуйте позже.',
            'err.BAD_REQUEST' => '⚙️ Провайдер настроен неверно.',
            'err.UNSUPPORTED_INPUT' => '📏 Голос слишком длинный или неподдерживаемого формата.',
            'err.PAYLOAD_TOO_LARGE' => '📦 Голос слишком большой для расшифровки.',
            'err.UNAVAILABLE' => '🛠 Расшифровка временно недоступна.',
            'err.EMPTY_RESULT' => '(ничего не удалось разобрать)',
            'usage.reply_voice' => '💡 Ответьте командой /text на голосовое сообщение.',
            'usage.no_voice' => 'Ответьте /text на голосовое сообщение, чтобы получить текст. Без ответа — настройки.',
            'notice.append' => "\n\nℹ️ Голосовые отправляются провайдеру «{provider}» для расшифровки.",
            'input.template' => "✍️ Отправьте новым сообщением шаблон ответа.\nПлейсхолдеры: <code>{text}</code>, <code>{lang}</code>, <code>{dur}</code>.\nОтмена: /text_cancel",
            'input.token' => "🔑 Отправьте API-ключ провайдера «{provider}» следующим сообщением.\nОн будет сохранён в зашифрованном виде.\nОтмена: /text_cancel",
            'input.json' => "🛠 Отредактируйте JSON и отправьте обратно следующим сообщением:\n<pre>{json}</pre>\nhttp разрешён только для локальных адресов. Отмена: /text_cancel",
            'saved.ok' => '✅ Сохранено',
            'cancelled' => 'Отменено',
        ],
        'en' => [
            'panel.title' => '🎙 Text from voice',
            'panel.auto' => 'Auto-transcribe',
            'panel.provider' => 'Provider',
            'panel.language' => 'Language hint',
            'panel.trigger' => 'Group trigger',
            'panel.template' => 'Reply template',
            'panel.onerror' => 'On error',
            'panel.quota' => 'Quota today',
            'panel.close' => 'Close',
            'panel.back' => '⬅️ Back',
            'panel.denied_group' => '⛔️ The panel is available to chat admins with the message-deletion right.',
            'lang.auto' => 'auto',
            'trigger.all' => 'all voice messages',
            'trigger.reply_bot' => 'reply to bot',
            'trigger.mention' => 'bot mention',
            'error.silent' => '',
            'error.emoji' => '😕',
            'err.AUTH' => '🔒 Provider rejected the key. Check the token in settings.',
            'err.QUOTA_PROVIDER' => '🚦 Provider quota exhausted — try again later.',
            'err.RATE_LIMITED' => '🚦 Provider is rate limiting us — try again later.',
            'err.BAD_REQUEST' => '⚙️ Provider is misconfigured.',
            'err.UNSUPPORTED_INPUT' => '📏 Voice is too long or in an unsupported format.',
            'err.PAYLOAD_TOO_LARGE' => '📦 Voice is too large to transcribe.',
            'err.UNAVAILABLE' => '🛠 Transcription is temporarily unavailable.',
            'err.EMPTY_RESULT' => '(nothing recognizable)',
            'usage.reply_voice' => '💡 Reply to a voice message with /text.',
            'usage.no_voice' => 'Reply /text to a voice message to transcribe it. Without a reply — settings.',
            'notice.append' => "\n\nℹ️ Voice messages are sent to provider “{provider}” for transcription.",
            'input.template' => "✍️ Send the reply template as your next message.\nPlaceholders: <code>{text}</code>, <code>{lang}</code>, <code>{dur}</code>.\nCancel: /text_cancel",
            'input.token' => "🔑 Send the “{provider}” API key as your next message.\nIt will be stored encrypted.\nCancel: /text_cancel",
            'input.json' => "🛠 Edit this JSON and send it back as your next message:\n<pre>{json}</pre>\nhttp is allowed only for local addresses. Cancel: /text_cancel",
            'saved.ok' => '✅ Saved',
            'cancelled' => 'Cancelled',
        ],
    ];

    public static function t(string $locale, string $key, array $replace = []): string
    {
        $line = self::CATALOG[$locale][$key]
            ?? self::CATALOG['en'][$key]
            ?? $key;

        foreach ($replace as $name => $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $line = str_replace('{'.$name.'}', (string) $value, $line);
        }

        return $line;
    }

    public static function errorText(string $locale, ErrorCode $code): string
    {
        return self::t($locale, 'err.'.$code->value);
    }
}
