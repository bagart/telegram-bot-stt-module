<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Processing;

use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\Contracts\Outbound\TgSenderContract;
use BAGArt\TelegramBot\TgApi\Methods\DTO\SendChatActionMethodDTO;
use BAGArt\TelegramBot\TgApi\Methods\DTO\SendMessageMethodDTO;
use BAGArt\TelegramBot\TgApi\Methods\Enum\ActionEnum;
use BAGArt\TelegramBot\TgApi\Methods\Enum\ParseModeEnum;
use BAGArt\TelegramBot\TgApi\Types\DTO\MessageTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\ReplyParametersTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\VoiceTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\Enum\ChatPropTypeEnum;
use BAGArt\TelegramBotStt\Guard\ChatSemaphore;
use BAGArt\TelegramBotStt\Guard\GlobalConcurrency;
use BAGArt\TelegramBotStt\Guard\ProviderBreaker;
use BAGArt\TelegramBotStt\Guard\QuotaCounter;
use BAGArt\TelegramBotStt\I18n\Strings;
use BAGArt\TelegramBotStt\Media\FfmpegConverter;
use BAGArt\TelegramBotStt\Media\FileDownloader;
use BAGArt\TelegramBotStt\Models\SttTranscription;
use BAGArt\TelegramBotStt\Provider\ConfigResolver;
use BAGArt\TelegramBotStt\Provider\Dto\SttRequest;
use BAGArt\TelegramBotStt\Provider\ErrorCode;
use BAGArt\TelegramBotStt\Provider\ProviderException;
use BAGArt\TelegramBotStt\Provider\SttProviderContract;
use BAGArt\TelegramBotStt\Settings\SttSettings;
use BAGArt\TelegramBotStt\Settings\SttSettingsService;
use BAGArt\TelegramBotStt\Support\SttStats;
use BAGArt\TelegramBotStt\Support\TemplateRenderer;
use BAGArt\TelegramBotStt\Support\TranscriptionRecorder;
use BAGArt\TelegramBotStt\Support\VaultTokenResolver;

/**
 * The §7bis step machine: typing indicator → dedupe reserve → caps → quota →
 * semaphore → breaker → download → maybe convert → provider call → record →
 * threaded reply. Sync in-webhook execution with a wall-clock budget; every
 * guard declares its degraded-cache mode (§9).
 */
class VoiceTranscriptionService
{
    public function __construct(
        private readonly SttSettingsService $settingsService,
        private readonly ConfigResolver $configResolver,
        private readonly SttProviderContract $provider,
        private readonly FileDownloader $downloader,
        private readonly FfmpegConverter $converter,
        private readonly TranscriptionRecorder $recorder,
        private readonly TemplateRenderer $renderer,
        private readonly QuotaCounter $quota,
        private readonly ChatSemaphore $semaphore,
        private readonly GlobalConcurrency $global,
        private readonly ProviderBreaker $breaker,
        private readonly TgSenderContract $sender,
        private readonly VaultTokenResolver $tokens,
        private readonly SttStats $stats,
        private readonly int $budgetSeconds = 30,
    ) {
    }

