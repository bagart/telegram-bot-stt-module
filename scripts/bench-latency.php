<?php

/**
 * STT latency bench (todo.stt.md R4): sends an audio file to a configured
 * OpenAI-compatible endpoint N times and reports a latency profile.
 *
 * Real network calls — run manually, never in CI.
 *
 * Usage:
 *   php scripts/bench-latency.php \
 *     --url=https://api.groq.com/openai/v1 \
 *     --token=gsk_... \
 *     --model=whisper-large-v3 \
 *     --file=/path/to/voice.ogg \
 *     --iterations=5
 */

declare(strict_types=1);

require __DIR__.'/../../../../vendor/autoload.php';

use BAGArt\TelegramBotStt\Provider\Adapter\OpenAiCompatibleStt;
use BAGArt\TelegramBotStt\Provider\Dto\SttRequest;
use BAGArt\TelegramBotStt\Provider\Dto\VoiceProviderConfig;
use BAGArt\TelegramBotStt\Provider\ProviderException;
use BAGArt\TelegramBotStt\Provider\SttApiStyle;

$options = getopt('', ['url:', 'token:', 'model:', 'file:', 'iterations::']);

$baseUrl = (string) ($options['url'] ?? '');
$token = (string) ($options['token'] ?? '');
$model = (string) ($options['model'] ?? 'whisper-large-v3');
$file = (string) ($options['file'] ?? '');
$iterations = max(1, min(50, (int) ($options['iterations'] ?? 5)));

foreach ([['--url', $baseUrl], ['--file', $file]] as [$flag, $value]) {
    if ($value === '') {
        fwrite(STDERR, "Missing required option {$flag}\n");

        exit(2);
    }
}

if (! is_file($file)) {
    fwrite(STDERR, "File not found: {$file}\n");

    exit(2);
}

$config = new VoiceProviderConfig(
    key: 'bench',
    apiStyle: SttApiStyle::OpenaiStt,
    baseUrl: $baseUrl,
    token: $token !== '' ? $token : null,
    model: $model,
    connectTimeoutSec: 10,
    timeoutSec: 60,
    maxResponseBytes: 8388608,
);

$adapter = new OpenAiCompatibleStt;
$request = new SttRequest(
    audioPath: $file,
    mimeType: mime_content_type($file) ?: 'audio/ogg',
    durationSec: null,
    languageHint: null,
    provider: $config,
);

printf("bench: %s × %d → %s (%s)\n", basename($file), $iterations, $baseUrl, $model);
printf("%4s  %10s  %8s  %s\n", '#', 'latency_ms', 'status', 'note');

/** @var list<float> $ok */
$ok = [];
$failed = 0;

for ($i = 1; $i <= $iterations; $i++) {
    try {
        $result = $adapter->transcribe($request);
        $ok[] = $result->latencyMs;
        printf("%4d  %10d  %8s  %s\n", $i, $result->latencyMs, 'ok', mb_substr($result->text, 0, 40));
    } catch (ProviderException $e) {
        $failed++;
        printf("%4d  %10s  %8s  %s\n", $i, '-', $e->errorCode->value, $e->getMessage());
    }
}

if ($ok === []) {
    fwrite(STDERR, "\nAll iterations failed — nothing to profile.\n");

    exit(5);
}

sort($ok);
$p = fn (float $q): float => (float) $ok[min(count($ok) - 1, (int) ceil($q * count($ok)) - 1)];

printf(
    "\nprofile: n=%d ok=%d failed=%d min=%.0fms p50=%.0fms p95=%.0fms max=%.0fms\n",
    $iterations,
    count($ok),
    $failed,
    $ok[0],
    $p(0.5),
    $p(0.95),
    end($ok),
);

exit(0);
