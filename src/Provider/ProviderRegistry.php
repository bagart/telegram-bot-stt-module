<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Provider;

use InvalidArgumentException;

/**
 * Catalog of STT providers available for one-click selection plus the
 * validator for admin-authored custom provider configs (JSON editor flow).
 *
 * Preset base URLs are code-reviewed constants and skip validation; only
 * custom configs go through assertSafeBaseUrl() (SSRF, §10.3).
 */
class ProviderRegistry
{
    public const CUSTOM_KEY = 'custom';

    /** @var array<string, SttProviderPreset> */
    private array $presets;

    public function __construct()
    {
        $presets = [
            new SttProviderPreset('groq-whisper-v3', 'Groq Whisper v3', 'https://api.groq.com/openai/v1', 'whisper-large-v3', needsToken: true, costLabel: 'free tier'),
            new SttProviderPreset('groq-whisper-turbo', 'Groq Whisper Turbo', 'https://api.groq.com/openai/v1', 'whisper-large-v3-turbo', needsToken: true, costLabel: 'free tier'),
            new SttProviderPreset('openai-whisper', 'OpenAI Whisper', 'https://api.openai.com/v1', 'whisper-1', needsToken: true, costLabel: 'paid'),
            new SttProviderPreset('local-whisper', 'Local Whisper (self-hosted)', 'http://localhost:8000/v1', 'whisper-large-v3', needsToken: false, costLabel: 'self-hosted'),
        ];

        $this->presets = [];
        foreach ($presets as $preset) {
            $this->presets[$preset->key] = $preset;
        }
    }

    /** @return array<string, SttProviderPreset> */
    public function all(): array
    {
        return $this->presets;
    }

    public function has(string $key): bool
    {
        return $key === self::CUSTOM_KEY || isset($this->presets[$key]);
    }

    public function get(string $key): ?SttProviderPreset
    {
        return $this->presets[$key] ?? null;
    }

    /**
     * Pre-generated JSON shown to the admin in the custom-provider editor.
     */
    public function customTemplateJson(): string
    {
        $template = [
            'name' => 'My self-hosted Whisper',
            'base_url' => 'http://localhost:8000/v1',
            'model' => 'whisper-large-v3',
            'api_style' => 'openai-stt',
            'token' => '',
            'timeout_seconds' => 20,
            'audio_format' => null,
        ];

        return json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Validate an admin-submitted custom provider config (assoc array from
     * JSON). Returns normalized config or throws InvalidArgumentException
     * with a human-readable reason.
     *
     * @return array<string, mixed>
     */
    public function validateCustomConfig(string $json): array
    {
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException('Invalid JSON: '.$e->getMessage());
        }

        if (! is_array($data)) {
            throw new InvalidArgumentException('JSON must encode an object.');
        }

        $baseUrl = trim((string) ($data['base_url'] ?? ''));
        if ($baseUrl === '') {
            throw new InvalidArgumentException('base_url is required.');
        }

        self::assertSafeBaseUrl($baseUrl);

        $model = trim((string) ($data['model'] ?? ''));
        if ($model === '') {
            throw new InvalidArgumentException('model is required.');
        }

        $style = strtolower(trim((string) ($data['api_style'] ?? SttApiStyle::OpenaiStt->value)));
        if ($style !== SttApiStyle::OpenaiStt->value) {
            throw new InvalidArgumentException("api_style must be '".SttApiStyle::OpenaiStt->value."'.");
        }

        $token = trim((string) ($data['token'] ?? ''));
        $format = $data['audio_format'] ?? null;

        return [
            'name' => mb_substr(trim((string) ($data['name'] ?? 'Custom provider')), 0, 60),
            'base_url' => rtrim($baseUrl, '/'),
            'model' => mb_substr($model, 0, 100),
            'api_style' => $style,
            'token' => $token === '' ? null : $token,
            'timeout_seconds' => max(10, min(120, (int) ($data['timeout_seconds'] ?? 20))),
            'audio_format' => is_string($format) && preg_match('/^[a-z0-9]{2,5}$/', $format) === 1 ? $format : null,
        ];
    }

