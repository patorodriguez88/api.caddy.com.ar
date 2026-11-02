<?php
// Acentos y eñes perdidos (2/11/2026). Algunos clientes (VENEX, Ferniplast) mandan por API el nombre y
// la dirección del destinatario con "?" en lugar de la letra acentuada ("Mu?oz", "Mar?a", "Jazm?n"):
// la conversión la hace su sistema antes de enviar, a nosotros ya nos llega el "?". Se reconstruye la
// palabra con un diccionario armado con los nombres y calles que sí llegaron bien escritos (acentos.json:
// palabra con "?" en lugar de cada letra acentuada => palabra correcta). Si la palabra no está en el
// diccionario, queda como vino. Variantes que también llegan: "??" por una sola letra y la vocal seguida
// de "?" (acento separado: "Mati?as" = Matías).
class Acentos
{
    private static ?array $dic = null;

    private static function dic(): array
    {
        if (self::$dic === null) {
            $json = @file_get_contents(__DIR__ . '/acentos.json');
            self::$dic = $json ? (json_decode($json, true) ?: []) : [];
        }
        return self::$dic;
    }

    private static function mismoFormato(string $original, string $palabra): string
    {
        if ($original === mb_strtoupper($original, 'UTF-8')) return mb_strtoupper($palabra, 'UTF-8');
        $primera = mb_substr($original, 0, 1, 'UTF-8');
        if ($primera === mb_strtoupper($primera, 'UTF-8') && $primera !== '?') {
            return mb_strtoupper(mb_substr($palabra, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($palabra, 1, null, 'UTF-8');
        }
        return $palabra;
    }

    private static function palabra(string $w): ?string
    {
        $dic = self::dic();
        $l = mb_strtolower($w, 'UTF-8');
        if (isset($dic[$l])) return $dic[$l];
        $l = preg_replace('/\?\?+/', '?', $l);
        if (isset($dic[$l])) return $dic[$l];
        $vocales = ['a' => 'á', 'e' => 'é', 'i' => 'í', 'o' => 'ó', 'u' => 'ú'];
        $x = preg_replace_callback('/([aeiou])\?/', fn($m) => $vocales[$m[1]], $l);
        if (strpos($x, '?') === false) {
            $patron = preg_replace('/[áéíóúñü]/u', '?', $x);
            if (($dic[$patron] ?? null) === $x) return $x;
        }
        return null;
    }

    public static function reparar(?string $texto): ?string
    {
        if ($texto === null || strpos($texto, '?') === false) return $texto;
        return preg_replace_callback('/[\p{L}]*\?[\p{L}?]*/u', function ($m) {
            if (!preg_match('/\p{L}/u', $m[0])) return $m[0];
            $ok = self::palabra($m[0]);
            return $ok === null ? $m[0] : self::mismoFormato($m[0], $ok);
        }, $texto);
    }
}
