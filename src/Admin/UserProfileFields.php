<?php
/**
 * Telegram user profile fields.
 *
 * This file adds a compact Telegram section to WordPress user profiles so
 * administrators can see and update contact data collected during login.
 *
 * @author  Konstantin Sorokin
 * @link    https://konstantinsorokin.com
 * @package KS_Telegram
 */

declare(strict_types=1);

namespace KonstantinSorokin\Telegram\Admin;

use KonstantinSorokin\Telegram\Plugins\WooCommerce\CustomerPhoneSync;
use WP_User;

defined('ABSPATH') || exit;

/**
 * Shows Telegram metadata on user profile screens.
 */
final class UserProfileFields {

    public function __construct(private readonly CustomerPhoneSync $phone_sync) {}

    /**
     * Register profile hooks.
     *
     * @return void
     */
    public function boot(): void {
        add_action('show_user_profile', [$this, 'render']);
        add_action('edit_user_profile', [$this, 'render']);
        add_action('personal_options_update', [$this, 'save']);
        add_action('edit_user_profile_update', [$this, 'save']);
    }

    /**
     * Render Telegram user meta fields.
     *
     * @param WP_User $user Profile user.
     *
     * @return void
     */
    public function render(WP_User $user): void {
        $telegram_id = sanitize_text_field((string) get_user_meta($user->ID, 'ks_telegram_id', true));
        $username    = sanitize_text_field((string) get_user_meta($user->ID, 'ks_telegram_username', true));
        $phone       = sanitize_text_field((string) get_user_meta($user->ID, 'ks_telegram_phone', true));

        if ('' === $telegram_id && '' === $username && '' === $phone) {
            return;
        }

        require KS_TELEGRAM_DIR . 'templates/admin/user-profile-fields.php';
    }

    /**
     * Save editable Telegram user meta fields.
     *
     * @param int $user_id Profile user ID.
     *
     * @return void
     */
    public function save(int $user_id): void {
        if (! current_user_can('edit_user', $user_id)) {
            return;
        }

        $nonce = filter_input(INPUT_POST, '_wpnonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        if (! is_string($nonce) || ! wp_verify_nonce($nonce, 'update-user_' . $user_id)) {
            return;
        }

        $phone_raw_input = filter_input(INPUT_POST, 'ks_telegram_phone', FILTER_UNSAFE_RAW);

        if (! is_string($phone_raw_input)) {
            return;
        }

        $phone_raw = wp_unslash($phone_raw_input);
        $phone     = $this->sanitizePhone($phone_raw);

        if ('' === trim($phone_raw)) {
            delete_user_meta($user_id, 'ks_telegram_phone');

            return;
        }

        if ('' !== $phone) {
            update_user_meta($user_id, 'ks_telegram_phone', $phone);
            $this->phone_sync->copyToBillingPhone($user_id, $phone);
        }
    }

    /**
     * Sanitize a phone number for user meta storage.
     *
     * @param string $value Raw phone number.
     *
     * @return string
     */
    private function sanitizePhone(string $value): string {
        $value = sanitize_text_field($value);
        $value = preg_replace('/[^\d+]/', '', $value) ?? '';

        return preg_match('/^\+?\d{7,20}$/', $value) ? $value : '';
    }
}
