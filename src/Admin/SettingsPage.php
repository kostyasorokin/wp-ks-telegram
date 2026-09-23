<?php
/**
 * Admin settings page.
 *
 * This file registers the Settings API page, handles admin-only test sends,
 * and renders the plugin configuration screen.
 *
 * @author  Konstantin Sorokin
 * @link    https://konstantinsorokin.com
 * @package KS_Telegram
 */

declare(strict_types=1);

namespace KonstantinSorokin\Telegram\Admin;

use KonstantinSorokin\Telegram\Bot\Enum\ParseMode;
use KonstantinSorokin\Telegram\Bot\Formatter;
use KonstantinSorokin\Telegram\Bot\ChatRegistry;
use KonstantinSorokin\Telegram\Bot\MessageSender;
use KonstantinSorokin\Telegram\Bot\TelegramApiClient;
use KonstantinSorokin\Telegram\Publishing\ChannelPublisher;
use KonstantinSorokin\Telegram\Settings\SettingsRepository;
use KonstantinSorokin\Telegram\Support\DeliveryLog;

defined('ABSPATH') || exit;

/**
 * Renders and processes admin settings.
 */
final readonly class SettingsPage {

    private const string NOTICE_TRANSIENT = 'ks_telegram_admin_notice_';

    public function __construct(
        private SettingsRepository $settings,
        private MessageSender $sender,
        private TelegramApiClient $client,
        private ChatRegistry $chats,
        private DeliveryLog $delivery_log
    ) {}

    /**
     * Register admin hooks.
     *
     * @return void
     */
    public function boot(): void {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_post_ks_telegram_test_message', [$this, 'handleTestMessage']);
        add_action('admin_post_ks_telegram_webhook_action', [$this, 'handleWebhookAction']);
        add_action('admin_post_ks_telegram_chat_id_helper', [$this, 'handleChatIdHelper']);
        add_action('update_option_' . SettingsRepository::OPTION_NAME, [$this, 'verifySavedToken'], 10, 2);
    }

    /**
     * Check a newly saved bot token against Telegram and say so.
     *
     * Saving used to be silent about correctness: `SettingsRepository::sanitize()`
     * is the only code that runs on save and it validates shape, never truth, so
     * a mistyped token was accepted without complaint and the first sign of
     * trouble was a notification that never arrived. `getMe()` existed on the
     * API client for exactly this and had no callers anywhere in the plugin.
     *
     * Only fires when the token actually changed, so an unrelated checkbox does
     * not cost a network round trip. The result goes through
     * `add_settings_error()`, which the settings template already renders via
     * `settings_errors()` — that call had nothing to display until now.
     *
     * @param mixed $old Previous option value.
     * @param mixed $new Newly saved option value.
     *
     * @return void
     */
    public function verifySavedToken(mixed $old, mixed $new): void {
        $old_token = is_array($old) ? (string) ($old['bot_token'] ?? '') : '';
        $new_token = is_array($new) ? (string) ($new['bot_token'] ?? '') : '';

        if ('' === $new_token || $old_token === $new_token) {
            return;
        }

        // The transients hold the previous verdict about the previous token.
        delete_transient('ks_telegram_health_bot');

        $result = $this->client->getMe();

        if (is_wp_error($result)) {
            add_settings_error(
                SettingsRepository::OPTION_NAME,
                'ks_telegram_bot_token',
                sprintf(
                    /* translators: %s: error message returned by Telegram. */
                    __('Settings saved, but Telegram refused the bot token: %s', 'ks-telegram'),
                    $result->get_error_message()
                ),
                'error'
            );

            return;
        }

        $username = is_array($result) ? (string) ($result['username'] ?? '') : '';

        add_settings_error(
            SettingsRepository::OPTION_NAME,
            'ks_telegram_bot_token',
            '' !== $username
                ? sprintf(
                    /* translators: %s: Telegram bot username. */
                    __('Bot token verified with Telegram: @%s', 'ks-telegram'),
                    $username
                )
                : __('Bot token verified with Telegram.', 'ks-telegram'),
            'success'
        );
    }

    /**
     * Add the settings menu item.
     *
     * @return void
     */
    public function registerMenu(): void {
        add_options_page(
            __('KS Telegram', 'ks-telegram'),
            __('KS Telegram', 'ks-telegram'),
            'manage_options',
            'ks-telegram',
            [$this, 'render']
        );
    }

    /**
     * Register the plugin option.
     *
     * @return void
     */
    public function registerSettings(): void {
        register_setting(
            'ks_telegram',
            SettingsRepository::OPTION_NAME,
            [
                'type'              => 'array',
                'sanitize_callback' => [$this->settings, 'sanitize'],
                'default'           => $this->settings->defaults(),
            ]
        );
    }

    /**
     * Enqueue admin assets only on the plugin settings page.
     *
     * @param string $hook_suffix Current admin page hook.
     *
     * @return void
     */
    public function enqueueAssets(string $hook_suffix): void {
        if ('settings_page_ks-telegram' !== $hook_suffix) {
            return;
        }

        wp_enqueue_style(
            'ks-telegram-admin',
            KS_TELEGRAM_URL . 'assets/css/admin.css',
            [],
            KS_TELEGRAM_VERSION
        );

        if (is_readable(KS_TELEGRAM_DIR . 'assets/js/admin.js')) {
            wp_enqueue_script(
                'ks-telegram-admin',
                KS_TELEGRAM_URL . 'assets/js/admin.js',
                [],
                KS_TELEGRAM_VERSION,
                true
            );
        }
    }

    /**
     * Render the settings page.
     *
     * @return void
     */
    public function render(): void {
        if (! current_user_can('manage_options')) {
            return;
        }

        $settings = $this->settings->all();
        $custom_post_types = ChannelPublisher::availableCustomPostTypes();
        $selected_custom_post_types = $this->settings->selectedCustomPostTypes();
        $notice   = $this->consumeNotice();
        $chat_id_helper_items = $this->chats->recent();

        // Read once here rather than from the template: the template is markup,
        // and a failure list it fetched itself would be a second source of
        // truth about delivery.
        $delivery_failures    = $this->delivery_log->failures();
        $delivery_last_ok     = $this->delivery_log->lastSuccess();
        $delivery_is_failing  = $this->delivery_log->isFailing();

        require KS_TELEGRAM_DIR . 'templates/admin/settings-page.php';
    }

    /**
     * Send a test message from the admin page.
     *
     * @return never
     */
    public function handleTestMessage(): never {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do this.', 'ks-telegram'));
        }

        check_admin_referer('ks_telegram_test_message');

        $message = isset($_POST['message'])
            ? sanitize_textarea_field(wp_unslash((string) $_POST['message']))
            : '';
        $chat_id = isset($_POST['chat_id'])
            ? sanitize_text_field(wp_unslash((string) $_POST['chat_id']))
            : '';

        $mode      = ParseMode::tryFrom($this->settings->string('parse_mode', 'MarkdownV2')) ?? ParseMode::MarkdownV2;
        $formatter = new Formatter($mode, $this->settings->messageHeaderOptions());
        $text      = $formatter->header('telegram') . $formatter->esc($message ?: __('KS Telegram test message.', 'ks-telegram'));
        $sent      = $this->sender->send('' !== $chat_id ? $chat_id : null, $text);

        $this->setNotice(
            $sent ? 'success' : 'error',
            $sent
                ? __('Test message sent.', 'ks-telegram')
                : __('Test message failed. Check the bot token and chat IDs.', 'ks-telegram')
        );

        wp_safe_redirect($this->settingsUrl());
        exit;
    }

    /**
     * Refresh or clear the Telegram chat ID helper.
     *
     * @return never
     */
    public function handleChatIdHelper(): never {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do this.', 'ks-telegram'));
        }

        check_admin_referer('ks_telegram_chat_id_helper');

        $command = isset($_POST['command'])
            ? sanitize_key(wp_unslash((string) $_POST['command']))
            : '';

        if ('clear' === $command) {
            $this->chats->clear();
            $this->setNotice('success', __('Chat ID helper list cleared.', 'ks-telegram'));
            wp_safe_redirect($this->settingsUrl());
            exit;
        }

        $result = $this->client->request(
            'getUpdates',
            [
                'limit'           => 25,
                'timeout'         => 0,
                'allowed_updates' => [
                    'message',
                    'edited_message',
                    'channel_post',
                    'edited_channel_post',
                    'callback_query',
                    'my_chat_member',
                    'chat_member',
                    'chat_join_request',
                    'message_reaction',
                    'message_reaction_count',
                ],
            ]
        );

        if (is_wp_error($result)) {
            $this->setNotice('error', $result->get_error_message());
            wp_safe_redirect($this->settingsUrl());
            exit;
        }

        $updates = $result['result'] ?? [];
        $updates = is_array($updates) ? $updates : [];
        $found   = $this->chats->recordUpdates($updates);

        $this->setNotice(
            $found > 0 ? 'success' : 'error',
            $found > 0
                ? sprintf(
                    /* translators: %d: Number of discovered Telegram chats. */
                    __('Telegram chats found: %d.', 'ks-telegram'),
                    $found
                )
                : __('No chats found. Send a message to the bot or add it to a group/channel, then try again.', 'ks-telegram')
        );

        wp_safe_redirect($this->settingsUrl());
        exit;
    }

    /**
     * Register or delete the Telegram webhook.
     *
     * @return never
     */
    public function handleWebhookAction(): never {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do this.', 'ks-telegram'));
        }

        check_admin_referer('ks_telegram_webhook_action');

        $command = isset($_POST['command'])
            ? sanitize_key(wp_unslash((string) $_POST['command']))
            : '';

        $result = match ($command) {
            'set' => $this->client->setWebhook($this->settings->webhookUrl()),
            'delete' => $this->client->deleteWebhook(),
            default => null,
        };

        $ok = is_array($result) && true === ($result['ok'] ?? false);
        $this->setNotice(
            $ok ? 'success' : 'error',
            $ok
                ? __('Webhook action completed.', 'ks-telegram')
                : __('Webhook action failed. Check the bot token and HTTPS URL.', 'ks-telegram')
        );

        wp_safe_redirect($this->settingsUrl());
        exit;
    }

    /**
     * Build the settings page URL.
     *
     * @return string
     */
    private function settingsUrl(): string {
        return admin_url('options-general.php?page=ks-telegram');
    }

    /**
     * Store a short admin notice.
     *
     * @param string $type    Notice type.
     * @param string $message Notice message.
     *
     * @return void
     */
    private function setNotice(string $type, string $message): void {
        set_transient(
            self::NOTICE_TRANSIENT . get_current_user_id(),
            [
                'type'    => $type,
                'message' => $message,
            ],
            60
        );
    }

    /**
     * Consume the current user's admin notice.
     *
     * @return array{type:string,message:string}|null
     */
    private function consumeNotice(): ?array {
        $key    = self::NOTICE_TRANSIENT . get_current_user_id();
        $notice = get_transient($key);

        if (! is_array($notice)) {
            return null;
        }

        delete_transient($key);

        return [
            'type'    => sanitize_key((string) ($notice['type'] ?? 'info')),
            'message' => sanitize_text_field((string) ($notice['message'] ?? '')),
        ];
    }
}
