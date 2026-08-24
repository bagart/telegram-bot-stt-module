<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Provider;

/**
 * Provider-side failure with a machine-readable taxonomy code. Messages must
 * never contain the provider token or the Telegram file URL (§10.4).
 */
final class ProviderException extends \RuntimeException
{
    public function __construct(
        public readonly ErrorCode $errorCode,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function because(ErrorCode $code, string $message, ?\Throwable $previous = null): self
    {
        return new self($code, $message, $previous);
    }
}
