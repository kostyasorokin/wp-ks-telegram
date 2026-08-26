<?php
/**
 * WordPress HTTP client adapter.
 *
 * This file wraps WordPress HTTP functions so Telegram API code can stay
 * focused on Telegram payloads and response handling.
 *
 * @author  Konstantin Sorokin
 * @link    https://konstantinsorokin.com
 * @package KS_Telegram
 */

declare(strict_types=1);

namespace KonstantinSorokin\Telegram\Http;

use WP_Error;

defined('ABSPATH') || exit;

/**
 * Small HTTP adapter around WordPress HTTP functions.
 */
final readonly class WpHttpClient {

    /**
     * Send a GET request.
     *
     * @param string               $url     Request URL.
     * @param array<string,string> $headers Request headers.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function get(string $url, array $headers = []): array|WP_Error {
        $response = wp_remote_get(
            $url,
            [
                'timeout'     => 15,
                'redirection' => 3,
                'headers'     => $headers,
            ]
        );

        return $this->normalizeResponse($response);
    }

    /**
     * Send a POST request.
     *
     * @param string              $url  Request URL.
     * @param array<string,mixed> $body Request body.
     * @param array<string,string> $headers Request headers.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function post(string $url, array $body, array $headers = []): array|WP_Error {
        $response = wp_remote_post(
            $url,
            [
                'timeout'     => 15,
                'redirection' => 3,
                'headers'     => $headers,
                'body'        => $body,
            ]
        );

        return $this->normalizeResponse($response);
    }

    /**
     * Normalize a WordPress HTTP response.
     *
     * @param array<string,mixed>|WP_Error $response Raw HTTP response.
     *
     * @return array<string,mixed>|WP_Error
     */
    private function normalizeResponse(array|WP_Error $response): array|WP_Error {
        if (is_wp_error($response)) {
            return $response;
        }

        return [
            'code' => (int) wp_remote_retrieve_response_code($response),
            'body' => (string) wp_remote_retrieve_body($response),
        ];
    }
}
