<?php
/**
 * Settings repository.
 *
 * This file owns the plugin option schema, default values, sanitization, and
 * typed getters used by the rest of the plugin.
 *
 * @author  Konstantin Sorokin
 * @link    https://konstantinsorokin.com
 * @package KS_Telegram
 */

declare(strict_types=1);

namespace KonstantinSorokin\Telegram\Settings;

use KonstantinSorokin\Telegram\Publishing\ChannelPublisher;
use KonstantinSorokin\Telegram\Support\WebhookSecret;

defined('ABSPATH') || exit;

/**
 * Provides typed access to plugin settings.
 */
final class SettingsRepository {

    public const string OPTION_NAME = 'ks_telegram';

    /**
     * Get default settings.
     *
     * @return array<string,mixed>
     */
    public function defaults(): array {
        return [
            'bot_token'                         => '',
            'bot_username'                      => '',
            'default_chat_ids'                  => '',
            'channel_chat_id'                   => '',
            'channel_publish_posts'             => false,
            'channel_publish_pages'             => false,
            'channel_publish_news'              => false,
            'channel_publish_custom_types'      => [],
            'large_upload_threshold_mb'         => 10,
            'message_header_date'               => true,
            'message_header_site_hashtag'       => true,
            'message_header_time'               => true,
            'message_header_type_hashtag'       => true,
            'parse_mode'                        => 'MarkdownV2',
            'disable_web_page_preview'          => true,
            'silent_notifications'              => false,
            'notifications_enabled'             => true,
            'notify_admin_login'                => false,
            'notify_application_passwords'      => false,
            'notify_comment_approved'           => false,
            'notify_comment_deleted'            => false,
            'notify_comment_new'                => false,
            'notify_comment_pending'            => false,
            'notify_comment_spam'               => false,
            'notify_failed_login'               => false,
            'notify_media_deleted'              => false,
            'notify_media_large_upload'         => false,
            'notify_media_uploaded'             => false,
            'notify_missed_scheduled_posts'     => false,
            'notify_new_posts'                  => false,
            'notify_new_admin'                  => false,
            'notify_option_changes'             => false,
            'notify_password_reset'             => false,
            'notify_password_reset_request'     => false,
            'notify_plugin_activation'          => false,
            'notify_plugin_deactivation'        => false,
            'notify_post_deleted'               => false,
            'notify_post_pending'               => false,
            'notify_post_published'             => false,
            'notify_post_scheduled'             => false,
            'notify_post_updated'               => false,
            'notify_profile_update'             => false,
            'notify_recovery_mode'              => false,
            'notify_site_health'                => false,
            'notify_theme_switch'               => false,
            'notify_updates'                    => false,
            'notify_user_email_change'          => false,
            'notify_user_registration'          => false,
            'notify_user_role_change'           => false,
            'notify_wp_mail_failed'             => false,
            'notify_authorization'              => false,
            'include_plain_passwords'           => false,
            'notify_contact_form_7'             => false,
            'notify_contact_form_7_rejected'    => false,
            'cf7_skip_mail'                     => false,
            'cf7_spam_streak_threshold'         => 5,
            'delivery_log_enabled'              => true,
            /*
             * Off by default: it sends CF7's reCAPTCHA secret — another plugin's
             * credential — to Google, which nothing else here contacts.
             */
            'health_recaptcha_probe'            => false,
            'notify_mailchimp_subscribe'        => false,
            'notify_mailchimp_unsubscribe'      => false,
            'notify_wc_order'                   => false,
            'notify_wc_order_status'            => false,
            'notify_wc_add_to_cart'             => false,
            'notify_wc_low_stock'               => false,
            'wc_checkout_telegram_field'        => false,
            'include_request_context'           => true,
            'include_billing'                   => true,
            'include_billing_name'              => true,
            'include_billing_email'             => true,
            'include_billing_phone'             => true,
            'include_billing_telegram'          => true,
            'include_billing_company'           => true,
            'include_billing_address'           => true,
            'include_shipping'                  => true,
            'include_shipping_name'             => true,
            'include_shipping_phone'            => true,
            'include_shipping_company'          => true,
            'include_shipping_address'          => true,
            'include_order_transaction_id'      => false,
            'include_order_customer_note'       => true,
            'include_order_utm'                 => false,
            'telegram_login_enabled'            => false,
            'telegram_login_create_users'       => false,
            'telegram_login_require_email'      => true,
            'telegram_login_request_write'      => false,
            'telegram_login_request_phone'      => false,
            'telegram_login_upload_avatar'       => false,
            'telegram_login_client_id'          => '',
            'telegram_login_client_secret'      => '',
            'webhook_enabled'                   => false,
            'webhook_secret'                    => WebhookSecret::generate(),
        ];
    }

    /**
     * Get all settings merged with defaults.
     *
     * @return array<string,mixed>
     */
    public function all(): array {
        $stored = get_option(self::OPTION_NAME, []);
        $stored = is_array($stored) ? $stored : [];

        return array_merge($this->defaults(), $stored);
    }

