<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Tests\Unit;

use BAGArt\TelegramBotStt\Provider\Adapter\OpenAiCompatibleStt;
use BAGArt\TelegramBotStt\Provider\Dto\SttRequest;
use BAGArt\TelegramBotStt\Provider\Dto\VoiceProviderConfig;
use BAGArt\TelegramBotStt\Provider\ErrorCode;
use BAGArt\TelegramBotStt\Provider\ProviderException;
use BAGArt\TelegramBotStt\Provider\SttApiStyle;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use PHPUnit\Framework\TestCase;

final class OpenAiCompatibleSttTest extends TestCase
{
    private const AUDIO = __DIR__.'/Fixtures/voice.ogg';

    public static function setUpBeforeClass(): void
    {
        if (! is_dir(\dirname(self::AUDIO))) {
            mkdir(\dirname(self::AUDIO), 0777, true);
        }

        file_put_contents(self::AUDIO, 'RIFF-fake-ogg-bytes');
    }

    public function test_sends_multipart_fields_and_bearer_and_parses_text(): void
    {
        $http = new Factory;
        $http->fake([
            'api.groq.com/*' => $http->response(['text' => '  Привет, мир  '], 200),
        ]);

        $result = (new OpenAiCompatibleStt($http))->transcribe($this->request());

        self::assertSame('Привет, мир', $result->text);
        self::assertSame('groq-whisper-v3', $result->providerKey);
        self::assertGreaterThanOrEqual(0, $result->latencyMs);

        $http->assertSent(function (Request $request): bool {
            $body = (string) $request->body();

            return $request->hasHeader('Authorization', 'Bearer gsk-test')
                && str_contains($body, 'name="model"')
                && str_contains($body, 'whisper-large-v3')
                && str_contains($body, 'name="file"')
                && str_contains($request->url(), '/audio/transcriptions');
        });
    }

    public function test_omits_authorization_when_no_token(): void
    {
        $http = new Factory;
        $http->fake(['*' => $http->response(['text' => 'ok'], 200)]);

        $config = $this->config(token: null);
        (new OpenAiCompatibleStt($http))->transcribe(new SttRequest(
            self::AUDIO, 'audio/ogg', null, null, $config,
        ));

        $http->assertSent(fn (Request $r): bool => ! $r->hasHeader('Authorization'));
    }

    public function test_maps_http_errors_to_taxonomy(): void
    {
        $cases = [
            [401, ErrorCode::Auth],
            [403, ErrorCode::Auth],
            [400, ErrorCode::BadRequest],
            [404, ErrorCode::BadRequest],
            [413, ErrorCode::PayloadTooLarge],
            [500, ErrorCode::Unavailable],
        ];

        foreach ($cases as [$status, $expectedCode]) {
            $http = new Factory;
            $http->fake(['*' => $http->response(['error' => 'x'], $status)]);

            try {
                (new OpenAiCompatibleStt($http))->transcribe($this->request());
                self::fail("HTTP {$status} must throw");
            } catch (ProviderException $e) {
                self::assertSame($expectedCode, $e->errorCode, "HTTP {$status}");
            }
        }
    }

    public function test_empty_transcription_maps_to_empty_result(): void
    {
        $http = new Factory;
        $http->fake(['*' => $http->response(['text' => '   '], 200)]);

        try {
            (new OpenAiCompatibleStt($http))->transcribe($this->request());
            self::fail('empty text must throw');
        } catch (ProviderException $e) {
            self::assertSame(ErrorCode::EmptyResult, $e->errorCode);
        }
    }

    public function test_response_size_cap(): void
    {
        $http = new Factory;
        $http->fake(['*' => $http->response(['text' => str_repeat('a', 100)], 200)]);

        $config = $this->config();

        $tiny = new VoiceProviderConfig(
            key: $config->key,
            apiStyle: $config->apiStyle,
            baseUrl: $config->baseUrl,
            token: $config->token,
            model: $config->model,
            connectTimeoutSec: $config->connectTimeoutSec,
            timeoutSec: $config->timeoutSec,
            maxResponseBytes: 64,
        );

        try {
            (new OpenAiCompatibleStt($http))->transcribe(new SttRequest(
                self::AUDIO, 'audio/ogg', null, null, $tiny,
            ));
            self::fail('oversized body must throw');
        } catch (ProviderException $e) {
            self::assertSame(ErrorCode::Unavailable, $e->errorCode);
        }
    }

    public function test_token_never_leaks_into_exception_message(): void
    {
        $http = new Factory;
        $http->fake(['*' => static fn () => throw new \RuntimeException('socket exploded')]);

        try {
            (new OpenAiCompatibleStt($http))->transcribe($this->request());
            self::fail('connection failure must throw');
        } catch (ProviderException $e) {
            self::assertSame(ErrorCode::Unavailable, $e->errorCode);
            self::assertStringNotContainsString('gsk-test', $e->getMessage());
            self::assertStringNotContainsString('gsk-test', (string) $e->getPrevious());
        }
    }

    private function request(): SttRequest
    {
        return new SttRequest(
            audioPath: self::AUDIO,
            mimeType: 'audio/ogg',
            durationSec: 14,
            languageHint: null,
            provider: $this->config(),
        );
    }

    private function config(?string $token = 'gsk-test'): VoiceProviderConfig
    {
        return new VoiceProviderConfig(
            key: 'groq-whisper-v3',
            apiStyle: SttApiStyle::OpenaiStt,
            baseUrl: 'https://api.groq.com/openai/v1',
            token: $token,
            model: 'whisper-large-v3',
            connectTimeoutSec: 10,
            timeoutSec: 20,
            maxResponseBytes: 8388608,
        );
    }
}
