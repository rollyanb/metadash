<?php
// includes/crypto.php — cifragem de segredos em repouso (token Meta)
// Chave lida de config/.enc_key (bloqueado via .htaccess). Sem chave => grava em texto puro (compatível com versão antiga).

class Crypto {
    const PREFIX = 'enc:v1:';

    private static function key() {
        static $key = null;
        if ($key !== null) return $key;
        $key = '';
        if (defined('TOKEN_ENC_KEY') && TOKEN_ENC_KEY !== '') {
            $key = TOKEN_ENC_KEY;
        } else {
            $f = dirname(__DIR__) . '/config/.enc_key';
            if (is_readable($f)) $key = trim(file_get_contents($f));
        }
        return $key;
    }

    public static function enabled() {
        return self::key() !== '' && function_exists('openssl_encrypt');
    }

    public static function encrypt($plain) {
        if ($plain === null || $plain === '') return $plain;
        if (!self::enabled()) return $plain;
        $k  = hash('sha256', self::key(), true);
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', $k, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) return $plain;
        return self::PREFIX . base64_encode($iv . $tag . $ct);
    }

    public static function decrypt($stored) {
        if ($stored === null || strpos($stored, self::PREFIX) !== 0) return $stored; // texto puro (legado)
        if (!self::enabled()) return null;
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 29) return null;
        $k   = hash('sha256', self::key(), true);
        $iv  = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct  = substr($raw, 28);
        $pt  = openssl_decrypt($ct, 'aes-256-gcm', $k, OPENSSL_RAW_DATA, $iv, $tag);
        return $pt === false ? null : $pt;
    }

    public static function isEncrypted($stored) {
        return is_string($stored) && strpos($stored, self::PREFIX) === 0;
    }
}