    /**
     * Sanitize an incoming settings payload.
     *
     * @param array<string,mixed> $input Raw settings.
     *
     * @return array<string,mixed>
     */
    public function sanitize(array $input): array {
        $old      = $this->all();
        $settings = $this->defaults();

        $token = isset($input['bot_token']) ? trim(sanitize_text_field(wp_unslash((string) $input['bot_token']))) : '';
        $settings['bot_token'] = '' !== $token ? $token : (string) $old['bot_token'];

        $settings['bot_username']              = $this->sanitizeBotUsername($input['bot_username'] ?? '');
        $settings['default_chat_ids']          = $this->sanitizeChatIdsText($input['default_chat_ids'] ?? '');
        $settings['channel_chat_id']           = $this->sanitizeChannelId($input['channel_chat_id'] ?? '');
        $settings['large_upload_threshold_mb'] = $this->sanitizePositiveInt($input['large_upload_threshold_mb'] ?? $old['large_upload_threshold_mb'], (int) $old['large_upload_threshold_mb'], 1, 1024);

        // Floor 0, not 1: zero means "never warn".
        $settings['cf7_spam_streak_threshold'] = $this->sanitizePositiveInt($input['cf7_spam_streak_threshold'] ?? $old['cf7_spam_streak_threshold'], (int) $old['cf7_spam_streak_threshold'], 0, 100);

        $settings['parse_mode']                = $this->sanitizeParseMode($input['parse_mode'] ?? 'MarkdownV2');
        $settings['telegram_login_client_id'] = $this->sanitizeClientId($input['telegram_login_client_id'] ?? '');

        $client_secret = isset($input['telegram_login_client_secret'])
            ? trim(sanitize_text_field(wp_unslash((string) $input['telegram_login_client_secret'])))
            : '';
        $settings['telegram_login_client_secret'] = '' !== $client_secret
            ? $client_secret
            : (string) $old['telegram_login_client_secret'];

        foreach ($settings as $key => $default) {
            if (is_bool($default)) {
                $settings[$key] = $this->sanitizeBool($input[$key] ?? false);
            }
        }

        $available_custom_types = ChannelPublisher::availableCustomPostTypes();
        $submitted_custom_types = $input['channel_publish_custom_types'] ?? [];
        $submitted_custom_types = is_array($submitted_custom_types) ? $submitted_custom_types : [];
        $selected_custom_types  = [];

        foreach ($submitted_custom_types as $slug) {
            if (! is_string($slug)) {
                continue;
            }

            $slug = sanitize_key(wp_unslash($slug));
            if (isset($available_custom_types[$slug])) {
                $selected_custom_types[] = $slug;
            }
        }

        // Keep choices from temporarily disabled plugins, but never retain a
        // registered type that is no longer public or viewable.
        $previous_custom_types = is_array($old['channel_publish_custom_types']) ? $old['channel_publish_custom_types'] : [];
        foreach ($previous_custom_types as $slug) {
            if (is_string($slug) && $slug === sanitize_key($slug) && ! post_type_exists($slug)) {
                $selected_custom_types[] = $slug;
            }
        }

        $settings['channel_publish_custom_types'] = array_values(array_unique($selected_custom_types));

        // The KS News field is hidden when that plugin is inactive. Keep the
        // choice for a later reactivation, while the publisher itself ignores it.
        if (! ChannelPublisher::newsAvailable()) {
            $settings['channel_publish_news'] = (bool) $old['channel_publish_news'];
        }

        $secret = isset($input['webhook_secret']) ? sanitize_key(wp_unslash((string) $input['webhook_secret'])) : '';
        $settings['webhook_secret'] = '' !== $secret ? $secret : (string) $old['webhook_secret'];

        if ('' === $settings['webhook_secret']) {
            $settings['webhook_secret'] = WebhookSecret::generate();
        }

        return $settings;
    }

