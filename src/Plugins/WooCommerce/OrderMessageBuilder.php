<?php
/**
 * WooCommerce Telegram message builder.
 *
 * This file builds Telegram-ready messages for WooCommerce order and product
 * events using only WooCommerce public APIs.
 *
 * @author  Konstantin Sorokin
 * @link    https://konstantinsorokin.com
 * @package KS_Telegram
 */

declare(strict_types=1);

namespace KonstantinSorokin\Telegram\Plugins\WooCommerce;

use KonstantinSorokin\Telegram\Bot\Enum\ParseMode;
use KonstantinSorokin\Telegram\Bot\Formatter;
use KonstantinSorokin\Telegram\Settings\SettingsRepository;
use KonstantinSorokin\Telegram\Support\RequestContext;

defined('ABSPATH') || exit;

/**
 * Builds WooCommerce notification messages.
 */
final readonly class OrderMessageBuilder {

    public function __construct(private SettingsRepository $settings) {}

    /**
     * Build a new order message.
     *
     * @param \WC_Order $order WooCommerce order.
     *
     * @return string
     */
    public function order(\WC_Order $order): string {
        $data      = new OrderData($order);
        $formatter = $this->formatter();
        $message   = $formatter->header('wc_order');
        $message  .= $formatter->bold($formatter->esc(__('Order', 'ks-telegram') . ' #' . $data->id())) . "\n";
        $message  .= $formatter->line('Status', $this->statusName($order->get_status()));
        $message  .= $formatter->line('Total', wp_strip_all_tags($order->get_formatted_order_total()));
        $message  .= $formatter->line('Payment', $order->get_payment_method_title());

        if ($this->settings->bool('include_order_transaction_id')) {
            $message .= $formatter->line('Transaction ID', $order->get_transaction_id());
        }

        $message .= "\n" . $this->products($order, $formatter);
        $message .= $this->billing($data, $formatter);
        $message .= $this->shipping($data, $formatter);

        if ($this->settings->bool('include_order_customer_note')) {
            $message .= $formatter->line('Customer note', $order->get_customer_note());
        }

        if ($this->settings->bool('include_order_utm')) {
            foreach ($data->utm() as $key => $value) {
                $message .= $formatter->line($key, $value);
            }
        }

        return $message . $this->requestContext($formatter);
    }

    /**
     * Build an order status message.
     *
     * @param \WC_Order $order      WooCommerce order.
     * @param string    $old_status Old status slug.
     * @param string    $new_status New status slug.
     *
     * @return string
     */
    public function orderStatus(\WC_Order $order, string $old_status, string $new_status): string {
        $formatter = $this->formatter();
        $message   = $formatter->header('wc_order_status');
        $message  .= $formatter->bold($formatter->esc(__('Order status changed', 'ks-telegram'))) . "\n";
        $message  .= $formatter->line('Order', '#' . $order->get_id());
        $message  .= $formatter->line('Status', $this->statusName($old_status) . ' -> ' . $this->statusName($new_status));

        return $message . $this->requestContext($formatter);
    }

    /**
     * Build an add-to-cart message.
     *
     * @param \WC_Product         $product   Product object.
     * @param int                 $quantity  Quantity.
     * @param array<string,mixed> $variation Variation attributes.
     *
     * @return string
     */
    public function addToCart(\WC_Product $product, int $quantity, array $variation): string {
        $formatter = $this->formatter();
        $details   = [];

        foreach ($variation as $key => $value) {
            if (is_scalar($value) && '' !== (string) $value) {
                $details[] = wc_attribute_label(str_replace('attribute_', '', (string) $key)) . ': ' . sanitize_text_field((string) $value);
            }
        }

        $message  = $formatter->header('wc_add_to_cart');
        $message .= $formatter->bold($formatter->esc(__('Product added to cart', 'ks-telegram'))) . "\n";
        $message .= $formatter->line('Product', $product->get_name());
        $message .= $formatter->line('Quantity', $quantity);
        $message .= $formatter->line('Variation', implode(', ', $details));

        return $message . $this->requestContext($formatter);
    }

    /**
     * Build a low stock message.
     *
     * @param \WC_Product $product Product object.
     *
     * @return string
     */
    public function lowStock(\WC_Product $product): string {
        $formatter = $this->formatter();
        $message   = $formatter->header('wc_low_stock');
        $message  .= $formatter->bold($formatter->esc(__('Low stock', 'ks-telegram'))) . "\n";
        $message  .= $formatter->line('Product', $product->get_name());
        $message  .= $formatter->line('Stock quantity', $product->get_stock_quantity());

        return $message;
    }

    /**
     * Create the configured formatter.
     *
     * @return Formatter
     */
    private function formatter(): Formatter {
        return new Formatter(
            ParseMode::tryFrom($this->settings->string('parse_mode', 'MarkdownV2')) ?? ParseMode::MarkdownV2,
            $this->settings->messageHeaderOptions()
        );
    }

    /**
     * Format order product lines.
     *
     * @param \WC_Order $order     WooCommerce order.
     * @param Formatter $formatter Message formatter.
     *
     * @return string
     */
    private function products(\WC_Order $order, Formatter $formatter): string {
        $lines = [$formatter->bold($formatter->esc(__('Products', 'ks-telegram')))];

        foreach ($order->get_items('line_item') as $item) {
            if (! $item instanceof \WC_Order_Item_Product) {
                continue;
            }

            $lines[] = $formatter->esc(
                sprintf(
                    '%s x %s - %s %s',
                    (string) $item->get_quantity(),
                    $item->get_name(),
                    (string) $item->get_total(),
                    $order->get_currency()
                )
            );

            foreach ($item->get_meta_data() as $meta) {
                $key = (string) $meta->key;
                if (str_starts_with($key, '_')) {
                    continue;
                }

                $lines[] = $formatter->esc('  ' . $key . ': ' . (string) $meta->value);
            }
        }

        return implode("\n", $lines) . "\n\n";
    }

    /**
     * Format the billing section.
     *
     * @param OrderData $data      Order data.
     * @param Formatter $formatter Message formatter.
     *
     * @return string
     */
    private function billing(OrderData $data, Formatter $formatter): string {
        if (! $this->settings->bool('include_billing')) {
            return '';
        }

        $order  = $data->order;
        $lines  = [];
        $lines[] = $formatter->bold($formatter->esc(__('Billing', 'ks-telegram')));

        if ($this->settings->bool('include_billing_name')) {
            $lines[] = $formatter->line('Name', trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()));
        }

        if ($this->settings->bool('include_billing_email')) {
            $lines[] = $formatter->line('Email', $order->get_billing_email());
        }

        if ($this->settings->bool('include_billing_phone')) {
            $lines[] = $formatter->line('Phone', $order->get_billing_phone());
        }

        if ($this->settings->bool('include_billing_telegram')) {
            $lines[] = $formatter->line('Telegram', $data->billingTelegram());
        }

        if ($this->settings->bool('include_billing_company')) {
            $lines[] = $formatter->line('Company', $order->get_billing_company());
        }

        if ($this->settings->bool('include_billing_address')) {
            $lines[] = $formatter->line('Address', $data->billingAddress());
        }

        $body = implode('', array_slice($lines, 1));

        return '' !== $body ? $lines[0] . "\n" . $body . "\n" : '';
    }

    /**
     * Format the shipping section.
     *
     * @param OrderData $data      Order data.
     * @param Formatter $formatter Message formatter.
     *
     * @return string
     */
    private function shipping(OrderData $data, Formatter $formatter): string {
        if (! $this->settings->bool('include_shipping')) {
            return '';
        }

        $order  = $data->order;
        $lines  = [];
        $lines[] = $formatter->bold($formatter->esc(__('Shipping', 'ks-telegram')));

        if ($this->settings->bool('include_shipping_name')) {
            $lines[] = $formatter->line('Name', trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name()));
        }

        if ($this->settings->bool('include_shipping_phone')) {
            $lines[] = $formatter->line('Phone', $order->get_shipping_phone());
        }

        if ($this->settings->bool('include_shipping_company')) {
            $lines[] = $formatter->line('Company', $order->get_shipping_company());
        }

        if ($this->settings->bool('include_shipping_address')) {
            $lines[] = $formatter->line('Address', $data->shippingAddress());
        }

        $body = implode('', array_slice($lines, 1));

        return '' !== $body ? $lines[0] . "\n" . $body . "\n" : '';
    }

    /**
     * Add request context when enabled.
     *
     * @param Formatter $formatter Message formatter.
     *
     * @return string
     */
    private function requestContext(Formatter $formatter): string {
        return $this->settings->bool('include_request_context')
            ? "\n" . (new RequestContext())->toMessage($formatter)
            : '';
    }

    /**
     * Get a readable WooCommerce status name.
     *
     * @param string $status Status slug.
     *
     * @return string
     */
    private function statusName(string $status): string {
        return function_exists('wc_get_order_status_name') ? wc_get_order_status_name($status) : $status;
    }
}
