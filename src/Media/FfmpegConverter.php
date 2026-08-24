<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Media;

use BAGArt\TelegramBotStt\Provider\ErrorCode;
use BAGArt\TelegramBotStt\Provider\ProviderException;
use Symfony\Component\Process\Process;

/**
 * Optional audio-container conversion via the ffmpeg binary. Availability is
 * detected lazily; when ffmpeg is absent the module simply runs providers
 * that accept native ogg/opus (capability-gated, §6 step 6).
 */
final class FfmpegConverter
{
    private ?bool $available = null;

    /** @var list<string>|null */
    private ?array $versionParts = null;

    public function __construct(
        /** Empty string = auto-detect "ffmpeg" on PATH; "none" = hard-disable. */
        private readonly string $binaryPath = '',
    ) {}

    public function available(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        if ($this->binaryPath === 'none') {
            return $this->available = false;
        }

        $process = new Process(array_filter([$this->binaryPath ?: 'ffmpeg', '-version']));
        $process->setTimeout(5);

        try {
            $process->run();
        } catch (\Throwable) {
            return $this->available = false;
        }

        if (! $process->isSuccessful()) {
            return $this->available = false;
        }

        if (preg_match('/ffmpeg version (\S+)/', $process->getOutput(), $m) === 1) {
            $this->versionParts = [$m[1]];
        }

        return $this->available = true;
    }

    /**
     * @return list<string> [version] or empty when unavailable
     */
    public function version(): array
    {
        $this->available();

        return $this->versionParts ?? [];
    }

    /**
     * Convert a downloaded voice into another container format (e.g. mp3).
     * Returns the converted path; the source file stays untouched.
     *
     * @throws ProviderException Unavailable | UnsupportedInput
     */
    public function convert(DownloadedVoice $source, string $format): DownloadedVoice
    {
        if (! $this->available()) {
            throw new ProviderException(ErrorCode::Unavailable, 'Conversion requested but ffmpeg is not available');
        }

        if (preg_match('/^[a-z0-9]{2,5}$/', $format) !== 1) {
            throw new ProviderException(ErrorCode::UnsupportedInput, 'Invalid target format');
        }

        $target = $source->path.'.'.preg_replace('/[^a-z0-9]/', '', $format);

        // No shell wrapper: argument array only.
        $process = new Process([
            $this->binaryPath ?: 'ffmpeg',
            '-y',
            '-i',
            $source->path,
            '-loglevel',
            'error',
            $target,
        ]);
        $process->setTimeout(15);

        try {
            $process->run();
        } catch (\Throwable $e) {
            throw new ProviderException(ErrorCode::Unavailable, 'ffmpeg conversion crashed', $e);
        }

        clearstatcache(true, $target);

        if (! $process->isSuccessful() || ! is_file($target)) {
            @unlink($target);

            throw new ProviderException(ErrorCode::UnsupportedInput, 'ffmpeg could not convert this audio');
        }

        $bytes = (int) filesize($target);
        @unlink($source->path);

        return new DownloadedVoice($target, $bytes, null, $source->durationSec);
    }
}
