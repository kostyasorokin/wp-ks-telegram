<?php
/**
 * Telegram Bot API manager.
 *
 * This file creates shared TelegramBot/Api objects from WordPress settings and
 * converts low-level setup failures into WordPress-friendly errors.
 *
 * @author  Konstantin Sorokin
 * @link    https://konstantinsorokin.com
 * @package KS_Telegram
 */

declare(strict_types=1);

namespace KonstantinSorokin\Telegram\Bot;

use TelegramBot\Api\BotApi;
use TelegramBot\Api\Client;
use TelegramBot\Api\Types\Update;
use KonstantinSorokin\Telegram\Settings\SettingsRepository;
use Throwable;
use WP_Error;

defined('ABSPATH') || exit;

/**
 * Provides configured TelegramBot/Api instances.
 */
final class BotApiManager {

    private ?BotApi $api = null;

    private ?Client $client = null;

    public function __construct(private readonly SettingsRepository $settings) {}

    /**
     * Get the configured raw Telegram Bot API client.
     *
     * @return BotApi|WP_Error
     */
    public function api(): BotApi|WP_Error {
        $token = $this->token();
        if (is_wp_error($token)) {
            return $token;
        }

        try {
            return $this->api ??= new BotApi($token);
        } catch (Throwable $exception) {
            return $this->error($exception, 'ks_telegram_bot_api_unavailable');
        }
    }

    /**
     * Get the configured Telegram bot client for command and update handlers.
     *
     * @return Client|WP_Error
     */
    public function client(): Client|WP_Error {
        $token = $this->token();
        if (is_wp_error($token)) {
            return $token;
        }

        try {
            return $this->client ??= new Client($token);
        } catch (Throwable $exception) {
            return $this->error($exception, 'ks_telegram_bot_client_unavailable');
        }
    }

    /**
     * Convert a webhook payload into a TelegramBot/Api Update object.
     *
     * @param array<string,mixed> $payload Telegram webhook payload.
     *
     * @return Update|WP_Error
     */
    public function updateFromPayload(array $payload): Update|WP_Error {
        try {
            return Update::fromResponse($payload);
        } catch (Throwable $exception) {
            return $this->error($exception, 'ks_telegram_invalid_update');
        }
    }

    /**
     * Get the configured bot token.
     *
     * @return string|WP_Error
     */
    private function token(): string|WP_Error {
        $token = $this->settings->botToken();

        if ('' === $token) {
            return new WP_Error(
                'ks_telegram_missing_token',
                __('Telegram bot token is not configured.', 'ks-telegram'),
                ['status' => 400]
            );
        }

        return $token;
    }

    /**
     * Convert setup exceptions into a safe WordPress error.
     *
     * @param Throwable $exception Failure thrown by TelegramBot/Api.
     * @param string    $code      WordPress error code.
     *
     * @return WP_Error
     */
    private function error(Throwable $exception, string $code): WP_Error {
        $status  = $exception->getCode();
        $status  = $status >= 400 && $status <= 599 ? $status : 500;
        $message = sanitize_text_field($exception->getMessage());

        return new WP_Error(
            $code,
            '' !== $message ? $message : __('Telegram Bot API is unavailable.', 'ks-telegram'),
            ['status' => $status]
        );
    }
}
