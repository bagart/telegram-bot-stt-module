<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Provider;

/** Named provider configuration row of the catalog (§3.1). */
final readonly class SttProviderPreset
{
    public function __construct(
        public string $key,
        public string $name,
        public string $baseUrl,
        public string $model,
        public SttApiStyle $apiStyle = SttApiStyle::OpenaiStt,
        public bool $needsToken = true,
        /** Short cost label shown in the picker ("free tier", "paid", …). */
        public string $costLabel = '',
        public ?string $containerFormat = null,
    ) {}
}
