<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Dedupe cache + transcription history. The unique (bot_id, file_unique_id)
 * index is the at-least-once redelivery serializer (§7bis): the row is
 * reserved BEFORE any slow work, collapsing Telegram duplicates.
 */
class SttTranscription extends Model
{
    use HasUuids;

    public const STATUS_OK = 'ok';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EMPTY = 'empty';

    protected $fillable = [
        'bot_id',
        'chat_id',
        'message_id',
        'file_unique_id',
        'provider_key',
        'status',
        'error_code',
        'result_text',
        'latency_ms',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
