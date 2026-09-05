<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Media;

use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\Contracts\ApiCommunication\TgBotApiDTOClientContract;
use BAGArt\TelegramBot\TgApi\Methods\DTO\GetFileMethodDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\FileTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\VoiceTypeDTO;
use BAGArt\TelegramBotStt\Provider\ErrorCode;
use BAGArt\TelegramBotStt\Provider\ProviderException;
use Illuminate\Http\Client\Factory;

/**
 * getFile + disk-streamed download of the voice file (§6).
 *
 * Laravel's HTTP client buffers in memory by default; a 19 MB voice per
 * concurrent worker is an OOM vector, so the body goes through ->sink()
 * straight to a 0600 tmpfile. The download URL contains the bot token:
 * it is never logged and never appears in exception messages.
 */
final class FileDownloader
{
    private const DOWNLOAD_BUDGET_SECONDS = 8;

    public function __construct(
        private readonly TgBotApiDTOClientContract $api,
        private readonly Factory $http = new Factory(),
        private readonly ?string $tmpDirOverride = null,
    ) {
    }

    /**
     * @throws ProviderException PayloadTooLarge | Unavailable
     */
    public function download(TgBotConfig $botConfig, VoiceTypeDTO $voice, int $maxFileMb): DownloadedVoice
    {
        $file = $this->resolveFile($botConfig, $voice);
        $sizeLimitBytes = $maxFileMb * 1024 * 1024;

        if ((int) ($file->fileSize ?? '0') > $sizeLimitBytes) {
            throw new ProviderException(ErrorCode::PayloadTooLarge, "Voice exceeds {$maxFileMb} MB limit");
        }

        if ($file->filePath === null) {
            throw new ProviderException(ErrorCode::Unavailable, 'Telegram returned no file path');
        }

        $path = $this->freshTmpPath();

        try {
            // The URL embeds the bot token — built here and never surfaced.
            $response = $this->http
                ->baseUrl('https://api.telegram.org/file/bot'.$botConfig->token)
                ->connectTimeout(10)
                ->timeout(self::DOWNLOAD_BUDGET_SECONDS)
                ->sink($path)
                ->get($file->filePath);

            if ($response->failed()) {
                throw new ProviderException(ErrorCode::Unavailable, "Voice download failed (HTTP {$response->status()})");
            }

            clearstatcache(true, $path);

            $bytes = (int) @filesize($path);

            if ($bytes <= 0) {
                throw new ProviderException(ErrorCode::Unavailable, 'Downloaded voice is empty');
            }

            if ($bytes > $sizeLimitBytes) {
                throw new ProviderException(ErrorCode::PayloadTooLarge, "Voice exceeds {$maxFileMb} MB limit");
            }
        } catch (ProviderException $e) {
            @unlink($path);

            throw $e;
        } catch (\Throwable $e) {
            @unlink($path);

            throw new ProviderException(ErrorCode::Unavailable, 'Voice download failed', $e);
        }

        return new DownloadedVoice($path, $bytes, $voice->mimeType, max(0, $voice->duration));
    }

    private function resolveFile(TgBotConfig $botConfig, VoiceTypeDTO $voice): FileTypeDTO
    {
        try {
            $response = $this->api->request($botConfig, new GetFileMethodDTO(fileId: $voice->fileId));
        } catch (\Throwable $e) {
            throw new ProviderException(ErrorCode::Unavailable, 'getFile call failed', $e);
        }

        if (! $response->ok || ! $response->result instanceof FileTypeDTO) {
            throw new ProviderException(ErrorCode::Unavailable, 'getFile returned no File object');
        }

        return $response->result;
    }

    private function freshTmpPath(): string
    {
        $dir = $this->tmpDir();

        if (! is_dir($dir) && ! @mkdir($dir, 0770, true) && ! is_dir($dir)) {
            throw new ProviderException(ErrorCode::Unavailable, 'Cannot create STT tmp directory');
        }

        return $dir.DIRECTORY_SEPARATOR.bin2hex(random_bytes(8)).'.ogg';
    }

    private function tmpDir(): string
    {
        if ($this->tmpDirOverride !== null && $this->tmpDirOverride !== '') {
            return $this->tmpDirOverride;
        }

        return \function_exists('storage_path')
            ? storage_path('framework/stt')
            : sys_get_temp_dir().DIRECTORY_SEPARATOR.'stt';
    }
}
