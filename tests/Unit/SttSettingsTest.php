<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Tests\Unit;

use BAGArt\TelegramBotStt\Settings\SttSettings;
use PHPUnit\Framework\TestCase;

final class SttSettingsTest extends TestCase
{
    public function test_private_defaults_auto_on_message_errors_ru(): void
    {
        $s = SttSettings::fromArray([], isPrivateChat: true);

        self::assertTrue($s->autoEnabled);
        self::assertSame(SttSettings::ERROR_MESSAGE, $s->onError);
        self::assertSame('groq-whisper-v3', $s->providerKey);
        self::assertNull($s->language);
        self::assertTrue($s->replyThreaded);
    }

    public function test_group_defaults_auto_off_emoji_errors(): void
    {
        $s = SttSettings::fromArray([], isPrivateChat: false);

        self::assertFalse($s->autoEnabled);
        self::assertSame(SttSettings::ERROR_EMOJI, $s->onError);
        self::assertSame(SttSettings::TRIGGER_ALL, $s->groupTrigger);
    }

    public function test_clamps_out_of_range_values(): void
    {
        $s = SttSettings::fromArray([
            'max_duration_sec' => 100000,
            'max_file_mb' => 500,
            'daily_quota' => -5,
            'template' => str_repeat('x', 4000),
            'group_trigger' => 'nonsense',
            'on_error' => 'nonsense',
            'locale' => 'xx',
            'language' => '  RU-ru  ',
        ], isPrivateChat: false);

        self::assertSame(1200, $s->maxDurationSec);
        self::assertSame(19, $s->maxFileMb);
        self::assertSame(0, $s->dailyQuota);
        self::assertSame(512, mb_strlen($s->template));
        self::assertSame(SttSettings::TRIGGER_ALL, $s->groupTrigger);
        // private=false default error mode wins over garbage
        self::assertSame(SttSettings::ERROR_EMOJI, $s->onError);
        self::assertSame('ru', $s->locale);
        self::assertSame('ru-ru', $s->language);
    }

    public function test_unknown_provider_key_is_preserved_for_bad_request_surface(): void
    {
        $s = SttSettings::fromArray(['provider_key' => str_repeat('z', 200)], true);

        // clamped to column width; ConfigResolver maps unknown → BAD_REQUEST (§8)
        self::assertSame(64, mb_strlen($s->providerKey));
    }
}
