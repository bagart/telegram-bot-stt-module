<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Support;

use BAGArt\TelegramBotStt\Models\SttTranscription;
use BAGArt\TelegramBotStt\Provider\ErrorCode;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Dedupe serializer + history writer (§7bis steps 5/9). reserve() runs
 * firstOrCreate on the unique (bot_id, file_unique_id) index BEFORE any slow
 * work: Telegram webhook redelivery collapses into an instant cached reply.
 * A rare insert-race double reply is accepted (Q6).
 */
class TranscriptionRecorder
{
    /**
     * Returns [row, isNew]. When !isNew and status is ok/empty the caller
     * replays the stored text instead of calling the provider again.
     *
     * @return array{0: SttTranscription, 1: bool}
     */
    public function reserve(
        string $botId,
        int $chatId,
        int $messageId,
        string $fileUniqueId,
        string $providerKey,
    ): array {
        $existing = SttTranscription::query()
            ->where('bot_id', $botId)
            ->where('file_unique_id', $fileUniqueId)
            ->first();

        if ($existing !== null) {
            return [$existing, false];
        }

        try {
            $row = SttTranscription::query()->create([
                'bot_id' => $botId,
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'file_unique_id' => mb_substr($fileUniqueId, 0, 128),
                'provider_key' => $providerKey,
                'status' => SttTranscription::STATUS_FAILED,
                'error_code' => ErrorCode::Unavailable->value,
            ]);

            return [$row, true];
        } catch (Throwable) {
            // lost insert race against a parallel webhook delivery of the same voice
            $row = SttTranscription::query()
                ->where('bot_id', $botId)
                ->where('file_unique_id', $fileUniqueId)
                ->first()
                ?? throw new \RuntimeException('stt_transcriptions reserve failed twice');

            return [$row, false];
        }
    }

    public function storeOk(
        SttTranscription $row,
        string $text,
        string $providerKey,
        int $latencyMs,
        ?string $language = null,
    ): void {
        $this->updateRow($row, [
            'status' => SttTranscription::STATUS_OK,
            'result_text' => $text,
            'provider_key' => $providerKey,
            'latency_ms' => max(0, min(4294967295, $latencyMs)),
            'error_code' => null,
            'meta' => array_filter([
                'lang' => $language,
            ], static fn ($v): bool => $v !== null),
        ]);
    }

    public function storeEmpty(SttTranscription $row, string $providerKey): void
    {
        $this->updateRow($row, [
            'status' => SttTranscription::STATUS_EMPTY,
            'result_text' => null,
            'provider_key' => $providerKey,
            'error_code' => ErrorCode::EmptyResult->value,
        ]);
    }

    public function storeFailed(SttTranscription $row, string $providerKey, ErrorCode $code): void
    {
        $this->updateRow($row, [
            'status' => SttTranscription::STATUS_FAILED,
            'result_text' => null,
            'provider_key' => $providerKey,
            'error_code' => $code->value,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function updateRow(SttTranscription $row, array $attributes): void
    {
        DB::transaction(function () use ($row, $attributes): void {
            SttTranscription::query()
                ->whereKey($row->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            foreach ($attributes as $key => $value) {
                $row->{$key} = $value;
            }

            $row->save();
        });
    }
}
