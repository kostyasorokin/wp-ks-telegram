<?php
/**
 * WooCommerce customer phone sync.
 *
 * This file copies Telegram phone numbers into WooCommerce customer metadata
 * without overwriting an existing billing phone value.
 *
 * @author  Konstantin Sorokin
 * @link    https://konstantinsorokin.com
 * @package KS_Telegram
 */

declare(strict_types=1);

namespace KonstantinSorokin\Telegram\Plugins\WooCommerce;

defined('ABSPATH') || exit;

/**
 * Syncs Telegram phone data to WooCommerce customer metadata.
 */
final readonly class CustomerPhoneSync {

    /**
     * Copy a Telegram phone number to WooCommerce billing phone when empty.
     *
     * @param int    $user_id WordPress user ID.
     * @param string $phone   Phone number.
     *
     * @return void
     */
    public function copyToBillingPhone(int $user_id, string $phone): void {
        $phone = $this->sanitizePhone($phone);

        if ($user_id <= 0 || '' === $phone) {
            return;
        }

        $billing_phone = sanitize_text_field((string) get_user_meta($user_id, 'billing_phone', true));
        if ('' !== $billing_phone) {
            return;
        }

        update_user_meta($user_id, 'billing_phone', $phone);
    }

    /**
     * Sanitize a phone number for WooCommerce user meta.
     *
     * @param string $value Raw phone number.
     *
     * @return string
     */
    private function sanitizePhone(string $value): string {
        $value = sanitize_text_field(wp_unslash($value));
        $value = preg_replace('/[^\d+]/', '', $value) ?? '';

        return preg_match('/^\+?\d{7,20}$/', $value) ? $value : '';
    }
}