    /**
     * Get a string setting.
     *
     * @param string $key     Setting key.
     * @param string $default Default value.
     *
     * @return string
     */
    public function string(string $key, string $default = ''): string {
        $value = $this->all()[$key] ?? $default;

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * Get a boolean setting.
     *
     * @param string $key     Setting key.
     * @param bool   $default Default value.
     *
     * @return bool
     */
    public function bool(string $key, bool $default = false): bool {
        $value = $this->all()[$key] ?? $default;

        return is_bool($value) ? $value : (bool) $value;
    }

    /**
     * Get an integer setting.
     *
     * @param string $key     Setting key.
     * @param int    $default Default value.
     *
     * @return int
     */
    public function int(string $key, int $default = 0): int {
        $value = $this->all()[$key] ?? $default;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Get message header component settings.
     *
     * @return array{date:bool,time:bool,type_hashtag:bool,site_hashtag:bool}
     */
    public function messageHeaderOptions(): array {
        return [
            'date'         => $this->bool('message_header_date', true),
            'time'         => $this->bool('message_header_time', true),
            'type_hashtag' => $this->bool('message_header_type_hashtag', true),
            'site_hashtag' => $this->bool('message_header_site_hashtag', true),
        ];
    }

    /**
     * Get the Bot API token.
     *
     * @return string
     */
    public function botToken(): string {
        return $this->string('bot_token');
    }

    /**
     * Get Telegram Login client ID.
     *
     * @return string
     */
    public function loginClientId(): string {
        return $this->string('telegram_login_client_id');
    }

    /**
     * Get Telegram Login client secret.
     *
     * @return string
     */
    public function loginClientSecret(): string {
        return $this->string('telegram_login_client_secret');
    }

    /**
     * Get default target chat IDs.
     *
     * @return array<int,string>
     */
    public function chatIds(): array {
        return $this->parseChatIds($this->string('default_chat_ids'));
    }

    /**
     * Return the single public publishing destination, never default alert chats.
     */
    public function channelId(): string {
        return $this->sanitizeChannelId($this->string('channel_chat_id'));
    }

    /**
     * Selected and currently available custom post types, including legacy News.
     *
     * @return array<int,string>
     */
    public function selectedCustomPostTypes(): array {
        $stored = $this->all()['channel_publish_custom_types'] ?? [];
        $stored = is_array($stored) ? $stored : [];

        if ($this->bool('channel_publish_news') && ChannelPublisher::newsAvailable()) {
            $stored[] = (string) KS_NEWS_POST_TYPE;
        }

        $available = ChannelPublisher::availableCustomPostTypes();
        return array_values(array_unique(array_filter(
            $stored,
            static fn($slug): bool => is_string($slug) && isset($available[$slug])
        )));
    }

    /**
     * Parse chat IDs from a string, scalar, or array.
     *
     * @param string|int|array<int,string|int>|null $chat_ids Raw chat IDs.
     *
     * @return array<int,string>
     */
    public function parseChatIds(string|int|array|null $chat_ids): array {
        if (null === $chat_ids) {
            return $this->chatIds();
        }

        $items = is_array($chat_ids) ? $chat_ids : preg_split('/[\s,]+/', (string) $chat_ids);
        $items = is_array($items) ? $items : [];

        $chat_ids = [];
        foreach ($items as $item) {
            $item = trim((string) $item);
            if ('' !== $item && preg_match('/^@?[A-Za-z0-9_-]+$|^-?\d+$/', $item)) {
                $chat_ids[] = $item;
            }
        }

        return array_values(array_unique($chat_ids));
    }

    /**
     * Get the REST webhook URL.
     *
     * @return string
     */
    public function webhookUrl(): string {
        return rest_url('ks-telegram/v1/webhook/' . rawurlencode($this->string('webhook_secret')));
    }

    /**
     * Sanitize checkbox-like values.
     *
     * @param mixed $value Raw value.
     *
     * @return bool
     */
    private function sanitizeBool(mixed $value): bool {
        return in_array($value, [true, 1, '1', 'on', 'yes'], true);
    }

    /**
     * Sanitize Bot API parse mode.
     *
     * @param mixed $value Raw value.
     *
     * @return string
     */
    private function sanitizeParseMode(mixed $value): string {
        $value = (string) $value;

        return match ($value) {
            'HTML', 'MarkdownV2', 'Markdown', 'None' => $value,
            default => 'MarkdownV2',
        };
    }

    /**
     * Sanitize bot username without the leading at sign.
     *
     * @param mixed $value Raw value.
     *
     * @return string
     */
    private function sanitizeBotUsername(mixed $value): string {
        $value = ltrim(trim(sanitize_text_field(wp_unslash((string) $value))), '@');

        return preg_match('/^[A-Za-z0-9_]{5,32}$/', $value) ? $value : '';
    }

    /**
     * Sanitize Telegram Login client ID.
     *
     * @param mixed $value Raw value.
     *
     * @return string
     */
    private function sanitizeClientId(mixed $value): string {
        $value = trim(sanitize_text_field(wp_unslash((string) $value)));

        return preg_match('/^\d+$/', $value) ? $value : '';
    }

    /**
     * Sanitize multiline chat ID text.
     *
     * @param mixed $value Raw value.
     *
     * @return string
     */
    private function sanitizeChatIdsText(mixed $value): string {
        return implode("\n", $this->parseChatIds((string) wp_unslash($value)));
    }

    /**
     * Accept one Telegram numeric ID or public channel username only.
     */
    private function sanitizeChannelId(mixed $value): string {
        if (! is_string($value) && ! is_int($value)) {
            return '';
        }

        $value = trim((string) wp_unslash($value));
        return preg_match('/^(?:-?\d+|@[A-Za-z0-9_]{5,32})$/', $value) ? $value : '';
    }

    /**
     * Sanitize a bounded positive integer setting.
     *
     * @param mixed $value   Raw value.
     * @param int   $default Default value.
     * @param int   $min     Minimum accepted value.
     * @param int   $max     Maximum accepted value.
     *
     * @return int
     */
    private function sanitizePositiveInt(mixed $value, int $default, int $min, int $max): int {
        // An emptied field means "unchanged": callers pass the stored value as
        // $default, so clearing the box keeps what was saved rather than
        // silently snapping back to the built-in number.
        if (is_string($value) && '' === trim($value)) {
            return $default;
        }

        $value = absint($value);

        return $value >= $min && $value <= $max ? $value : $default;
    }
}
