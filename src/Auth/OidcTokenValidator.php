<?php
/**
 * Telegram OIDC token validator.
 *
 * This file validates Telegram Login ID tokens against Telegram JWKS and checks
 * issuer, audience, expiration, and nonce claims.
 *
 * @author  Konstantin Sorokin
 * @link    https://konstantinsorokin.com
 * @package KS_Telegram
 */

declare(strict_types=1);

namespace KonstantinSorokin\Telegram\Auth;

use KonstantinSorokin\Telegram\Http\WpHttpClient;
use KonstantinSorokin\Telegram\Settings\SettingsRepository;
use WP_Error;

defined('ABSPATH') || exit;

/**
 * Validates Telegram OIDC ID tokens.
 */
final readonly class OidcTokenValidator {

    private const string ISSUER = 'https://oauth.telegram.org';
    private const string JWKS_ENDPOINT = 'https://oauth.telegram.org/.well-known/jwks.json';
    private const string JWKS_TRANSIENT = 'ks_telegram_oidc_jwks';

    public function __construct(
        private SettingsRepository $settings,
        private WpHttpClient $http
    ) {}

    /**
     * Validate an ID token and return its claims.
     *
     * @param string $id_token       JWT ID token.
     * @param string $expected_nonce Nonce saved before redirecting to Telegram.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function validate(string $id_token, string $expected_nonce): array|WP_Error {
        $parts = explode('.', $id_token);
        if (3 !== count($parts)) {
            return new WP_Error('ks_telegram_login_invalid_id_token', __('Telegram ID token is malformed.', 'ks-telegram'));
        }

        [$header_part, $payload_part, $signature_part] = $parts;
        $header = json_decode((string) $this->base64UrlDecode($header_part), true);
        $claims = json_decode((string) $this->base64UrlDecode($payload_part), true);

        if (! is_array($header) || ! is_array($claims)) {
            return new WP_Error('ks_telegram_login_invalid_id_token_json', __('Telegram ID token JSON is invalid.', 'ks-telegram'));
        }

        if ('RS256' !== ($header['alg'] ?? '')) {
            return new WP_Error('ks_telegram_login_invalid_id_token_alg', __('Telegram ID token algorithm is not supported.', 'ks-telegram'));
        }

        $pem = $this->publicKeyForHeader($header);
        if (is_wp_error($pem)) {
            return $pem;
        }

        $signature = $this->base64UrlDecode($signature_part);
        if (false === $signature) {
            return new WP_Error('ks_telegram_login_invalid_id_token_signature', __('Telegram ID token signature is invalid.', 'ks-telegram'));
        }

        $verified = openssl_verify($header_part . '.' . $payload_part, $signature, $pem, OPENSSL_ALGO_SHA256);
        if (1 !== $verified) {
            return new WP_Error('ks_telegram_login_id_token_not_verified', __('Telegram ID token signature could not be verified.', 'ks-telegram'));
        }

        return $this->validateClaims($claims, $expected_nonce);
    }

    /**
     * Validate token claims.
     *
     * @param array<string,mixed> $claims         Token claims.
     * @param string              $expected_nonce Expected nonce.
     *
     * @return array<string,mixed>|WP_Error
     */
    private function validateClaims(array $claims, string $expected_nonce): array|WP_Error {
        if (self::ISSUER !== ($claims['iss'] ?? '')) {
            return new WP_Error('ks_telegram_login_invalid_issuer', __('Telegram ID token issuer is invalid.', 'ks-telegram'));
        }

        $audience = $claims['aud'] ?? '';
        $audience = is_array($audience) ? array_map('strval', $audience) : [(string) $audience];
        if (! in_array($this->settings->loginClientId(), $audience, true)) {
            return new WP_Error('ks_telegram_login_invalid_audience', __('Telegram ID token audience is invalid.', 'ks-telegram'));
        }

        if (time() >= absint($claims['exp'] ?? 0)) {
            return new WP_Error('ks_telegram_login_expired_id_token', __('Telegram ID token has expired.', 'ks-telegram'));
        }

        if (isset($claims['nbf']) && time() < absint($claims['nbf'])) {
            return new WP_Error('ks_telegram_login_not_before_id_token', __('Telegram ID token is not valid yet.', 'ks-telegram'));
        }

        if ('' !== $expected_nonce && ! hash_equals($expected_nonce, (string) ($claims['nonce'] ?? ''))) {
            return new WP_Error('ks_telegram_login_invalid_nonce', __('Telegram Login nonce is invalid.', 'ks-telegram'));
        }

        if (empty($claims['sub'])) {
            return new WP_Error('ks_telegram_login_missing_subject', __('Telegram ID token subject is missing.', 'ks-telegram'));
        }

        return $claims;
    }

    /**
     * Get a public key for a JWT header.
     *
     * @param array<string,mixed> $header JWT header.
     *
     * @return string|WP_Error
     */
    private function publicKeyForHeader(array $header): string|WP_Error {
        $kid  = (string) ($header['kid'] ?? '');
        $jwks = $this->jwks();

        if (is_wp_error($jwks)) {
            return $jwks;
        }

        foreach ($jwks['keys'] ?? [] as $key) {
            if (! is_array($key) || ('' !== $kid && $kid !== (string) ($key['kid'] ?? ''))) {
                continue;
            }

            if (! empty($key['x5c'][0])) {
                return "-----BEGIN CERTIFICATE-----\n" . chunk_split((string) $key['x5c'][0], 64, "\n") . "-----END CERTIFICATE-----\n";
            }

            if ('RSA' === ($key['kty'] ?? '') && ! empty($key['n']) && ! empty($key['e'])) {
                return $this->rsaJwkToPem((string) $key['n'], (string) $key['e']);
            }
        }

        return new WP_Error('ks_telegram_login_missing_jwk', __('Telegram public key was not found.', 'ks-telegram'));
    }

    /**
     * Fetch Telegram JWKS.
     *
     * @return array<string,mixed>|WP_Error
     */
    private function jwks(): array|WP_Error {
        $cached = get_transient(self::JWKS_TRANSIENT);
        if (is_array($cached)) {
            return $cached;
        }

        $response = $this->http->get(self::JWKS_ENDPOINT, ['Accept' => 'application/json']);
        if (is_wp_error($response)) {
            return new WP_Error('ks_telegram_login_jwks_http_error', __('Telegram public keys request failed.', 'ks-telegram'));
        }

        $jwks = json_decode((string) ($response['body'] ?? ''), true);
        if (! is_array($jwks) || empty($jwks['keys']) || ! is_array($jwks['keys'])) {
            return new WP_Error('ks_telegram_login_invalid_jwks', __('Telegram public keys response is invalid.', 'ks-telegram'));
        }

        set_transient(self::JWKS_TRANSIENT, $jwks, 12 * HOUR_IN_SECONDS);

        return $jwks;
    }

    /**
     * Convert an RSA JWK to a PEM public key.
     *
     * @param string $modulus  Base64url-encoded RSA modulus.
     * @param string $exponent Base64url-encoded RSA exponent.
     *
     * @return string
     */
    private function rsaJwkToPem(string $modulus, string $exponent): string {
        $modulus_bytes  = $this->base64UrlDecode($modulus);
        $exponent_bytes = $this->base64UrlDecode($exponent);

        $rsa_public_key = $this->asn1Sequence(
            $this->asn1Integer(false === $modulus_bytes ? '' : $modulus_bytes)
            . $this->asn1Integer(false === $exponent_bytes ? '' : $exponent_bytes)
        );

        $algorithm = $this->asn1Sequence(
            $this->asn1Oid(hex2bin('2A864886F70D010101') ?: '')
            . $this->asn1Null()
        );

        $subject_public_key_info = $this->asn1Sequence(
            $algorithm . $this->asn1BitString($rsa_public_key)
        );

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($subject_public_key_info), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    /**
     * Decode a base64url string.
     *
     * @param string $value Encoded value.
     *
     * @return string|false
     */
    private function base64UrlDecode(string $value): string|false {
        return base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
    }

    /**
     * Encode an ASN.1 sequence.
     *
     * @param string $value Encoded content.
     *
     * @return string
     */
    private function asn1Sequence(string $value): string {
        return "\x30" . $this->asn1Length(strlen($value)) . $value;
    }

    /**
     * Encode an ASN.1 integer.
     *
     * @param string $value Raw integer bytes.
     *
     * @return string
     */
    private function asn1Integer(string $value): string {
        $value = ltrim($value, "\x00");
        if ('' === $value || (ord($value[0]) & 0x80)) {
            $value = "\x00" . $value;
        }

        return "\x02" . $this->asn1Length(strlen($value)) . $value;
    }

    /**
     * Encode an ASN.1 object identifier.
     *
     * @param string $value OID bytes.
     *
     * @return string
     */
    private function asn1Oid(string $value): string {
        return "\x06" . $this->asn1Length(strlen($value)) . $value;
    }

    /**
     * Encode ASN.1 NULL.
     *
     * @return string
     */
    private function asn1Null(): string {
        return "\x05\x00";
    }

    /**
     * Encode an ASN.1 bit string.
     *
     * @param string $value Raw bit string bytes.
     *
     * @return string
     */
    private function asn1BitString(string $value): string {
        $value = "\x00" . $value;

        return "\x03" . $this->asn1Length(strlen($value)) . $value;
    }

    /**
     * Encode an ASN.1 length.
     *
     * @param int $length Content length.
     *
     * @return string
     */
    private function asn1Length(int $length): string {
        if ($length < 128) {
            return chr($length);
        }

        $bytes = '';
        while ($length > 0) {
            $bytes  = chr($length & 0xff) . $bytes;
            $length >>= 8;
        }

        return chr(0x80 | strlen($bytes)) . $bytes;
    }
}
