<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Tests\Unit;

use BAGArt\TelegramBotStt\Provider\Adapter\OpenAiCompatibleStt;
use BAGArt\TelegramBotStt\Provider\ConfigResolver;
use BAGArt\TelegramBotStt\Provider\Dto\SttRequest;
use BAGArt\TelegramBotStt\Provider\Dto\SttResult;
use BAGArt\TelegramBotStt\Provider\Dto\VoiceProviderConfig;
use BAGArt\TelegramBotStt\Provider\ErrorCode;
use BAGArt\TelegramBotStt\Provider\ProviderException;
use BAGArt\TelegramBotStt\Provider\ProviderRegistry;
use BAGArt\TelegramBotStt\Provider\SttApiStyle;
use BAGArt\TelegramBotStt\Settings\SttSettings;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\TestCase;

/**
 * Wire-level verification against a REAL loopback HTTP socket, complementing
 * the Http::fake() unit matrix: proves the actual Guzzle multipart encoding,
 * Bearer transmission, JSON parsing and the SSRF guard's loopback exception
 * behave correctly end-to-end — the same wire path a self-hosted Whisper box
 * or Groq sees in production.
 *
 * The server is a forked child owning a socket bound before pcntl_fork(), so
 * the port is live the moment tests start; requests are answered with an
 * explicit Content-Length and Connection: close. What the child parsed out of
 * the raw request lands in a capture file the parent asserts against.
 */
final class LoopbackWireTest extends TestCase
{
    private const AUDIO = __DIR__.'/Fixtures/voice.ogg';

    private const TOKEN = 'loopback-secret';

    private const MAX_REQUESTS = 12;

    private static int $port = 0;

    private static int $childPid = 0;

    private static string $capturePath = '';

    public static function setUpBeforeClass(): void
    {
        if (! \function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl extension is required for the loopback wire test');
        }

        if (! is_dir(\dirname(self::AUDIO))) {
            mkdir(\dirname(self::AUDIO), 0777, true);
        }

        file_put_contents(self::AUDIO, 'RIFF-fake-ogg-bytes');

        self::$capturePath = sys_get_temp_dir().'/stt-loopback-capture-'.getmypid().'.json';
        @unlink(self::$capturePath);

        $listen = stream_socket_server('tcp://127.0.0.1:0');

        if ($listen === false) {
            self::fail('cannot bind a loopback socket');
        }

        $name = (string) stream_socket_get_name($listen, false);
        self::$port = (int) substr($name, (int) strrpos($name, ':') + 1);

        $pid = pcntl_fork();

        if ($pid === -1) {
            self::fail('cannot fork the loopback STT server');
        }

        if ($pid === 0) {
            self::serveUntilDone($listen);
            posix_kill(posix_getpid(), SIGKILL);
        }

        self::$childPid = $pid;
        fclose($listen);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$childPid !== 0) {
            posix_kill(self::$childPid, SIGKILL);
            pcntl_waitpid(self::$childPid, $status);
            self::$childPid = 0;
        }