    /**
     * SSRF guard for admin-authored endpoints (§10.3): https everywhere except
     * explicitly local addresses (loopback / RFC1918 / ULA fc00::/7); link-local
     * and cloud-metadata ranges are rejected in both literal-IP and hostname form.
     */
    public static function assertSafeBaseUrl(string $baseUrl, bool $resolveDns = false): void
    {
        if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('base_url must be a valid absolute URL.');
        }

        $scheme = strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));

        if ($host === '') {
            throw new InvalidArgumentException('base_url must contain a host.');
        }

        if (! in_array($host, ['localhost'], true)
            && str_contains($host, '.localhost')) {
            throw new InvalidArgumentException('base_url host looks like a loopback alias.');
        }

        $isLocal = self::isLoopbackOrPrivateHost($host);

        // Literal link-local / metadata addresses are rejected outright (§10.3).
        $literal = self::literalIp($host);

        if ($literal !== null && self::isLinkLocalIp($literal)) {
            throw new InvalidArgumentException("base_url host is in a forbidden address range ({$literal}).");
        }

        // Resolvable hostnames must not point at link-local/metadata space.
        if ($resolveDns && ! $isLocal) {
            foreach (self::resolveIps($host) as $ip) {
                if (self::isLinkLocalIp($ip)) {
                    throw new InvalidArgumentException("base_url resolves to a forbidden address range ({$ip}).");
                }
                $isLocal = $isLocal || self::isPrivateIp($ip);
            }
        }

        if ($scheme !== 'https' && ! ($scheme === 'http' && $isLocal)) {
            throw new InvalidArgumentException('base_url must use https (http is allowed only for local/self-hosted addresses).');
        }
    }

    private static function isLoopbackOrPrivateHost(string $host): bool
    {
        if (in_array($host, ['localhost', '::1'], true)) {
            return true;
        }

        $ip = self::literalIp($host);

        return $ip !== null && self::isPrivateIp($ip);
    }

    private static function literalIp(string $host): ?string
    {
        $unbracketed = str_starts_with($host, '[') && str_ends_with($host, ']')
            ? substr($host, 1, -1)
            : $host;

        return filter_var($unbracketed, FILTER_VALIDATE_IP) !== false ? $unbracketed : null;
    }

    private static function isPrivateIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return in_array($ip, ['127.0.0.1', '0.0.0.0'], true)
                || str_starts_with($ip, '10.')
                || str_starts_with($ip, '192.168.')
                || preg_match('/^172\.(1[6-9]|2\d|3[01])\./', $ip) === 1;
        }

        $packed = @inet_pton($ip);

        if ($packed === false || strlen($packed) !== 16) {
            return false;
        }

        // fc00::/7 unique-local addresses are allowed for LAN self-hosting.
        return ($packed[0] & "\xFE") === "\xFC";
    }

    private static function isLinkLocalIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            // 169.254/16 includes the AWS/GCP metadata endpoint 169.254.169.254.
            return str_starts_with($ip, '169.254.') || str_starts_with($ip, '100.64.');
        }

        $packed = @inet_pton($ip);

        if ($packed === false || strlen($packed) !== 16) {
            return false;
        }

        // fe80::/10 link-local + NAT64 64:ff9b::/96 metadata translation guard.
        return ($packed[0] === "\xFE" && ((ord($packed[1]) & 0xC0) === 0x80))
            || ($packed[0] === "\x00" && $packed[1] === "\x64" && $packed[2] === "\xff" && $packed[3] === "\x9b");
    }

    /** @return list<string> */
    private static function resolveIps(string $host): array
    {
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);

        if ($records === false) {
            throw new InvalidArgumentException("base_url host cannot be resolved: {$host}");
        }

        $ips = [];
        foreach ($records as $record) {
            if (is_string($record['ip'] ?? null)) {
                $ips[] = $record['ip'];
            }
            if (is_string($record['ipv6'] ?? null)) {
                $ips[] = $record['ipv6'];
            }
        }

        if ($ips === []) {
            throw new InvalidArgumentException("base_url host has no A/AAAA records: {$host}");
        }

        return $ips;
    }
}
