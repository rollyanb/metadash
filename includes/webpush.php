<?php
// includes/webpush.php — envio de Web Push sem dependências externas.
//
// Substitui a biblioteca minishlink/web-push (que exigia a pasta vendor/).
// Usa apenas extensões nativas do PHP: openssl, curl e hash_hkdf.
//
// Implementa:
//   • VAPID (RFC 8292) — JWT assinado em ES256
//   • Criptografia de payload aes128gcm (RFC 8291)
//
// Uso:
//   $push = new SimpleWebPush($publicKey, $privateKey, 'mailto:voce@dominio.com');
//   $r = $push->send($endpoint, $p256dh, $auth, json_encode([...]));
//   // $r = ['ok' => bool, 'status' => int, 'error' => string|null, 'expirado' => bool]

class SimpleWebPush {

    private $vapidPublic;
    private $vapidPrivate;
    private $subject;

    public function __construct($vapidPublic, $vapidPrivate, $subject) {
        $this->vapidPublic  = $vapidPublic;
        $this->vapidPrivate = $vapidPrivate;
        $this->subject      = $subject;
    }

    /** O ambiente tem tudo o que é necessário? */
    public static function disponivel(&$motivo = null) {
        if (!function_exists('openssl_sign'))      { $motivo = 'extensão openssl ausente'; return false; }
        if (!function_exists('hash_hkdf'))         { $motivo = 'PHP sem hash_hkdf (requer 7.1+)'; return false; }
        if (!function_exists('curl_init'))         { $motivo = 'extensão curl ausente'; return false; }
        if (!in_array('prime256v1', openssl_get_curve_names() ?: [])) {
            $motivo = 'openssl sem a curva prime256v1'; return false;
        }
        return true;
    }