        @unlink(self::$capturePath);
    }

    public function test_adapter_round_trip_over_real_socket(): void
    {
        $result = $this->transcribe(token: self::TOKEN);

        self::assertSame('лупбэк ок', $result->text);
        self::assertSame('loopback', $result->providerKey);

        $capture = $this->capture();

        self::assertSame('Bearer '.self::TOKEN, $capture['authorization']);
        self::assertSame('whisper-large-v3', $capture['model']);
        self::assertSame('json', $capture['response_format']);
        self::assertFalse($capture['language_present']);
        self::assertSame('voice.ogg', $capture['file_name']);
        self::assertSame(filesize(self::AUDIO), $capture['file_size']);
        self::assertNotSame('', $capture['file_type']);
    }

    public function test_custom_provider_admin_flow_passes_ssrf_and_wires_tokenless(): void
    {
        $json = json_encode([
            'name' => 'Local loopback whisper',
            'base_url' => 'http://127.0.0.1:'.self::$port.'/v1',
            'model' => 'whisper-large-v3',
            'api_style' => 'openai-stt',
            'token' => '',
            'timeout_seconds' => 20,
        ], JSON_THROW_ON_ERROR);

        $normalized = (new ProviderRegistry)->validateCustomConfig($json);

        $settings = SttSettings::fromArray([
            'provider_key' => ProviderRegistry::CUSTOM_KEY,
            'custom_provider' => $normalized,
        ], isPrivateChat: true);

        $resolved = (new ConfigResolver(new ProviderRegistry, 20, 8_388_608))->resolve($settings, null);

        self::assertSame(ProviderRegistry::CUSTOM_KEY, $resolved->key);
        self::assertSame('http://127.0.0.1:'.self::$port.'/v1', $resolved->baseUrl);
        self::assertNull($resolved->token);

        $result = (new OpenAiCompatibleStt(new Factory))->transcribe(new SttRequest(
            self::AUDIO,
            'audio/ogg',
            14,
            null,
            $resolved,
        ));

        self::assertSame('лупбэк ок', $result->text);

        $capture = $this->capture();

        self::assertSame('', $capture['authorization']);
        self::assertSame('whisper-large-v3', $capture['model']);
    }

    public function test_revoked_key_maps_to_auth_over_real_socket(): void
    {
        try {
            $this->transcribe(token: 'revoked-key');
            self::fail('rejected credentials must throw');
        } catch (ProviderException $e) {
            self::assertSame(ErrorCode::Auth, $e->errorCode);
        }
    }

    /**
     * Child-process body: never returns into PHPUnit, answers at most
     * MAX_REQUESTS connections, then dies by SIGKILL so no shutdown
     * handlers can corrupt the test runner's output.
     */
    private static function serveUntilDone($listen): void
    {
        stream_set_blocking($listen, false);
        $served = 0;
        $idleDeadline = microtime(true) + 15;

        while ($served < self::MAX_REQUESTS && microtime(true) < $idleDeadline) {
            $read = [$listen];
            $write = null;
            $except = null;

            if (@stream_select($read, $write, $except, 1) !== 1) {
                continue;
            }

            $conn = @stream_socket_accept($listen, 0);

            if ($conn === false) {
                continue;
            }

            $served++;
            $idleDeadline = microtime(true) + 15;

            try {
                self::handleConnection($conn);
            } catch (\Throwable) {
                // fall through: connection errors must not kill the server
            }
        }
    }

    private static function handleConnection($conn): void
    {
        stream_set_timeout($conn, 5);

        $rawHead = '';

        while (! str_contains($rawHead, "\r\n\r\n")) {
            $chunk = fread($conn, 8192);

            if ($chunk === false || $chunk === '') {
                fclose($conn);

                return;
            }

            $rawHead .= $chunk;
        }

        [$head, $rest] = explode("\r\n\r\n", $rawHead, 2);
        $lines = explode("\r\n", $head);
        [$method, $uri] = explode(' ', (string) $lines[0]);
        $headers = [];

        foreach (\array_slice($lines, 1) as $line) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        $body = $rest;

        if ($method === 'POST' && isset($headers['content-length'])) {
            $needed = (int) $headers['content-length'] - strlen($body);

            while ($needed > 0) {
                $chunk = fread($conn, min(65536, $needed));

                if ($chunk === false || $chunk === '') {
                    break;
                }

                $body .= $chunk;
                $needed -= strlen($chunk);
            }
        }

        $capture = [
            'authorization' => $headers['authorization'] ?? '',
            'model' => '',
            'response_format' => '',
            'language_present' => false,
            'file_name' => '',
            'file_size' => 0,
            'file_type' => '',
        ];

        if ($method === 'GET' && str_contains($uri, '/healthz')) {
            self::respond($conn, 200, ['ok' => true]);

            return;
        }

        if ($method === 'POST' && str_contains($uri, '/audio/transcriptions')) {
            if (($headers['authorization'] ?? '') !== '' && $headers['authorization'] !== 'Bearer '.self::TOKEN) {
                self::respond($conn, 401, ['error' => 'bad credentials']);

                return;
            }

            $fields = self::parseMultipart($body, $headers['content-type'] ?? '', $capture);

            if (! isset($fields['file'])) {
                self::respond($conn, 400, ['error' => 'multipart file missing']);

                return;
            }

            self::writeCapture($capture, $fields);
            self::respond($conn, 200, ['text' => ' лупбэк ок ']);

            return;
        }

        self::respond($conn, 404, ['error' => 'not found']);
    }

    /**
     * Minimal multipart/form-data parser good enough for assertions: splits
     * on the boundary and records form fields plus file-part metadata.
     *
     * @param  array<string, string>  $headers
     * @param  array<string, string>  $capture
     * @return array<string, string>
     */
    private static function parseMultipart(string $body, string $contentType, array &$capture): array
    {
        if (preg_match('/boundary=(?:"([^"]+)"|([^;\s]+))/i', $contentType, $m) !== 1) {
            return [];
        }

        $boundary = '--'.trim((string) ($m[1] ?? $m[2]));
        $fields = [];

        foreach (explode($boundary, $body) as $part) {
            $part = preg_replace('/^\r\n/', '', $part) ?? $part;

            if ($part === '' || $part === "--\r\n" || $part === '--' || ! str_contains($part, "\r\n\r\n")) {
                continue;
            }

            [$partHead, $partBody] = explode("\r\n\r\n", $part, 2);
            $partBody = preg_replace('/\r\n$/', '', $partBody) ?? $partBody;

            if (preg_match('/name="([^"]*)"/i', $partHead, $n) !== 1) {
                continue;
            }

            $name = (string) $n[1];

            if (preg_match('/filename="([^"]*)"/i', $partHead, $f) === 1) {
                $capture['file_name'] = (string) $f[1];
                $capture['file_size'] = strlen($partBody);

                if (preg_match('/^content-type:\s*(.+)$/im', $partHead, $t) === 1) {
                    $capture['file_type'] = strtolower(trim((string) $t[1]));
                }

                $fields[$name] = '(file)';
            } else {
                $fields[$name] = $partBody;
                $capture[$name] = $partBody;
            }
        }

        return $fields;
    }

    /**
     * @param  array<string, string>  $capture
     * @param  array<string, string>  $fields
     */
    private static function writeCapture(array $capture, array $fields): void
    {
        $capture['language_present'] = array_key_exists('language', $fields);
        file_put_contents(self::$capturePath, json_encode($capture, JSON_THROW_ON_ERROR));
    }

    private static function respond($conn, int $status, array $body): void
    {
        $payload = json_encode($body, JSON_THROW_ON_ERROR);
        $reason = match ($status) {
            200 => 'OK',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            default => 'Not Found',
        };

        fwrite($conn, "HTTP/1.1 {$status} {$reason}\r\nContent-Type: application/json\r\nContent-Length: ".strlen($payload)."\r\nConnection: close\r\n\r\n{$payload}");
        fclose($conn);
    }

    private function transcribe(string $token): SttResult
    {
        $config = new VoiceProviderConfig(
            key: 'loopback',
            apiStyle: SttApiStyle::OpenaiStt,
            baseUrl: 'http://127.0.0.1:'.self::$port.'/v1',
            token: $token,
            model: 'whisper-large-v3',
            connectTimeoutSec: 5,
            timeoutSec: 10,
            maxResponseBytes: 1_048_576,
        );

        return (new OpenAiCompatibleStt(new Factory))->transcribe(new SttRequest(
            audioPath: self::AUDIO,
            mimeType: 'audio/ogg',
            durationSec: 14,
            languageHint: null,
            provider: $config,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function capture(): array
    {
        clearstatcache(true, self::$capturePath);

        $raw = file_get_contents(self::$capturePath);

        if ($raw === false) {
            self::fail('loopback server did not write a request capture');
        }

        return json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    }
}