    /**
     * @param  'auto'|'command'  $source
     */
    public function transcribe(TgBotConfig $botConfig, MessageTypeDTO $message, VoiceTypeDTO $voice, string $source): void
    {
        $botId = (string) $botConfig->botId;
        $chatId = (int) $message->chat->id;
        $isPrivate = $message->chat->type === ChatPropTypeEnum::PRIVATE;

        $deadlineAt = hrtime(true) + $this->budgetSeconds * 1_000_000_000;
        $this->sendTyping($botConfig, $chatId);

        $settings = $this->settingsService->get($botId, $chatId, $isPrivate);

        // Caps (§7bis): duration/size skips are silent in auto mode, surfaced on /text.
        if ($voice->duration > $settings->maxDurationSec) {
            $this->surface($botConfig, $message, $settings, ErrorCode::UnsupportedInput, $source);

            return;
        }

        try {
            [$row, $isNew] = $this->recorder->reserve(
                $botId,
                $chatId,
                $message->messageId,
                $voice->fileUniqueId,
                $settings->providerKey,
            );

            if (! $isNew && ($cachedText = $this->replayableText($row)) !== null) {
                $this->reply(
                    $botConfig,
                    $message,
                    $settings,
                    $this->renderer->render($settings, $cachedText, null, $voice->duration),
                    null,
                    cached: true,
                );

                return;
            }
        } catch (\Throwable) {
            // DB unavailable: continue without dedupe/history (fail-open, cache-only reply)
            $row = null;
        }

        if (! $this->quota->allowed($botId, $chatId, $settings->dailyQuota)) {
            $this->stats->incQuotaBlocked($botId);
            $this->surface($botConfig, $message, $settings, ErrorCode::QuotaProvider, $source);

            return;
        }

        if (! $this->semaphore->acquire($botId, $chatId)) {
            return;
        }

        if (! $this->global->acquire()) {
            $this->semaphore->release($botId, $chatId);

            return;
        }

        try {
            $this->runPipeline($botConfig, $message, $voice, $settings, $source, $row, $deadlineAt);
        } finally {
            $this->semaphore->release($botId, $chatId);
            $this->global->release();
        }
    }

    /**
     * @param  'auto'|'command'  $source
     */
    private function runPipeline(
        TgBotConfig $botConfig,
        MessageTypeDTO $message,
        VoiceTypeDTO $voice,
        SttSettings $settings,
        string $source,
        ?SttTranscription $row,
        int $deadlineAt,
    ): void {
        $botId = (string) $botConfig->botId;
        $providerKey = $settings->providerKey;

        if (! $this->breaker->allow($providerKey)) {
            $this->recordAndSurface($botConfig, $message, $settings, $source, $row, ErrorCode::Unavailable);

            return;
        }

        if ($this->expired($deadlineAt)) {
            $this->recordAndSurface($botConfig, $message, $settings, $source, $row, ErrorCode::Unavailable);

            return;
        }

        $this->sendTyping($botConfig, (int) $message->chat->id);

        try {
            $config = $this->configResolver->resolve($settings, $this->resolveToken($botId, $providerKey));
            $downloaded = $this->downloader->download($botConfig, $voice, $settings->maxFileMb);
        } catch (ProviderException $e) {
            $this->recordAndSurface($botConfig, $message, $settings, $source, $row, $e->errorCode);

            return;
        }

        try {
            if ($config->containerFormat !== null && str_starts_with($downloaded->mimeType ?? '', 'audio/ogg')) {
                $downloaded = $this->converter->convert($downloaded, $config->containerFormat);
            }

            if ($this->expired($deadlineAt)) {
                throw new ProviderException(ErrorCode::Unavailable, 'Budget exhausted before provider call');
            }

            $result = $this->provider->transcribe(new SttRequest(
                audioPath: $downloaded->path,
                mimeType: $downloaded->mimeType ?? 'audio/ogg',
                durationSec: $voice->duration,
                languageHint: $settings->language,
                provider: $config,
            ));

            $this->breaker->recordSuccess($providerKey);
            $this->stats->incTotal($botId, $result->providerKey, 'ok');
            $this->stats->recordLatency($providerKey, $result->latencyMs);
            $this->quota->increment($botId, (int) $message->chat->id);

            if ($row !== null && $row->exists) {
                $this->safeStore(fn (): mixed => $this->recorder->storeOk($row, $result->text, $result->providerKey, $result->latencyMs, $result->language));
            }

            $notice = $this->privacyNotice($botConfig, $message, $settings);
            $body = $this->renderer->render($settings, $result->text, $settings->language ?? $result->language, $voice->duration);
            $this->reply($botConfig, $message, $settings, $body, $notice, cached: false);
        } catch (ProviderException $e) {
            $this->breaker->recordFailure($providerKey);
            $this->recordAndSurface($botConfig, $message, $settings, $source, $row, $e->errorCode);
        } finally {
            $downloaded->destroy();
        }
    }

    private function replayableText(?SttTranscription $row): ?string
    {
        return $row !== null
            && $row->status === SttTranscription::STATUS_OK
            && is_string($row->result_text)
            && $row->result_text !== ''
            ? $row->result_text
            : null;
    }

