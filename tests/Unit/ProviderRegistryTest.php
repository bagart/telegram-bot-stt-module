<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Tests\Unit;

use BAGArt\TelegramBotStt\Provider\ProviderRegistry;
use BAGArt\TelegramBotStt\Provider\SttApiStyle;
use Generator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProviderRegistryTest extends TestCase
{
    public function test_ships_catalog_presets(): void
    {
        $registry = new ProviderRegistry();
        $keys = array_keys($registry->all());

        foreach (['groq-whisper-v3', 'groq-whisper-turbo', 'openai-whisper', 'local-whisper'] as $expected) {
            self::assertContains($expected, $keys);
        }

        foreach ($registry->all() as $preset) {
            self::assertSame(SttApiStyle::OpenaiStt, $preset->apiStyle);
            self::assertNotSame('', $preset->model);
            self::assertNotSame('', $preset->name);
        }
    }

    public function test_only_local_preset_may_speak_http(): void
    {
        foreach ((new ProviderRegistry())->all() as $preset) {
            if (str_starts_with($preset->baseUrl, 'http://')) {
                self::assertTrue(str_contains($preset->baseUrl, 'localhost'), $preset->key);
                self::assertFalse($preset->needsToken, $preset->key);
            } else {
                self::assertStringStartsWith('https://', $preset->baseUrl);
            }
        }
    }

    public static function unsafeBaseUrlProvider(): Generator
    {
        yield 'plain http' => ['http://api.example.com/v1'];
        yield 'metadata endpoint' => ['https://169.254.169.254/latest/meta-data'];
        yield 'link-local ipv6' => ['https://[fe80::1]/v1'];
        yield 'ftp scheme' => ['ftp://localhost/v1'];
        yield 'not a url' => ['nonsense'];
        yield 'missing host' => ['https:///v1'];
        yield 'localhost alias' => ['https://api.localhost/v1'];
    }

    #[DataProvider('unsafeBaseUrlProvider')]
    public function test_rejects_unsafe_custom_base_urls(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        ProviderRegistry::assertSafeBaseUrl($url);
    }

    public static function safeLocalUrlProvider(): Generator
    {
        yield 'loopback v4' => ['http://127.0.0.1:8000/v1'];
        yield 'loopback name' => ['http://localhost:9000'];
        yield 'private range' => ['http://192.168.1.10:8000/v1'];
        yield 'ula ipv6' => ['http://[fd00::5]:8000/v1'];
    }

    #[DataProvider('safeLocalUrlProvider')]
    public function test_allows_local_selfhosted_http_urls(string $url): void
    {
        ProviderRegistry::assertSafeBaseUrl($url);
        self::addToAssertionCount(1);
    }

    public function test_custom_config_normalization(): void
    {
        $registry = new ProviderRegistry();
        $config = $registry->validateCustomConfig((string) json_encode([
            'base_url' => 'https://my.host/api/',
            'model' => str_repeat('m', 300),
            'token' => ' secret ',
            'timeout_seconds' => 9999,
            'audio_format' => 'MP3!',
        ]));

        self::assertSame('https://my.host/api', $config['base_url']);
        self::assertSame(100, mb_strlen($config['model']));
        self::assertNull($config['audio_format']);
        self::assertSame(120, $config['timeout_seconds']);
        self::assertSame('secret', $config['token']);
    }

    public function test_custom_config_requires_model(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ProviderRegistry())->validateCustomConfig((string) json_encode([
            'base_url' => 'https://my.host/api',
        ]));
    }

    public function test_custom_config_rejects_bad_json(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ProviderRegistry())->validateCustomConfig('{broken');
    }
}
