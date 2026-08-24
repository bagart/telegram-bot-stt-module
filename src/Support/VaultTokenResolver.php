<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Support;

use BAGArt\TelegramBotStt\Models\SttToken;
use SensitiveParameter;
use Throwable;

/**
 * Vault read for the selected (bot, provider): the token is decrypted by the
 * Eloquent 'encrypted' cast only here and never logged or stored elsewhere
 * (§10.4).
 */
class VaultTokenResolver
{
    /**
     * Custom providers keep their inline token in the validated JSON config,
     * so a missing vault row is a normal null, not an error.
     */
    public function resolve(string $botId, #[SensitiveParameter] string $providerKey): ?string
    {
        try {
            $token = SttToken::query()
                ->where('bot_id', $botId)
                ->where('provider_key', $providerKey)
                ->value('token');

            return is_string($token) && $token !== '' ? $token : null;
        } catch (Throwable) {
            return null;
        }
    }
}
