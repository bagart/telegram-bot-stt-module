<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Provider;

use BAGArt\TelegramBotStt\Provider\Dto\SttRequest;
use BAGArt\TelegramBotStt\Provider\Dto\SttResult;

/**
 * One transcription call against a configured provider. Implementations must
 * throw ProviderException with a taxonomy code instead of leaking transport
 * details, and must never include the token in messages or exceptions.
 */
interface SttProviderContract
{
    /** @throws ProviderException */
    public function transcribe(SttRequest $request): SttResult;
}
