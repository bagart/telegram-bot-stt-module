<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use SensitiveParameter;

/**
 * STT provider API token owned by a bot. Full value is only readable
 * server-side (ConfigResolver) for outgoing provider calls; UI must use
 * masked(). One token per (bot, provider) — repasting overwrites.
 */
class SttToken extends Model
{
    use HasUuids;

    protected $fillable = [
        'bot_id',
        'provider_key',
        'token',
        'created_by_tg_id',
        'created_by_username',
    ];

    /** @var list<string> */
    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'created_by_tg_id' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public static function mask(
        #[SensitiveParameter]
        string $token,
    ): string {
        $length = mb_strlen($token);

        if ($length <= 8) {
            return str_repeat('•', $length);
        }

        return mb_substr($token, 0, 4).'…'.mb_substr($token, -4);
    }
}
