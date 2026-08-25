<?php

declare(strict_types=1);

use BAGArt\TelegramBotManagement\Models\TgBot;
use BAGArt\TelegramBotStt\Models\SttTranscription;
use Illuminate\Support\Facades\DB;

/*
 * stt:prune retention sweep (RFC §10.5): old transcription rows and stale
 * tmpfiles are removed; fresh rows survive.
 */

beforeEach(function () {
    config(['stt.retention_days' => 30]);
    config(['stt.tmp_dir' => sys_get_temp_dir().'/stt-prune-'.bin2hex(random_bytes(4))]);
    @mkdir((string) config('stt.tmp_dir'), 0770, true);

    TgBot::create(['bot_id' => 'test_bot', 'token' => '123:test']);
});

afterEach(function () {
    foreach ((array) (scandir((string) config('stt.tmp_dir')) ?: []) as $entry) {
        if (! in_array($entry, ['.', '..'], true)) {
            @unlink(config('stt.tmp_dir').DIRECTORY_SEPARATOR.$entry);
        }
    }

    @rmdir((string) config('stt.tmp_dir'));
});

function sstPruneRow(string $uniqueId, string $createdAt): void
{
    DB::table('stt_transcriptions')->insert([
        'id' => (string) Illuminate\Support\Str::uuid(),
        'bot_id' => 'test_bot',
        'chat_id' => 777,
        'message_id' => random_int(1, 99999),
        'file_unique_id' => $uniqueId,
        'provider_key' => 'groq-whisper-v3',
        'status' => 'ok',
        'result_text' => 'x',
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

it('prunes stale transcriptions and tmpfiles but keeps fresh rows', function () {
    sstPruneRow('old-1', now()->subDays(40)->toDateTimeString());
    sstPruneRow('fresh-1', now()->subDays(2)->toDateTimeString());

    $staleTmp = config('stt.tmp_dir').DIRECTORY_SEPARATOR.'voice-stale.ogg';
    file_put_contents($staleTmp, 'x');
    touch($staleTmp, time() - 86400 * 40); // older than the 30-day window

    $this->artisan('stt:prune')->assertExitCode(0);

    expect(SttTranscription::query()->where('file_unique_id', 'old-1')->exists())->toBeFalse()
        ->and(SttTranscription::query()->where('file_unique_id', 'fresh-1')->exists())->toBeTrue()
        ->and(is_file($staleTmp))->toBeFalse();
});
