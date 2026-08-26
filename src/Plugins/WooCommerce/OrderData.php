<?php
/**
 * WooCommerce order data adapter.
 *
 * This file provides a small typed view over a WooCommerce order for Telegram
 * message builders without depending on another plugin.
 *
 * @author  Konstantin Sorokin
 * @link    https://konstantinsorokin.com
 * @package KS_Telegram
 */

declare(strict_types=1);

namespace KonstantinSorokin\Telegram\Plugins\WooCommerce;

defined('ABSPATH') || exit;

/**
 * Read-only order data adapter.
 */
final readonly class OrderData {

    public function __construct(public \WC_Order $order) {}

    /**
     * Get the order ID.
     *
     * @return int
     */
    public function id(): int {
        return $this->order->get_id();
    }

    /**
     * Get the saved billing Telegram handle.
     *
     * @return string
     */
    public function billingTelegram(): string {
        $telegram = (string) $this->order->get_meta('telegram', true);

        if ('' === $telegram && $this->order->get_user_id() > 0) {
            $telegram = (string) get_user_meta($this->order->get_user_id(), 'telegram', true);
        }

        $telegram = trim(sanitize_text_field($telegram));

        return '' !== $telegram && ! str_starts_with($telegram, '@') ? '@' . $telegram : $telegram;
    }

    /**
     * Get a compact formatted billing address.
     *
     * @return string
     */
    public function billingAddress(): string {
        return trim(
            implode(
                ', ',
                array_filter(
                    [
                        $this->order->get_billing_address_1(),
                        $this->order->get_billing_address_2(),
                        $this->order->get_billing_city(),
                        $this->order->get_billing_state(),
                        $this->order->get_billing_postcode(),
                        $this->order->get_billing_country(),
                    ]
                )
            )
        );
    }

    /**
     * Get a compact formatted shipping address.
     *
     * @return string
     */
    public function shippingAddress(): string {
        return trim(
            implode(
                ', ',
                array_filter(
                    [
                        $this->order->get_shipping_address_1(),
                        $this->order->get_shipping_address_2(),
                        $this->order->get_shipping_city(),
                        $this->order->get_shipping_state(),
                        $this->order->get_shipping_postcode(),
                        $this->order->get_shipping_country(),
                    ]
                )
            )
        );
    }

    /**
     * Get UTM fields stored on the order.
     *
     * @return array<string,string>
     */
    public function utm(): array {
        $fields = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
        $utm    = [];

        foreach ($fields as $field) {
            $value = (string) ($this->order->get_meta($field, true) ?: $this->order->get_meta('_' . $field, true));
            if ('' !== $value) {
                $utm[$field] = sanitize_text_field($value);
            }
        }

        return $utm;
    }
}
