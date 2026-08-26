<?php
/**
 * Telegram OIDC token client.
 *
 * This file exchanges Telegram Login authorization codes for OIDC tokens using
 * the Authorization Code Flow with PKCE.
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
 * Exchanges authorization codes for Telegram OIDC tokens.
 */
final readonly class OidcTokenClient {

    private const string TOKEN_ENDPOINT = 'https://oauth.telegram.org/token';

    public function __construct(
        private SettingsRepository $settings,
        private WpHttpClient $http
    ) {}

    /**
     * Exchange an authorization code for tokens.
     *
     * @param string $code          Authorization code.
     * @param string $redirect_uri  Redirect URI registered with Telegram.
     * @param string $code_verifier PKCE verifier.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function exchangeCode(string $code, string $redirect_uri, string $code_verifier): array|WP_Error {
        $client_id     = $this->settings->loginClientId();
        $client_secret = $this->settings->loginClientSecret();

        if ('' === $client_id || '' === $client_secret) {
            return new WP_Error(
                'ks_telegram_login_missing_client_credentials',
                __('Telegram Login client ID and client secret are required.', 'ks-telegram')
            );
        }

        $response = $this->http->post(
            self::TOKEN_ENDPOINT,
            [
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'redirect_uri'  => $redirect_uri,
                'client_id'     => $client_id,
                'code_verifier' => $code_verifier,
            ],
            [
                'Authorization' => 'Basic ' . base64_encode($client_id . ':' . $client_secret),
                'Accept'        => 'application/json',
            ]
        );

        if (is_wp_error($response)) {
            return new WP_Error(
                'ks_telegram_login_token_http_error',
                __('Telegram token request failed.', 'ks-telegram')
            );
        }

        $body = json_decode((string) ($response['body'] ?? ''), true);
        if (! is_array($body)) {
            return new WP_Error(
                'ks_telegram_login_invalid_token_response',
                __('Telegram token response is invalid.', 'ks-telegram')
            );
        }

        if ((int) ($response['code'] ?? 500) >= 400 || empty($body['id_token'])) {
            return new WP_Error(
                'ks_telegram_login_token_error',
                sanitize_text_field((string) ($body['error_description'] ?? $body['error'] ?? __('Telegram token request was rejected.', 'ks-telegram')))
            );
        }

        return $body;
    }
}
