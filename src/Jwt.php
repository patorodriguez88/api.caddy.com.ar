<?php

declare(strict_types=1);

namespace CaddyApi;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * JWT helper for access tokens.
 * - Secret comes from env var CADDY_JWT_SECRET (or PHP constant CADDY_JWT_SECRET as fallback).
 * - Default TTL: 15 minutes (900s)
 * - Adds jti and kid.
 * - Supports configurable leeway to absorb small clock skews.
 */
class Jwt
{
    private const ALG = 'HS256';
    private const DEFAULT_ISS = 'https://api.caddy.com.ar';
    private const DEFAULT_AUD = 'https://api.caddy.com.ar';

    /** Resolve signing key from environment/constant */
    private static function key(): string
    {
        $key = getenv('CADDY_JWT_SECRET');
        if (!$key && \defined('CADDY_JWT_SECRET')) {
            $key = (string) CADDY_JWT_SECRET;
        }
        if (!$key) {
            // Fallback only for development; change ASAP in production.
            $key = 'CHANGE_ME__USE_ENV_CADDY_JWT_SECRET_64+_CHARS';
        }
        return $key;
    }

    /** Optional: adjust leeway (seconds) to tolerate clock skew, e.g., 30-60s */
    public static function setLeeway(int $seconds): void
    {
        JWT::$leeway = max(0, $seconds);
    }

    /**
     * Issue a signed access token (JWT).
     *
     * @param int         $userId     Subject/user id
     * @param array       $scopes     Array of scopes
     * @param int         $ttlSeconds Lifetime in seconds (default 900 = 15m)
     * @param string|null $jti        Custom token id (optional)
     */
    public static function issueAccessToken(
        int $userId,
        array $scopes = [],
        int $ttlSeconds = 900,
        ?string $jti = null
    ): string {
        $now = time();
        $exp = $now + max(60, $ttlSeconds); // at least 60s

        $payload = [
            'iss' => self::DEFAULT_ISS,
            'aud' => self::DEFAULT_AUD,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $exp,
            'sub' => $userId,
            'scp' => array_values($scopes),
            'jti' => $jti ?: bin2hex(random_bytes(8)),
        ];

        // kid derived from current key, helps rotate keys later
        $kid = substr(hash('sha256', self::key()), 0, 16);
        $headers = ['kid' => $kid];

        return JWT::encode($payload, self::key(), self::ALG, null, $headers);
    }

    /**
     * Verify and decode a JWT access token.
     * Also hard-checks iss and aud by default.
     */
    public static function verify(
        string $jwt,
        string $expectedIss = self::DEFAULT_ISS,
        string $expectedAud = self::DEFAULT_AUD
    ): object {
        $decoded = JWT::decode($jwt, new Key(self::key(), self::ALG));

        // Extra defense-in-depth
        if (($decoded->iss ?? null) !== $expectedIss) {
            throw new \UnexpectedValueException('Invalid token issuer (iss).');
        }
        if (($decoded->aud ?? null) !== $expectedAud) {
            throw new \UnexpectedValueException('Invalid token audience (aud).');
        }

        return $decoded;
    }

    /** Convenience: extract user id (sub) from a validated token string */
    public static function userIdFrom(string $jwt): int
    {
        $decoded = self::verify($jwt);
        return (int) ($decoded->sub ?? 0);
    }
}
