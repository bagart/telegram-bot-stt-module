<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Provider;

use BAGArt\TelegramBotStt\Provider\Dto\VoiceProviderConfig;
use BAGArt\TelegramBotStt\Settings\SttSettings;

/**
 * Resolves effective runtime provider configuration from chat settings,
 * the preset catalog and the vault token. The token is decrypted only here.
 */
class ConfigResolver
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly int $defaultTimeoutSeconds,
        private readonly int $defaultMaxResponseBytes,
    ) {
    }

    public function resolve(SttSettings $settings, ?string $tokenValue = null): VoiceProviderConfig
    {
        if ($settings->providerKey === ProviderRegistry::CUSTOM_KEY) {
            return $this->resolveCustom($settings->customProvider, $tokenValue);
        }

        $preset = $this->registry->get($settings->providerKey)
            ?? throw new ProviderException(ErrorCode::BadRequest, "Unknown STT provider '{$settings->providerKey}'");

        return new VoiceProviderConfig(
            key: $preset->key,
            apiStyle: $preset->apiStyle,
            baseUrl: $preset->baseUrl,
            token: $tokenValue,
            model: $preset->model,
            connectTimeoutSec: 10,
            timeoutSec: $this->defaultTimeoutSeconds,
            maxResponseBytes: $this->defaultMaxResponseBytes,
            containerFormat: $preset->containerFormat,
        );
    }

    /**
     * @param  array<string, mixed>|null  $custom  validated custom config (ProviderRegistry shape)
     */
    private function resolveCustom(?array $custom, ?string $tokenValue): VoiceProviderConfig
    {
        if ($custom === null) {
            throw new ProviderException(ErrorCode::BadRequest, 'Custom provider selected but not configured');
        }

        return new VoiceProviderConfig(
            key: ProviderRegistry::CUSTOM_KEY,
            apiStyle: SttApiStyle::from((string) ($custom['api_style'] ?? SttApiStyle::OpenaiStt->value)),
            baseUrl: (string) ($custom['base_url'] ?? ''),
            model: (string) ($custom['model'] ?? ''),
            token: is_string($custom['token'] ?? null) && $custom['token'] !== '' ? (string) $custom['token'] : $tokenValue,
            connectTimeoutSec: 10,
            timeoutSec: max(10, min(120, (int) ($custom['timeout_seconds'] ?? $this->defaultTimeoutSeconds))),
            maxResponseBytes: $this->defaultMaxResponseBytes,
            containerFormat: is_string($custom['audio_format'] ?? null) ? (string) $custom['audio_format'] : null,
        );
    }
}