    private function resolveToken(string $botId, string $providerKey): ?string
    {
        return $this->tokens->resolve($botId, $providerKey);
    }

    private function privacyNotice(TgBotConfig $botConfig, MessageTypeDTO $message, SttSettings $settings): ?string
    {
        if ($settings->noticeShown) {
            return null;
        }

        try {
            $this->settingsService->patch((string) $botConfig->botId, (int) $message->chat->id, ['notice_shown' => true]);
        } catch (\Throwable) {
            // notice flag is cosmetic; never block transcription
        }

        return Strings::t($settings->locale, 'notice.append', ['provider' => $this->providerTitle($settings)]);
    }

    private function providerTitle(SttSettings $settings): string
    {
        return htmlspecialchars($settings->providerKey, ENT_QUOTES, 'UTF-8');
    }

    /**
     * @param  'auto'|'command'  $source
     */
    private function recordAndSurface(
        TgBotConfig $botConfig,
        MessageTypeDTO $message,
        SttSettings $settings,
        string $source,
        ?SttTranscription $row,
        ErrorCode $code,
    ): void {
        $this->stats->incTotal(
            (string) $botConfig->botId,
            $settings->providerKey,
            $code === ErrorCode::EmptyResult ? 'empty' : $code->value,
        );

        if ($row !== null && $row->exists) {
            $code === ErrorCode::EmptyResult
                ? $this->safeStore(fn (): mixed => $this->recorder->storeEmpty($row, $settings->providerKey))
                : $this->safeStore(fn (): mixed => $this->recorder->storeFailed($row, $settings->providerKey, $code));
        }

        $this->surface($botConfig, $message, $settings, $code, $source);
    }

    /**
     * Error surfacing per on_error mode + source (§7): auto skips silently
     * for soft failures, /text always explains.
     */
    private function surface(
        TgBotConfig $botConfig,
        MessageTypeDTO $message,
        SttSettings $settings,
        ErrorCode $code,
        string $source,
    ): void {
        $mode = $source === 'command' ? SttSettings::ERROR_MESSAGE : $settings->onError;

        if ($mode === SttSettings::ERROR_SILENT) {
            return;
        }

        $text = $mode === SttSettings::ERROR_EMOJI
            ? Strings::t($settings->locale, 'error.emoji')
            : Strings::errorText($settings->locale, $code);

        if ($text === '') {
            return;
        }

        $this->sender->send($botConfig, $this->messageDto($botConfig, $message, $settings, $text));
    }

    private function reply(
        TgBotConfig $botConfig,
        MessageTypeDTO $message,
        SttSettings $settings,
        string $body,
        ?string $appendedNotice,
        bool $cached,
    ): void {
        $suffix = match (true) {
            $cached => "\n<i>(cached)</i>",
            default => '',
        };

        $this->sender->send($botConfig, $this->messageDto(
            $botConfig,
            $message,
            $settings,
            $body.($appendedNotice ?? '').$suffix,
        ));
    }

    private function messageDto(
        TgBotConfig $botConfig,
        MessageTypeDTO $message,
        SttSettings $settings,
        string $text,
    ): SendMessageMethodDTO {
        return new SendMessageMethodDTO(
            chatId: (string) $message->chat->id,
            text: mb_substr($text, 0, 4096),
            parseMode: ParseModeEnum::HTML,
            replyParameters: $settings->replyThreaded
                ? new ReplyParametersTypeDTO(messageId: $message->messageId)
                : null,
        );
    }

    private function sendTyping(TgBotConfig $botConfig, int $chatId): void
    {
        $this->sender->send($botConfig, new SendChatActionMethodDTO(chatId: (string) $chatId, action: ActionEnum::TYPING));
    }

    private function safeStore(callable $write): void
    {
        try {
            $write();
        } catch (\Throwable) {
            // history write failure must not lose the user-facing reply
        }
    }

    private function expired(int $deadlineAt): bool
    {
        return hrtime(true) >= $deadlineAt;
    }
}
