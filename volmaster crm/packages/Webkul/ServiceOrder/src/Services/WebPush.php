<?php

namespace Webkul\ServiceOrder\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Webkul\ServiceOrder\Models\PushSubscription;

/**
 * Envio de Web Push (RFC 8030) com payload criptografado (RFC 8291,
 * aes128gcm) e autenticação VAPID (RFC 8292), usando só a extensão OpenSSL
 * do PHP -- sem pacotes novos no Composer, já que o deploy é feito por zip.
 */
class WebPush
{
    /** Prefixos DER para montar chaves EC P-256 em PEM. */
    protected const SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /**
     * As duas chaves VAPID existem? (a pública sozinha não envia nada)
     */
    public static function configurado(): bool
    {
        return filled(WorkshopSettings::get('push_vapid_public')) && filled(WorkshopSettings::get('push_vapid_private'));
    }

    /**
     * Normaliza o contato do VAPID para o que a Apple e o Google aceitam: "mailto:e-mail" ou
     * uma URL https. Um e-mail digitado sem o "mailto:" ganha o prefixo; texto inválido e
     * e-mails de exemplo devolvem null.
     */
    public static function normalizarAssunto(?string $valor): ?string
    {
        $valor = trim((string) $valor);

        if ($valor === '') {
            return null;
        }

        if (str_starts_with(strtolower($valor), 'https://')) {
            return filter_var($valor, FILTER_VALIDATE_URL) ? $valor : null;
        }

        $email = preg_replace('/^mailto:/i', '', $valor);

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $dominio = strtolower(substr((string) strrchr($email, '@'), 1));

        return in_array($dominio, ['example.com', 'example.org', 'example.net'], true) ? null : 'mailto:'.$email;
    }

    /**
     * Gera o par de chaves VAPID: ['public' => base64url (65 bytes), 'private' => base64url (32 bytes)].
     */
    public static function generateVapidKeys(): array
    {
        $key = static::newEcKey();
        [$x, $y, $d] = static::ecParts($key);

        return [
            'public' => static::b64uEncode("\x04".$x.$y),
            'private' => static::b64uEncode($d),
        ];
    }

    /**
     * Envia $payload (array, vira JSON) para um dispositivo inscrito.
     *
     * @return array{ok: bool, status: int, gone: bool, error: ?string}
     */
    public function send(PushSubscription $subscription, array $payload, int $ttl = 3600, string $urgency = 'normal'): array
    {
        try {
            $body = $this->encrypt(
                json_encode($payload, JSON_UNESCAPED_UNICODE),
                static::b64uDecode($subscription->p256dh),
                static::b64uDecode($subscription->auth),
            );

            $response = Http::withHeaders([
                'Content-Encoding' => 'aes128gcm',
                'TTL' => (string) $ttl,
                'Urgency' => $urgency,
                'Authorization' => $this->vapidAuthorization($subscription->endpoint),
            ])->withBody($body, 'application/octet-stream')->timeout(10)->post($subscription->endpoint);
        } catch (\Throwable $exception) {
            return ['ok' => false, 'status' => 0, 'gone' => false, 'error' => $exception->getMessage()];
        }

        return [
            'ok' => $response->successful(),
            'status' => $response->status(),
            // 404/410: a inscrição não existe mais (app desinstalado).
            'gone' => in_array($response->status(), [404, 410], true),
            'error' => $response->successful() ? null : 'HTTP '.$response->status(),
        ];
    }

