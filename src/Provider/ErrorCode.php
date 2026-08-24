<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Provider;

/**
 * Provider failure taxonomy (todo.stt.md §5). One code maps to exactly one
 * user string (I18n\Strings::errorText) and one metric label.
 */
enum ErrorCode: string
{
    case Auth = 'AUTH';
    case QuotaProvider = 'QUOTA_PROVIDER';
    case RateLimited = 'RATE_LIMITED';
    case BadRequest = 'BAD_REQUEST';
    case UnsupportedInput = 'UNSUPPORTED_INPUT';
    case Unavailable = 'UNAVAILABLE';
    case EmptyResult = 'EMPTY_RESULT';
    case PayloadTooLarge = 'PAYLOAD_TOO_LARGE';
}
