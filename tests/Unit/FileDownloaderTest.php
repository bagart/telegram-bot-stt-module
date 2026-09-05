<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Tests\Unit;

use BAGArt\ASKClient\Contracts\Pipeline\ASKFutureContract;
use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\Contracts\ApiCommunication\TgBotApiDTOClientContract;
use BAGArt\TelegramBot\Contracts\TgApi\TgApiMethodDTOContract;
use BAGArt\TelegramBot\Http\Pure\TgApiResponse;
use BAGArt\TelegramBot\TgApi\Methods\DTO\GetFileMethodDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\FileTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\VoiceTypeDTO;
use BAGArt\TelegramBotStt\Media\FileDownloader;
use BAGArt\TelegramBotStt\Provider\ErrorCode;
use BAGArt\TelegramBotStt\Provider\ProviderException;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\TestCase;

final class FileDownloaderTest extends TestCase
{
    public function test_rejects_oversized_files_before_any_download(): void
    {
        $api = new FakeFileApi(new FileTypeDTO(
            fileId: 'F1',
            fileUniqueId: 'U1',
            fileSize: (string) (25 * 1024 * 1024),
            filePath: 'voice/file_10.oga',
        ));

        $downloader = new FileDownloader($api, new Factory(), tmpDirOverride: sys_get_temp_dir().'/stt-test');

        try {
            $downloader->download($this->botConfig(), $this->voice(), maxFileMb: 19);
            self::fail('oversized voice must be rejected');
        } catch (ProviderException $e) {
            self::assertSame(ErrorCode::PayloadTooLarge, $e->errorCode);
        }
    }

    public function test_missing_file_path_is_unavailable(): void
    {
        $api = new FakeFileApi(new FileTypeDTO(fileId: 'F1', fileUniqueId: 'U1', fileSize: '1024', filePath: null));

        $downloader = new FileDownloader($api, new Factory(), tmpDirOverride: sys_get_temp_dir().'/stt-test');

        try {
            $downloader->download($this->botConfig(), $this->voice(), 19);
            self::fail('missing file_path must throw');
        } catch (ProviderException $e) {
            self::assertSame(ErrorCode::Unavailable, $e->errorCode);
        }
    }

    public function test_failed_http_download_hides_bot_token(): void
    {
        $api = new FakeFileApi(new FileTypeDTO(fileId: 'F1', fileUniqueId: 'U1', fileSize: '1024', filePath: 'voice/file_10.oga'));

        $http = new Factory();
        $http->fake(['*' => $http->response('nope', 404)]);

        $downloader = new FileDownloader($api, $http, tmpDirOverride: sys_get_temp_dir().'/stt-test');

        try {
            $downloader->download($this->botConfig(), $this->voice(), 19);
            self::fail('failed download must throw');
        } catch (ProviderException $e) {
            self::assertSame(ErrorCode::Unavailable, $e->errorCode);
            self::assertStringNotContainsString('AAExampleSecretPart', $e->getMessage());
        }
    }

    private function botConfig(): TgBotConfig
    {
        return new TgBotConfig('123456789:AAExampleSecretPartAAAAAAAAAAAAAAAAA', '123456789');
    }

    private function voice(): VoiceTypeDTO
    {
        return new VoiceTypeDTO(fileId: 'F1', fileUniqueId: 'U1', duration: 14, mimeType: 'audio/ogg');
    }
}

final class FakeFileApi implements TgBotApiDTOClientContract
{
    public function __construct(
        private readonly FileTypeDTO $file,
    ) {
    }

    public function request(TgBotConfig $botConfig, TgApiMethodDTOContract $dto, ?int $timeout = null): TgApiResponse
    {
        assert($dto instanceof GetFileMethodDTO);

        return new TgApiResponse(ok: true, possibleResultTypes: [FileTypeDTO::class], result: $this->file);
    }

    public function requestAsync(TgBotConfig $botConfig, TgApiMethodDTOContract $dto, ?int $timeout = null): ASKFutureContract
    {
        throw new \RuntimeException('not used in tests');
    }

    public function tick(int $systemPressure): void
    {
    }

    public function pressure(): int
    {
        return 0;
    }

    public function tickable(): array
    {
        return [];
    }
}
