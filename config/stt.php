<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| STT Module (bagart/telegram-bot-stt-module)
|--------------------------------------------------------------------------
|
| Voice-to-text module. Per-chat settings live in
| tg_module_enablements.module_settings; these are platform defaults and
| operational limits (todo.stt.md §13).
|
*/

return [
    // Telegram user ids allowed to manage any chat/provider config: "111,222"
    'superadmins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('STT_SUPERADMIN_TG_IDS', '')),
    ))),

    // Hard wall-clock budget for one transcribe() call (§7)
    'budget_seconds' => (int) env('STT_BUDGET_SECONDS', 30),

    // Global in-flight transcriptions across all chats (0 = unlimited)
    'global_concurrency' => (int) env('STT_GLOBAL_CONCURRENCY', 4),

    // ffmpeg binary; empty = auto-detect on PATH, "none" = force-disable
    'ffmpeg_path' => (string) env('STT_FFMPEG_PATH', ''),

    // Provider STT HTTP call timeout
    'timeout_seconds' => (int) env('STT_TIMEOUT_SECONDS', 20),

    // Provider response body cap
    'max_response_bytes' => (int) env('STT_MAX_RESPONSE_BYTES', 8388608),

    // Transcription history retention; also sweeps tmpfiles by mtime
    'retention_days' => (int) env('STT_RETENTION_DAYS', 30),

    // Downloaded voice tmpfile directory override (default: storage/framework/stt)
    'tmp_dir' => (string) env('STT_TMP_DIR', ''),

    // Pending text-input (token paste, template/JSON editor) lifetime in minutes
    'pending_input_ttl_minutes' => (int) env('STT_PENDING_INPUT_TTL', 15),
];
