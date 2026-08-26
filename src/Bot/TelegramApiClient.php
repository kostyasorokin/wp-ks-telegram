<?php
/**
 * Telegram Bot API client.
 *
 * This file adapts the TelegramBot/Api Composer package to WordPress settings,
 * WordPress errors, and the public plugin API.
 *
 * @author  Konstantin Sorokin
 * @link    https://konstantinsorokin.com
 * @package KS_Telegram
 */

declare(strict_types=1);

namespace KonstantinSorokin\Telegram\Bot;

use KonstantinSorokin\Telegram\Settings\SettingsRepository;
use TelegramBot\Api\Exception as TelegramApiException;
use Throwable;
use WP_Error;

defined('ABSPATH') || exit;

/**
 * WordPress-friendly Telegram Bot API adapter.
 */
final readonly class TelegramApiClient {

    public function __construct(
        private SettingsRepository $settings,
        private BotApiManager $bot_api
    ) {}

    /**
     * Send a message to one Telegram chat.
     *
     * @param string|int          $chat_id Chat ID.
     * @param string              $text    Message text.
     * @param array<string,mixed> $args    Additional sendMessage arguments.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function sendMessage(string|int $chat_id, string $text, array $args = []): array|WP_Error {
        $payload = array_merge(
            $args,
            [
                'chat_id' => (string) $chat_id,
                'text'    => $text,
            ]
        );

        if (isset($payload['reply_markup']) && is_array($payload['reply_markup'])) {
            $payload['reply_markup'] = wp_json_encode($payload['reply_markup']) ?: '{}';
        }

        return $this->request('sendMessage', $payload);
    }

    /**
     * Ask Telegram for bot metadata.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function getMe(): array|WP_Error {
        return $this->request('getMe');
    }

    /**
     * Register a webhook with Telegram.
     *
     * @param string $url Webhook URL.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function setWebhook(string $url): array|WP_Error {
        return $this->request(
            'setWebhook',
            [
                'url'                  => esc_url_raw($url),
                'drop_pending_updates' => 'true',
                'secret_token'         => $this->settings->string('webhook_secret'),
            ]
        );
    }

    /**
     * Delete the current webhook.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function deleteWebhook(): array|WP_Error {
        return $this->request('deleteWebhook');
    }

    /**
     * Call a Telegram Bot API method.
     *
     * @param string              $method API method.
     * @param array<string,mixed> $args   Method arguments.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function request(string $method, array $args = []): array|WP_Error {
        if (1 !== preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $method)) {
            return new WP_Error(
                'ks_telegram_invalid_api_method',
                __('Invalid Telegram API method.', 'ks-telegram'),
                ['status' => 400]
            );
        }

        $bot = $this->bot_api->api();
        if (is_wp_error($bot)) {
            return $bot;
        }

        try {
            $result = $bot->call($method, $this->preparePayload($args));

            return [
                'ok'     => true,
                'result' => $result,
            ];
        } catch (TelegramApiException $exception) {
            return $this->error($exception);
        } catch (Throwable $exception) {
            return $this->error($exception, 'ks_telegram_api_unavailable');
        }
    }

    /**
     * Prepare payload values for TelegramBot/Api generic calls.
     *
     * @param array<string,mixed> $args Raw method arguments.
     *
     * @return array<string,mixed>
     */
    private function preparePayload(array $args): array {
        foreach ($args as $key => $value) {
            if (is_array($value)) {
                $args[$key] = wp_json_encode($value) ?: '[]';
            }
        }

        return $args;
    }

    /**
     * Convert TelegramBot/Api exceptions into WordPress errors.
     *
     * @param Throwable $exception Failure thrown by TelegramBot/Api.
     * @param string    $code      WordPress error code.
     *
     * @return WP_Error
     */
    private function error(Throwable $exception, string $code = 'ks_telegram_api_error'): WP_Error {
        $status  = $exception->getCode();
        $status  = $status >= 400 && $status <= 599 ? $status : 502;
        $message = sanitize_text_field($exception->getMessage());

        return new WP_Error(
            $code,
            '' !== $message ? $message : __('Telegram API request failed.', 'ks-telegram'),
            ['status' => $status]
        );
    }
}
