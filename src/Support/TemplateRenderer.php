<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Support;

use BAGArt\TelegramBotStt\Settings\SttSettings;

/**
 * Applies {text}/{lang}/{dur} placeholders to the per-chat template and
 * produces the final HTML-safe reply body, clamped to the Telegram 4096
 * character limit (§2.3). Pure formatting — no I/O.
 */
final class TemplateRenderer
{
    public function render(
        SttSettings $settings,
        string $text,
        ?string $language,
        ?int $durationSec,
    ): string {
        $values = [
            '{text}' => htmlspecialchars($text, ENT_QUOTES, 'UTF-8'),
            '{lang}' => htmlspecialchars($language ?? '?', ENT_QUOTES, 'UTF-8'),
            '{dur}' => (string) ($durationSec ?? 0),
        ];

        $rendered = strtr($settings->template, $values);

        return mb_substr($rendered, 0, SttSettings::REPLY_MAX_CHARS);
    }

    public function usageHint(): string
    {
        return '💡 Reply to a voice message with /text.';
    }
}
