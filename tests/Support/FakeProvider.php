<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Tests\Support;

use BAGArt\TelegramBotStt\Provider\Dto\SttRequest;
use BAGArt\TelegramBotStt\Provider\Dto\SttResult;
use BAGArt\TelegramBotStt\Provider\ErrorCode;
use BAGArt\TelegramBotStt\Provider\ProviderException;
use BAGArt\TelegramBotStt\Provider\SttProviderContract;

/**
 * Canned provider: counts calls, returns a fixed text or throws a fixed
 * taxonomy error.
 */
final class FakeProvider implements SttProviderContract
{
    public int $calls = 0;

    public function __construct(
        private readonly ?ProviderException $throw = null,
        private readonly string $text = 'расшифрованный текст',
    ) {
    }

    public function transcribe(SttRequest $request): SttResult
    {
        $this->calls++;

        if ($this->throw !== null) {
            throw $this->throw;
        }

        return new SttResult(
            text: $this->text,
            language: null,
            durationSec: null,
            providerKey: $request->provider->key,
            latencyMs: 123,
        );
    }

    public static function failing(ErrorCode $code): self
    {
        return new self(throw: new ProviderException($code, 'canned failure'));
    }
}
