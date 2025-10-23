<?php

namespace CaddyApi;

use PDO;
use DateTime;

class AuthService
{
    public static function findUserByUsuario(PDO $pdo, string $usuario): ?array
    {
        $q = $pdo->prepare("SELECT id, Estado, password_hash FROM usuarios WHERE Usuario=? LIMIT 1");
        $q->execute([$usuario]);
        $r = $q->fetch();
        return $r ?: null;
    }

    // Refresh rotativo: selector público + token secreto (hash en DB)
    public static function issueRefresh(PDO $pdo, int $userId): array
    {
        $selector = bin2hex(random_bytes(9));   // 18
        $token    = bin2hex(random_bytes(32));  // 64
        $tokenHash = hash('sha256', $token);
        $exp      = (new DateTime('+30 days'))->format('Y-m-d H:i:s');

        // (opcional) invalidar anteriores del mismo user
        $pdo->prepare("UPDATE auth_refresh_tokens SET revoked_at=NOW() WHERE user_id=? AND revoked_at IS NULL")->execute([$userId]);

        $stmt = $pdo->prepare("INSERT INTO auth_refresh_tokens (user_id, token_hash, selector, ip, user_agent, expires_at) 
                           VALUES (?,?,?,?,?,?)");
        $stmt->execute([$userId, $tokenHash, $selector, Helpers::ip(), Helpers::ua(), $exp]);

        return ['selector' => $selector, 'token' => $token, 'expires_at' => $exp];
    }

    public static function rotateRefresh(PDO $pdo, string $selector, string $token): ?array
    {
        $stmt = $pdo->prepare("SELECT id, user_id, token_hash, expires_at, revoked_at FROM auth_refresh_tokens WHERE selector=? LIMIT 1");
        $stmt->execute([$selector]);
        $row = $stmt->fetch();
        if (!$row) return null;
        if ($row['revoked_at']) return null;
        if (new DateTime() > new DateTime($row['expires_at'])) return null;
        if (!hash_equals($row['token_hash'], hash('sha256', $token))) return null;

        // revocar el actual y emitir uno nuevo
        $pdo->prepare("UPDATE auth_refresh_tokens SET revoked_at=NOW() WHERE id=?")->execute([$row['id']]);
        return self::issueRefresh($pdo, (int)$row['user_id']);
    }

    public static function logout(PDO $pdo, string $selector): void
    {
        $pdo->prepare("UPDATE auth_refresh_tokens SET revoked_at=NOW() WHERE selector=?")->execute([$selector]);
    }
}
