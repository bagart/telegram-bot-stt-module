<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Provider\Adapter;

use BAGArt\TelegramBotStt\Provider\Dto\SttRequest;
use BAGArt\TelegramBotStt\Provider\Dto\SttResult;
use BAGArt\TelegramBotStt\Provider\ErrorCode;
use BAGArt\TelegramBotStt\Provider\ProviderException;
use BAGArt\TelegramBotStt\Provider\SttProviderContract;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Single wire-dialect driver for the whole provider catalog (§3): every row
 * speaks POST {base}/audio/transcriptions with multipart/form-data.
 *
 * HTTP-client discipline follows LlmClient (summarizer) verbatim: MAX_ATTEMPTS=2,
 * Retry-After capped at 30 s, 10 s connect timeout, size-capped response body,
 * token never logged and never part of exception messages.
 */
final class OpenAiCompatibleStt implements SttProviderContract
{
    private const MAX_ATTEMPTS = 2;

    private const RETRY_DELAY_MS = 1500;

    private const RETRY_AFTER_CAP_SECONDS = 30;

    public function __construct(
        private readonly Factory $http = new Factory,
    ) {}

    public function transcribe(SttRequest $request): SttResult
    {
        $config = $request->provider;
        $startedAt = hrtime(true);
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return $this->finish($config->key, $this->call($request), $startedAt);
            } catch (RetryableSttException $e) {
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw new ProviderException(ErrorCode::RateLimited, 'STT provider rate limited', $e);
                }

                Sleep::for(min($e->retryAfterSeconds, self::RETRY_AFTER_CAP_SECONDS))->seconds();
            } catch (ConnectionException $e) {
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw new ProviderException(ErrorCode::Unavailable, 'STT connection failed', $e);
                }

                Sleep::for(self::RETRY_DELAY_MS)->milliseconds();
            } catch (ProviderException $e) {
                throw $e;
            } catch (Throwable $e) {
                throw new ProviderException(ErrorCode::Unavailable, 'STT call failed: '.$e::class, $e);
            }
        }
    }

    private function call(SttRequest $request): string
    {
        $config = $request->provider;
        $http = $this->http
            ->baseUrl($config->baseUrl)
            ->connectTimeout($config->connectTimeoutSec)
            ->timeout($config->timeoutSec);

        if ($config->token !== null && $config->token !== '') {
            $http = $http->withToken($config->token);
        }

        $resource = fopen($request->audioPath, 'rb');

        if ($resource === false) {
            throw new ProviderException(ErrorCode::Unavailable, 'Cannot open downloaded audio file');
        }

        // The stream is intentionally NOT fclosed here: the HTTP layer keeps a
        // reference for request replay/diagnostics; PHP closes it on GC.

        $response = $http
            ->attach('file', $resource, 'voice.'.($config->containerFormat ?? 'ogg'))
            ->post('/audio/transcriptions', array_filter([
                'model' => $config->model,
                'response_format' => 'json',
                'language' => $request->languageHint,
            ], static fn ($v): bool => is_string($v) && $v !== ''));

        if ($response->tooManyRequests()) {
            throw new RetryableSttException((int) ($response->header('retry-after') ?: 1));
        }

        if ($response->status() === 413) {
            throw new ProviderException(ErrorCode::PayloadTooLarge, 'STT payload rejected by provider');
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new ProviderException(ErrorCode::Auth, 'STT provider rejected credentials');
        }

        if (in_array($response->status(), [400, 404, 409, 422], true)) {
            throw new ProviderException(ErrorCode::BadRequest, 'STT request rejected by provider');
        }

        if ($response->failed()) {
            throw new ProviderException(ErrorCode::Unavailable, "STT provider HTTP {$response->status()}");
        }

        return $this->extractText($body = (string) $response->body(), $config->maxResponseBytes);
    }

    private function extractText(string $body, int $maxResponseBytes): string
    {
        if (strlen($body) > $maxResponseBytes) {
            throw new ProviderException(ErrorCode::Unavailable, 'STT response exceeds size limit');
        }

        try {
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ProviderException(ErrorCode::Unavailable, 'STT returned non-JSON body', $e);
        }

        $text = is_array($data) && is_string($data['text'] ?? null)
            ? trim((string) $data['text'])
            : '';

        if ($text === '') {
            throw new ProviderException(ErrorCode::EmptyResult, 'Nothing recognizable in the audio');
        }

        return $text;
    }

    private function finish(string $providerKey, string $text, int $startedAt): SttResult
    {
        return new SttResult(
            text: $text,
            language: null,
            durationSec: null,
            providerKey: $providerKey,
            latencyMs: (int) ((hrtime(true) - $startedAt) / 1_000_000),
        );
    }
}