    /**
     * Corpo criptografado (RFC 8291 §3.4): salt || rs || idlen || keyid || registro.
     *
     * @param  array{d: string, public: string}|null  $ephemeral  chave efêmera fixa (só para teste)
     */
    public function encrypt(string $payload, string $uaPublic, string $authSecret, ?string $salt = null, ?array $ephemeral = null): string
    {
        if (strlen($uaPublic) !== 65 || strlen($authSecret) !== 16) {
            throw new RuntimeException('Chave p256dh/auth da inscrição inválida.');
        }

        $salt ??= random_bytes(16);

        if ($ephemeral) {
            $asPrivate = static::privateKeyFromD($ephemeral['d'], $ephemeral['public']);
            $asPublic = $ephemeral['public'];
        } else {
            $asPrivate = static::newEcKey();
            [$x, $y] = static::ecParts($asPrivate);
            $asPublic = "\x04".$x.$y;
        }

        $uaPublicKey = openssl_pkey_get_public(static::pemFromPublicPoint($uaPublic));

        if (! $uaPublicKey) {
            throw new RuntimeException('Não foi possível ler a chave pública do dispositivo.');
        }

        $ecdhSecret = openssl_pkey_derive($uaPublicKey, $asPrivate, 32);

        if ($ecdhSecret === false) {
            throw new RuntimeException('Falha no acordo de chaves ECDH.');
        }

        $keyInfo = "WebPush: info\0".$uaPublic.$asPublic;
        $ikm = hash_hkdf('sha256', $ecdhSecret, 32, $keyInfo, $authSecret);

        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        // Um único registro: dados + delimitador 0x02 (último registro).
        $tag = '';
        $cipher = openssl_encrypt($payload."\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);

        if ($cipher === false) {
            throw new RuntimeException('Falha ao criptografar a notificação.');
        }

        return $salt.pack('N', 4096).chr(strlen($asPublic)).$asPublic.$cipher.$tag;
    }

    /**
     * Cabeçalho "Authorization: vapid t=<jwt>, k=<chave pública>".
     */
    public function vapidAuthorization(string $endpoint): string
    {
        $public = (string) WorkshopSettings::get('push_vapid_public');
        $private = (string) WorkshopSettings::get('push_vapid_private');

        if (! $public || ! $private) {
            throw new RuntimeException('Chaves VAPID não configuradas (php artisan workshop:vapid-keys).');
        }

        $parts = parse_url($endpoint);
        $audience = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        $subject = static::normalizarAssunto(WorkshopSettings::get('push_vapid_subject')) ?: 'mailto:contato@volmastercrm.com';

        $header = static::b64uEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = static::b64uEncode(json_encode(['aud' => $audience, 'exp' => time() + 12 * 3600, 'sub' => $subject]));

        $signingKey = static::privateKeyFromD(static::b64uDecode($private), static::b64uDecode($public));

        if (! openssl_sign($header.'.'.$claims, $der, $signingKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Falha ao assinar o token VAPID.');
        }

        return 'vapid t='.$header.'.'.$claims.'.'.static::b64uEncode(static::derToRaw($der)).', k='.$public;
    }

    public static function b64uEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/').str_repeat('=', (4 - strlen($data) % 4) % 4));
    }

    protected static function newEcKey()
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);

        if (! $key) {
            throw new RuntimeException('Não foi possível gerar a chave EC (OpenSSL).');
        }

        return $key;
    }

    /**
     * [x, y, d] da chave, cada um com exatamente 32 bytes.
     */
    protected static function ecParts($key): array
    {
        $ec = openssl_pkey_get_details($key)['ec'];

        return [
            str_pad($ec['x'], 32, "\0", STR_PAD_LEFT),
            str_pad($ec['y'], 32, "\0", STR_PAD_LEFT),
            str_pad($ec['d'] ?? '', 32, "\0", STR_PAD_LEFT),
        ];
    }

    /**
     * Chave privada P-256 (OpenSSL) a partir de d (32 bytes) e do ponto público.
     */
    protected static function privateKeyFromD(string $d, string $publicPoint)
    {
        $der = hex2bin('30770201010420').$d.hex2bin('a00a06082a8648ce3d030107a14403420004').substr($publicPoint, 1);

        $pem = "-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END EC PRIVATE KEY-----\n";

        $key = openssl_pkey_get_private($pem);

        if (! $key) {
            throw new RuntimeException('Chave privada EC inválida.');
        }

        return $key;
    }

    protected static function pemFromPublicPoint(string $point): string
    {
        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin(self::SPKI_PREFIX).$point), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    /**
     * Assinatura ECDSA DER (SEQUENCE{INTEGER r, INTEGER s}) -> r||s de 64 bytes (JWS).
     */
    protected static function derToRaw(string $der): string
    {
        $offset = 2;

        if (ord($der[1]) & 0x80) {
            $offset += ord($der[1]) & 0x7F;
        }

        $parts = [];

        for ($i = 0; $i < 2; $i++) {
            $length = ord($der[$offset + 1]);
            $integer = substr($der, $offset + 2, $length);
            $parts[] = str_pad(ltrim($integer, "\0"), 32, "\0", STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $parts[0].$parts[1];
    }
}
