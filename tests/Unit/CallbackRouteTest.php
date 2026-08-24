<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Tests\Unit;

use BAGArt\TelegramBotStt\Ui\CallbackRoute;
use PHPUnit\Framework\TestCase;

final class CallbackRouteTest extends TestCase
{
    public function test_roundtrip_encodes_and_decodes(): void
    {
        $data = CallbackRoute::encode(-1001234567890, CallbackRoute::VERB_SELECT_PROVIDER, 'groq-whisper-v3');

        self::assertSame('tc:-1001234567890:sst:groq-whisper-v3', $data);

        $route = CallbackRoute::decode($data);

        self::assertNotNull($route);
        self::assertSame(-1001234567890, $route['chatId']);
        self::assertSame('sst', $route['verb']);
        self::assertSame('groq-whisper-v3', $route['arg']);
    }

    public function test_arg_is_optional(): void
    {
        $route = CallbackRoute::decode(CallbackRoute::encode(42, CallbackRoute::VERB_CLOSE));

        self::assertNotNull($route);
        self::assertSame(42, $route['chatId']);
        self::assertSame('x', $route['verb']);
        self::assertNull($route['arg']);
    }

    public function test_rejects_foreign_prefixes_and_garbage(): void
    {
        foreach ([null, '', 'sm:1:m', 'tv:1:m', 'tc', 'tc:1', 'tc:abc:m', 'tc:0:m', 'tc:1:UPPER', 'tc:1:toolongverb'] as $bad) {
            self::assertNull(CallbackRoute::decode($bad), 'must reject: '.var_export($bad, true));
        }
    }

    public function test_64_byte_cap_holds_for_all_module_verbs_with_args(): void
    {
        // longest realistic chat id (supergroup) + longest verb + longest preset arg
        $chatId = -1009999999999;

        foreach ([
            [CallbackRoute::VERB_SELECT_PROVIDER, 'groq-whisper-turbo'],
            [CallbackRoute::VERB_SET_LANGUAGE, 'auto'],
            [CallbackRoute::VERB_SET_TRIGGER, 'reply_bot'],
            [CallbackRoute::VERB_SET_ERROR, 'message'],
        ] as [$verb, $arg]) {
            self::assertTrue(CallbackRoute::fits($chatId, $verb, $arg), "{$verb}:{$arg} must fit 64 bytes");
        }
    }
}
