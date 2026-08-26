<?php
/**
 * Telegram webhook REST controller.
 *
 * This file registers a secure REST endpoint that receives Telegram bot
 * updates and exposes them through WordPress actions for other plugins.
 *
 * @author  Konstantin Sorokin
 * @link    https://konstantinsorokin.com
 * @package KS_Telegram
 */

declare(strict_types=1);

namespace KonstantinSorokin\Telegram\Bot;

use KonstantinSorokin\Telegram\Settings\SettingsRepository;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined('ABSPATH') || exit;

/**
 * Registers and handles Telegram webhook requests.
 */
final readonly class WebhookController {

    public function __construct(
        private SettingsRepository $settings,
        private BotApiManager $bot_api,
        private ChatRegistry $chats
    ) {}

    /**
     * Register REST hooks.
     *
     * @return void
     */
    public function boot(): void {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    /**
     * Register the webhook route.
     *
     * @return void
     */
    public function registerRoutes(): void {
        register_rest_route(
            'ks-telegram/v1',
            '/webhook/(?P<secret>[A-Za-z0-9_-]+)',
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'handle'],
                'permission_callback' => [$this, 'canReceive'],
            ]
        );
    }

    /**
     * Validate webhook access.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return bool|WP_Error
     */
    public function canReceive(WP_REST_Request $request): bool|WP_Error {
        if (! $this->settings->bool('webhook_enabled')) {
            return new WP_Error(
                'ks_telegram_webhook_disabled',
                __('Telegram webhook is disabled.', 'ks-telegram'),
                ['status' => 403]
            );
        }

        $expected      = $this->settings->string('webhook_secret');
        $route_secret  = (string) $request->get_param('secret');
        $header_secret = (string) $request->get_header('x-telegram-bot-api-secret-token');

        if ('' === $expected || ! hash_equals($expected, $route_secret)) {
            return new WP_Error(
                'ks_telegram_invalid_webhook_secret',
                __('Invalid webhook secret.', 'ks-telegram'),
                ['status' => 403]
            );
        }

        /*
         * The header is required, not merely checked when offered.
         *
         * It used to be conditional — `'' !== $header_secret && ! hash_equals(…)`
         * — which meant that simply omitting the header skipped the check
         * entirely, leaving the path segment as the only credential. A secret
         * in the request line is written to every access log, forwarded in
         * `Referer`, and kept in proxy logs the site owner does not control,
         * so treating it as the sole credential is the weakest possible
         * arrangement.
         *
         * Requiring the header costs nothing: `setWebhook` registers it
         * (TelegramApiClient::setWebhook), so Telegram sends it on every
         * delivery. A caller that has read the secret out of a log but cannot
         * replay the header no longer gets in.
         */
        if ('' === $header_secret || ! hash_equals($expected, $header_secret)) {
            return new WP_Error(
                'ks_telegram_invalid_webhook_header',
                __('Invalid webhook header.', 'ks-telegram'),
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * Handle Telegram update payloads.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function handle(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $payload = $request->get_json_params();

        if (! is_array($payload)) {
            return new WP_Error(
                'ks_telegram_invalid_webhook_payload',
                __('Invalid Telegram update payload.', 'ks-telegram'),
                ['status' => 400]
            );
        }

        $this->chats->recordUpdatePayload($payload);

        /**
         * Fires when a Telegram webhook update is received.
         *
         * @param array<string,mixed> $payload Telegram update payload.
         * @param WP_REST_Request     $request REST request object.
         */
        do_action('ks_telegram_webhook_update', $payload, $request);

        $update = $this->bot_api->updateFromPayload($payload);
        if (is_wp_error($update)) {
            return $update;
        }

        /**
         * Fires when a Telegram webhook update has been parsed by TelegramBot/Api.
         *
         * @param \TelegramBot\Api\Types\Update $update  Parsed Telegram update.
         * @param array<string,mixed>           $payload Raw Telegram update payload.
         * @param WP_REST_Request               $request REST request object.
         */
        do_action('ks_telegram_bot_update', $update, $payload, $request);

        $client = $this->bot_api->client();
        if (is_wp_error($client)) {
            return new WP_REST_Response(['ok' => true], 200);
        }

        /**
         * Register TelegramBot/Api command and update handlers before dispatch.
         *
         * @param \TelegramBot\Api\Client $client  TelegramBot/Api client.
         * @param BotApiManager           $bot_api Shared Bot API manager.
         * @param array<string,mixed>     $payload Raw Telegram update payload.
         * @param WP_REST_Request         $request REST request object.
         */
        do_action('ks_telegram_register_bot_handlers', $client, $this->bot_api, $payload, $request);

        try {
            $client->handle([$update]);
        } catch (Throwable $exception) {
            /**
             * Fires when a registered TelegramBot/Api handler throws an error.
             *
             * @param Throwable                       $exception Handler exception.
             * @param \TelegramBot\Api\Types\Update   $update    Parsed Telegram update.
             * @param array<string,mixed>             $payload   Raw Telegram update payload.
             * @param WP_REST_Request                 $request   REST request object.
             */
            do_action('ks_telegram_bot_handler_error', $exception, $update, $payload, $request);
        }

        return new WP_REST_Response(['ok' => true], 200);
    }
}
