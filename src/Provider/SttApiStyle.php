<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Provider;

/** Wire protocol family of a provider endpoint. New vendor = preset row. */
enum SttApiStyle: string
{
    case OpenaiStt = 'openai-stt';
}
