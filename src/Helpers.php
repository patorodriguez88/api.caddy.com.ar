<?php

namespace CaddyApi;

class Helpers
{
    public static function json(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
    public static function ip(): ?string
    {
        return $_SERVER['REMOTE_ADDR'] ?? null;
    }
    public static function ua(): string
    {
        return substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    }
    public static function bearer(): ?string
    {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (stripos($h, 'Bearer ') === 0) return trim(substr($h, 7));
        return null;
    }
    public static function cors(): void
    {
        header('Access-Control-Allow-Origin: https://www.sistema.caddy.com.ar');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
        header('Content-Type: application/json; charset=utf-8');
    }
}
