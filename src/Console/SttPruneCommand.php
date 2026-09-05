<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Console;

use BAGArt\TelegramBotStt\Models\SttTranscription;
use Illuminate\Console\Command;

/**
 * Retention sweep (§10.5): prunes transcription history older than
 * stt.retention_days and tmpfiles by mtime. Declared in config/tg_modules.php
 * (schedule) and registered by the module engine.
 */
final class SttPruneCommand extends Command
{
    protected $signature = 'stt:prune {--days= : Override retention days}';

    protected $description = 'Prune STT transcription history and stale voice tmpfiles';

    public function handle(): int
    {
        $days = max(1, (int) ($this->option('days') ?? config('stt.retention_days', 30)));
        $cutoff = now()->subDays($days);

        $rowsDeleted = SttTranscription::query()
            ->where('created_at', '<', $cutoff)
            ->delete();

        $filesRemoved = $this->sweepTmpFiles($days * 86400);

        $this->info(sprintf(
            'stt:prune — %d transcription row(s), %d tmpfile(s), cutoff %s',
            $rowsDeleted,
            $filesRemoved,
            $cutoff->toIso8601String(),
        ));

        return self::SUCCESS;
    }

    private function sweepTmpFiles(int $maxAgeSeconds): int
    {
        $dir = (string) (config('stt.tmp_dir') !== '' ? config('stt.tmp_dir') : storage_path('framework/stt'));

        if (! is_dir($dir)) {
            return 0;
        }

        $removed = 0;
        $deadline = time() - $maxAgeSeconds;

        foreach (scandir($dir) ?: [] as $entry) {
            if (in_array($entry, ['.', '..'], true)) {
                continue;
            }

            $path = $dir.DIRECTORY_SEPARATOR.$entry;

            if (! is_file($path)) {
                continue;
            }

            $mtime = (int) @filemtime($path);

            if ($mtime > 0 && $mtime < $deadline && @unlink($path)) {
                $removed++;
            }
        }

        return $removed;
    }
}
