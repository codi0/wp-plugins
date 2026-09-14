<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Multisite;

final class FederationSigner
{
    private const VERSION = 1;
    private const TTL_SECONDS = 60;
    private const CLOCK_SKEW_SECONDS = 10;

    private SiteDirectory $sites;
    private ?string $explicitSecret;
    private $clock;
    private $nonceFactory;

    public function __construct(SiteDirectory $sites, ?string $explicitSecret = null, ?callable $clock = null, ?callable $nonceFactory = null)
    {
        $this->sites = $sites;
        $this->explicitSecret = $explicitSecret;
        $this->clock = $clock ?? static fn (): int => time();
        $this->nonceFactory = $nonceFactory ?? static fn (): string => bin2hex(random_bytes(16));
    }

    public function issue(int $targetSiteId, int $userId, string $operation, string $body): string
    {
        $now = (int) call_user_func($this->clock);
        $claims = array(
            'v' => self::VERSION,
            'network_id' => $this->sites->networkId(),
            'target_site_id' => $targetSiteId,
            'user_id' => $userId,
            'operation' => $operation,
            'iat' => $now,
            'exp' => $now + self::TTL_SECONDS,
            'nonce' => (string) call_user_func($this->nonceFactory),
            'body_sha256' => hash('sha256', $body),
        );

        $payload = $this->base64UrlEncode($this->encode($claims));
        $signature = hash_hmac('sha256', $payload, $this->secret(), true);
        return $payload . '.' . $this->base64UrlEncode($signature);
    }

    /** @return array<string,mixed>|\WP_Error */
    public function verify(string $token, int $expectedSiteId, string $expectedOperation, string $body)
    {
        $parts = explode('.', trim($token));
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return $this->error('codi_multisite_bad_assertion', 'The federation assertion is malformed.', 401);
        }

        $signature = $this->base64UrlDecode($parts[1]);
        if ($signature === null) {
            return $this->error('codi_multisite_bad_assertion', 'The federation assertion signature is malformed.', 401);
        }
        $expectedSignature = hash_hmac('sha256', $parts[0], $this->secret(), true);
        if (!hash_equals($expectedSignature, $signature)) {
            return $this->error('codi_multisite_bad_assertion', 'The federation assertion signature is invalid.', 401);
        }

        $json = $this->base64UrlDecode($parts[0]);
        $claims = $json === null ? null : json_decode($json, true);
        if (!is_array($claims)) {
            return $this->error('codi_multisite_bad_assertion', 'The federation assertion payload is invalid.', 401);
        }

        $now = (int) call_user_func($this->clock);
        $version = (int) ($claims['v'] ?? 0);
        $networkId = (int) ($claims['network_id'] ?? 0);
        $targetSiteId = (int) ($claims['target_site_id'] ?? 0);
        $userId = (int) ($claims['user_id'] ?? 0);
        $operation = is_string($claims['operation'] ?? null) ? (string) $claims['operation'] : '';
        $issuedAt = (int) ($claims['iat'] ?? 0);
        $expiresAt = (int) ($claims['exp'] ?? 0);
        $nonce = is_string($claims['nonce'] ?? null) ? trim((string) $claims['nonce']) : '';
        $bodyHash = is_string($claims['body_sha256'] ?? null) ? (string) $claims['body_sha256'] : '';

        if ($version !== self::VERSION
            || $networkId !== $this->sites->networkId()
            || $targetSiteId !== $expectedSiteId
            || $userId < 1
            || $operation !== $expectedOperation
            || $issuedAt < 1
            || $expiresAt < $issuedAt
            || $expiresAt - $issuedAt > self::TTL_SECONDS
            || $issuedAt > $now + self::CLOCK_SKEW_SECONDS
            || $expiresAt < $now
            || !preg_match('/^[A-Za-z0-9._-]{16,128}$/', $nonce)
            || !preg_match('/^[a-f0-9]{64}$/', $bodyHash)
            || !hash_equals($bodyHash, hash('sha256', $body))) {
            return $this->error('codi_multisite_bad_assertion', 'The federation assertion is invalid or expired.', 401);
        }

        return $claims;
    }

    private function secret(): string
    {
        if (is_string($this->explicitSecret) && $this->explicitSecret !== '') {
            return hash_hmac('sha256', 'network:' . $this->sites->networkId(), $this->explicitSecret, true);
        }
        if (!function_exists('wp_salt')) {
            throw new \RuntimeException('WordPress authentication salts are unavailable for multisite federation.');
        }
        $salt = (string) wp_salt('auth');
        if ($salt === '') {
            throw new \RuntimeException('WordPress authentication salt is empty; multisite federation cannot be signed.');
        }
        return hash_hmac('sha256', 'codi-mcp-federation|network:' . $this->sites->networkId(), $salt, true);
    }

    /** @param array<string,mixed> $value */
    private function encode(array $value): string
    {
        if (function_exists('wp_json_encode')) {
            $json = wp_json_encode($value, JSON_UNESCAPED_SLASHES);
        } else {
            $json = json_encode($value, JSON_UNESCAPED_SLASHES);
        }
        if (!is_string($json)) {
            throw new \RuntimeException('Unable to encode multisite federation assertion.');
        }
        return $json;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        if ($value === '' || preg_match('/[^A-Za-z0-9_-]/', $value)) {
            return null;
        }
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return is_string($decoded) ? $decoded : null;
    }

    private function error(string $code, string $message, int $status): \WP_Error
    {
        return new \WP_Error($code, $message, array('status' => $status));
    }
}
