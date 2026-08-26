<?php
/**
 * WooCommerce integration manager.
 *
 * This file registers WooCommerce hooks for Telegram notifications and the
 * optional checkout Telegram handle field.
 *
 * @author  Konstantin Sorokin
 * @link    https://konstantinsorokin.com
 * @package KS_Telegram
 */

declare(strict_types=1);

namespace KonstantinSorokin\Telegram\Plugins\WooCommerce;

use KonstantinSorokin\Telegram\Bot\MessageSender;
use KonstantinSorokin\Telegram\Plugins\BootablePluginIntegration;
use KonstantinSorokin\Telegram\Settings\SettingsRepository;

defined('ABSPATH') || exit;

/**
 * Registers WooCommerce integrations.
 */
final readonly class WooCommerceManager implements BootablePluginIntegration {

    private OrderMessageBuilder $messages;

    public function __construct(
        private SettingsRepository $settings,
        private MessageSender $sender
    ) {
        $this->messages = new OrderMessageBuilder($settings);
    }

    /**
     * Check whether the integration can register its bootstrap hook.
     *
     * @return bool
     */
    public function isAvailable(): bool {
        return true;
    }

    /**
     * Register WooCommerce hooks after WooCommerce has loaded.
     *
     * @return void
     */
    public function boot(): void {
        add_action('plugins_loaded', [$this, 'registerHooks'], 20);
    }

    /**
     * Register enabled WooCommerce hooks.
     *
     * @return void
     */
    public function registerHooks(): void {
        if (! class_exists('WooCommerce') || ! function_exists('wc_get_order')) {
            return;
        }

        add_action('woocommerce_admin_order_data_after_billing_address', [$this, 'displayOrderTelegramHandle']);

        if ($this->settings->bool('wc_checkout_telegram_field')) {
            add_action('woocommerce_after_order_notes', [$this, 'renderCheckoutTelegramField']);
            add_action('woocommerce_checkout_update_order_meta', [$this, 'saveCheckoutTelegramField']);
        }

        if (! $this->settings->bool('notifications_enabled')) {
            return;
        }

        if ($this->settings->bool('notify_wc_order')) {
            add_action('woocommerce_checkout_order_processed', [$this, 'orderCreated'], 10, 3);
        }

        if ($this->settings->bool('notify_wc_order_status')) {
            add_action('woocommerce_order_status_changed', [$this, 'orderStatusChanged'], 10, 4);
        }

        if ($this->settings->bool('notify_wc_add_to_cart')) {
            add_action('woocommerce_add_to_cart', [$this, 'addedToCart'], 10, 6);
        }

        if ($this->settings->bool('notify_wc_low_stock')) {
            add_action('woocommerce_low_stock', [$this, 'lowStock']);
        }
    }

    /**
     * Render the checkout Telegram handle field.
     *
     * @param \WC_Checkout $checkout WooCommerce checkout object.
     *
     * @return void
     */
    public function renderCheckoutTelegramField(\WC_Checkout $checkout): void {
        echo '<div id="ks-telegram-checkout-field">';
        woocommerce_form_field(
            'telegram',
            [
                'type'        => 'text',
                'class'       => ['form-row-wide', 'telegram-nickname'],
                'label'       => esc_html__('Telegram handle', 'ks-telegram'),
                'placeholder' => esc_attr__('@username', 'ks-telegram'),
            ],
            $checkout->get_value('telegram')
        );
        echo '</div>';
    }

    /**
     * Save the checkout Telegram handle field.
     *
     * @param int $order_id Order ID.
     *
     * @return void
     */
    public function saveCheckoutTelegramField(int $order_id): void {
        $nonce = isset($_POST['woocommerce-process-checkout-nonce'])
            ? sanitize_text_field(wp_unslash((string) $_POST['woocommerce-process-checkout-nonce']))
            : '';

        if (! wp_verify_nonce($nonce, 'woocommerce-process_checkout')) {
            return;
        }

        if (empty($_POST['telegram'])) {
            return;
        }

        $telegram = sanitize_text_field(wp_unslash((string) $_POST['telegram']));
        $telegram = ltrim($telegram, '@');

        if ('' === $telegram || ! preg_match('/^[A-Za-z0-9_]{3,32}$/', $telegram)) {
            return;
        }

        update_post_meta($order_id, 'telegram', $telegram);

        if (is_user_logged_in()) {
            update_user_meta(get_current_user_id(), 'telegram', $telegram);
        }
    }

    /**
     * Display a saved Telegram handle on the admin order screen.
     *
     * @param \WC_Order $order Order object.
     *
     * @return void
     */
    public function displayOrderTelegramHandle(\WC_Order $order): void {
        $telegram = sanitize_text_field((string) $order->get_meta('telegram', true));
        $telegram = ltrim($telegram, '@');

        if ('' === $telegram || ! preg_match('/^[A-Za-z0-9_]{3,32}$/', $telegram)) {
            return;
        }

        printf(
            '<p><strong>%s:</strong> <a target="_blank" rel="noopener noreferrer" href="%s">%s</a></p>',
            esc_html__('Telegram', 'ks-telegram'),
            esc_url('https://t.me/' . $telegram),
            esc_html('@' . $telegram)
        );
    }

    /**
     * Send a new order notification.
     *
     * @param int          $order_id    Order ID.
     * @param array<mixed> $posted_data Posted data.
     * @param \WC_Order    $order       Order object.
     *
     * @return void
     */
    public function orderCreated(int $order_id, array $posted_data = [], ?\WC_Order $order = null): void {
        unset($posted_data);

        $order = $order instanceof \WC_Order ? $order : wc_get_order($order_id);
        if (! $order instanceof \WC_Order) {
            return;
        }

        $args = [
            'reply_markup' => [
                'inline_keyboard' => [
                    [
                        [
                            'text' => __('Edit order', 'ks-telegram') . ' #' . $order_id,
                            'url'  => admin_url('post.php?post=' . $order_id . '&action=edit'),
                        ],
                    ],
                ],
            ],
        ];

        /*
         * The marker records that the notification *arrived*, so it is written
         * only when the send succeeded. It used to be written unconditionally
         * on the line after the send, which meant a refused message left an
         * order permanently flagged as notified — the order carried a claim
         * that was never true and nothing could tell it apart from one that
         * had genuinely been delivered.
         */
        if ($this->sender->send(null, $this->messages->order($order), $args)) {
            update_post_meta($order_id, '_ks_telegram_order_notified', '1');
        }
    }

    /**
     * Send an order status notification.
     *
     * @param int       $order_id   Order ID.
     * @param string    $old_status Old status.
     * @param string    $new_status New status.
     * @param \WC_Order $order      Order object.
     *
     * @return void
     */
    public function orderStatusChanged(int $order_id, string $old_status, string $new_status, \WC_Order $order): void {
        if ($old_status === $new_status) {
            return;
        }

        $this->sender->send(null, $this->messages->orderStatus($order, $old_status, $new_status));
    }

    /**
     * Send an add-to-cart notification.
     *
     * @param string              $cart_item_key  Cart item key.
     * @param int                 $product_id     Product ID.
     * @param int                 $quantity       Quantity.
     * @param int                 $variation_id   Variation ID.
     * @param array<string,mixed> $variation      Variation attributes.
     * @param array<string,mixed> $cart_item_data Cart item data.
     *
     * @return void
     */
    public function addedToCart(string $cart_item_key, int $product_id, int $quantity, int $variation_id, array $variation, array $cart_item_data): void {
        unset($cart_item_key, $cart_item_data);

        $product = wc_get_product($variation_id ?: $product_id);
        if (! $product instanceof \WC_Product) {
            return;
        }

        $this->sender->send(null, $this->messages->addToCart($product, $quantity, $variation));
    }

    /**
     * Send a low stock notification.
     *
     * @param \WC_Product $product Product object.
     *
     * @return void
     */
    public function lowStock(\WC_Product $product): void {
        $args = [
            'reply_markup' => [
                'inline_keyboard' => [
                    [
                        [
                            'text' => __('Edit product', 'ks-telegram'),
                            'url'  => admin_url('post.php?post=' . $product->get_id() . '&action=edit'),
                        ],
                    ],
                ],
            ],
        ];

        $this->sender->send(null, $this->messages->lowStock($product), $args);
    }
}
