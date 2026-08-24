<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Tests\Unit;

use BAGArt\TelegramBotStt\Settings\SttSettings;
use BAGArt\TelegramBotStt\Support\TemplateRenderer;
use PHPUnit\Framework\TestCase;

final class TemplateRendererTest extends TestCase
{
    public function test_replaces_placeholders_and_escapes_html(): void
    {
        $settings = SttSettings::fromArray(['template' => "🎙 {text}\n— {lang} · {dur}s"], true);
        $out = (new TemplateRenderer)->render($settings, 'Привет <b>жирный</b>', 'ru', 14);

        self::assertSame("🎙 Привет &lt;b&gt;жирный&lt;/b&gt;\n— ru · 14s", $out);
    }

    public function test_truncates_to_telegram_4096_limit(): void
    {
        $settings = SttSettings::fromArray([], true);
        $out = (new TemplateRenderer)->render($settings, str_repeat('а', 9000), null, null);

        self::assertLessThanOrEqual(4096, mb_strlen($out));
        self::assertSame(4096, mb_strlen($out));
    }

    public function test_empty_text_is_rendered_as_given(): void
    {
        // EMPTY_RESULT is handled by the orchestrator before rendering; the
        // renderer itself must not swallow content silently.
        $settings = SttSettings::fromArray(['template' => '{text}'], true);
        self::assertSame('', (new TemplateRenderer)->render($settings, '', null, null));
    }
}