    // ── base64url ────────────────────────────────────────────────
    private static function b64e($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    private static function b64d($data) {
        $data = strtr($data, '-_', '+/');
        $pad  = strlen($data) % 4;
        if ($pad) $data .= str_repeat('=', 4 - $pad);
        return base64_decode($data);
    }

    /**
     * Gera o cabeçalho Authorization com o JWT do VAPID.
     */
    private function vapidHeader($endpoint) {
        $partes = parse_url($endpoint);
        $aud = $partes['scheme'] . '://' . $partes['host'];

        $header  = self::b64e(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $payload = self::b64e(json_encode([
            'aud' => $aud,
            'exp' => time() + 43200,           // 12h
            'sub' => $this->subject,
        ]));
        $assinar = $header . '.' . $payload;

        $pkey = $this->pemDaChavePrivada($this->vapidPrivate, $this->vapidPublic);
        if (!$pkey) throw new Exception('Chave VAPID inválida');

        $der = '';
        if (!openssl_sign($assinar, $der, $pkey, OPENSSL_ALGO_SHA256)) {
            throw new Exception('Falha ao assinar o JWT do VAPID');
        }
        $sig = self::derParaRaw($der);   // ES256 exige assinatura crua de 64 bytes

        return [
            'Authorization: vapid t=' . $assinar . '.' . self::b64e($sig) . ', k=' . $this->vapidPublic,
        ];
    }

    /** Monta uma chave privada EC em PEM a partir dos bytes crus. */
    private function pemDaChavePrivada($privB64, $pubB64) {
        $priv = self::b64d($privB64);
        $pub  = self::b64d($pubB64);
        if (strlen($priv) !== 32 || strlen($pub) !== 65) return null;

        // SEQUENCE(version, privateKey, [0] OID prime256v1, [1] publicKey)
        $der = "\x30\x77\x02\x01\x01\x04\x20" . $priv
             . "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"
             . "\xa1\x44\x03\x42\x00" . $pub;
        $pem = "-----BEGIN EC PRIVATE KEY-----\n"
             . chunk_split(base64_encode($der), 64, "\n")
             . "-----END EC PRIVATE KEY-----\n";
        return openssl_pkey_get_private($pem);
    }

    /** Converte a assinatura DER do OpenSSL para o formato cru (r||s, 64 bytes). */
    private static function derParaRaw($der) {
        $pos = 0;
        if (ord($der[$pos++]) !== 0x30) throw new Exception('Assinatura DER inválida');
        if (ord($der[$pos]) & 0x80) $pos += (ord($der[$pos]) & 0x7f) + 1; else $pos++;

        $ler = function () use ($der, &$pos) {
            if (ord($der[$pos++]) !== 0x02) throw new Exception('INTEGER esperado na assinatura');
            $len = ord($der[$pos++]);
            $val = substr($der, $pos, $len);
            $pos += $len;
            $val = ltrim($val, "\x00");
            return str_pad($val, 32, "\x00", STR_PAD_LEFT);
        };
        return $ler() . $ler();
    }

    /**
     * Criptografa o payload no esquema aes128gcm (RFC 8291).
     */
    private function criptografar($payload, $p256dhB64, $authB64) {
        $clientPub = self::b64d($p256dhB64);
        $authSecret = self::b64d($authB64);
        if (strlen($clientPub) !== 65) throw new Exception('Chave p256dh inválida');

        // par efêmero do servidor
        $ec = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if (!$ec) throw new Exception('Falha ao gerar chave efêmera');
        $det = openssl_pkey_get_details($ec);
        $serverPub = "\x04" . str_pad($det['ec']['x'], 32, "\x00", STR_PAD_LEFT)
                            . str_pad($det['ec']['y'], 32, "\x00", STR_PAD_LEFT);

        // segredo compartilhado (ECDH)
        $clientPem = self::pemDaChavePublica($clientPub);
        $shared = openssl_pkey_derive($clientPem, $ec, 32);
        if ($shared === false) throw new Exception('Falha no ECDH (openssl_pkey_derive)');

        // PRK e chaves, conforme RFC 8291
        $info = "WebPush: info\x00" . $clientPub . $serverPub;
        $ikm  = hash_hkdf('sha256', $shared, 32, $info, $authSecret);

        $salt = random_bytes(16);
        $cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00",     $salt);

        // padding delimiter + AES-128-GCM
        $tag = '';
        $texto = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($texto === false) throw new Exception('Falha ao criptografar o payload');

        // cabeçalho aes128gcm: salt(16) | rs(4) | idlen(1) | serverPub(65)
        $corpo = $salt . pack('N', 4096) . chr(strlen($serverPub)) . $serverPub . $texto . $tag;
        return $corpo;
    }

    /** Monta um PEM de chave pública EC a partir dos 65 bytes crus. */
    private static function pemDaChavePublica($raw) {
        $der = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"
             . "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00" . $raw;
        $pem = "-----BEGIN PUBLIC KEY-----\n"
             . chunk_split(base64_encode($der), 64, "\n")
             . "-----END PUBLIC KEY-----\n";
        return openssl_pkey_get_public($pem);
    }

    /**
     * Envia a notificação. Retorna ['ok','status','error','expirado'].
     * expirado = true quando a inscrição não vale mais (404/410) e deve ser apagada.
     */
    public function send($endpoint, $p256dh, $auth, $payload, $ttl = 2419200) {
        try {
            $corpo   = $this->criptografar($payload, $p256dh, $auth);
            $headers = array_merge($this->vapidHeader($endpoint), [
                'Content-Type: application/octet-stream',
                'Content-Encoding: aes128gcm',
                'TTL: ' . (int)$ttl,
                'Urgency: normal',
                'Content-Length: ' . strlen($corpo),
            ]);
        } catch (Exception $e) {
            return ['ok' => false, 'status' => 0, 'error' => $e->getMessage(), 'expirado' => false];
        }

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $corpo,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $erroC  = curl_error($ch);
        curl_close($ch);

        $ok = $status >= 200 && $status < 300;
        return [
            'ok'       => $ok,
            'status'   => $status,
            'error'    => $ok ? null : ($erroC ?: trim((string)$resp)),
            'expirado' => in_array($status, [404, 410]),
        ];
    }
}
