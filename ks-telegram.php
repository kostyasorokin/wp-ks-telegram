<?php
/**
 * Plugin Name: KS Telegram
 * Plugin URI: https://github.com/kostyasorokin/wp-ks-telegram
 * Description: Modern Telegram notifications, authorization, and bot integration toolkit for WordPress and WooCommerce.
 * Version: 1.0.0
 * Requires at least: 6.7
 * Requires PHP: 8.4
 * Author: Konstantin Sorokin
 * Author URI: https://konstantinsorokin.com
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: ks-telegram
 * Domain Path: /languages
 *
 * @package KS_Telegram
 */

/**
 * KS Telegram plugin bootstrap.
 *
 * This file defines plugin constants, loads the Composer autoloader, registers
 * activation hooks, and exposes a small public API for other plugins.
 *
 * @author  Konstantin Sorokin
 * @link    https://konstantinsorokin.com
 * @package KS_Telegram
 */

declare(strict_types=1);

use KonstantinSorokin\Telegram\Activator;
use KonstantinSorokin\Telegram\Deactivator;
use KonstantinSorokin\Telegram\Plugin;

defined('ABSPATH') || exit;

define('KS_TELEGRAM_VERSION', '1.0.0');
define('KS_TELEGRAM_FILE', __FILE__);
define('KS_TELEGRAM_DIR', plugin_dir_path(__FILE__));
define('KS_TELEGRAM_URL', plugin_dir_url(__FILE__));
define('KS_TELEGRAM_BASENAME', plugin_basename(__FILE__));

if (version_compare(PHP_VERSION, '8.4', '<')) {
    wp_die(
        esc_html__('KS Telegram requires PHP 8.4 or higher.', 'ks-telegram')
    );
}

$ks_telegram_autoload = KS_TELEGRAM_DIR . 'vendor/autoload.php';

if (file_exists($ks_telegram_autoload)) {
    require_once $ks_telegram_autoload;
} else {
    add_action(
        'admin_notices',
        static function (): void {
            if (!current_user_can('activate_plugins')) {
                return;
            }

            echo '<div class="notice notice-error"><p>';
            echo esc_html__('KS Telegram is missing Composer autoload files. Run composer install in the plugin directory.', 'ks-telegram');
            echo '</p></div>';
        }
    );

    return;
}

register_activation_hook(__FILE__, [Activator::class, 'activate']);
register_deactivation_hook(__FILE__, [Deactivator::class, 'deactivate']);

add_action(
    'plugins_loaded',
    static function (): void {
        ks_telegram()->boot();
    },
    5
);

if (!function_exists('ks_telegram')) {
    /**
     * Get the main KS Telegram plugin instance.
     *
     * @return Plugin
     */
    function ks_telegram(): Plugin
    {
        return Plugin::instance();
    }
}

if (!function_exists('ks_telegram_send_message')) {
    /**
     * Send a Telegram message through the plugin public API.
     *
     * @param string|int|array<int,string|int>|null $chat_ids Optional target chat IDs. Null uses default chats.
     * @param string $message Message text.
     * @param array<string,mixed> $args Additional Telegram sendMessage arguments.
     *
     * @return bool True when all target chats accepted the message.
     */
    function ks_telegram_send_message(string|int|array|null $chat_ids, string $message, array $args = []): bool
    {
        return ks_telegram()->sender()->send($chat_ids, $message, $args);
    }
}

if (!function_exists('ks_telegram_login_button')) {
    /**
     * Get the Telegram Login button markup for a theme template.
     *
     * The same markup [ks_telegram_login] produces, including the stylesheet
     * enqueue. Returns '' when login is off, unconfigured, the visitor is signed
     * in, or the plugin has not booted — safe to echo. Already escaped.
     *
     * @param string $class Extra CSS classes.
     * @param string $redirect_to Where to land after signing in.
     *
     * @return string
     */
    function ks_telegram_login_button(string $class = '', string $redirect_to = ''): string
    {
        $login = ks_telegram()->login();

        return $login instanceof \KonstantinSorokin\Telegram\Auth\LoginController
            ? $login->button($class, $redirect_to)
            : '';
    }
}

if (!function_exists('ks_telegram_bot_api')) {
    /**
     * Get the configured raw TelegramBot/Api BotApi client.
     *
     * @return \TelegramBot\Api\BotApi|\WP_Error
     */
    function ks_telegram_bot_api(): \TelegramBot\Api\BotApi|\WP_Error
    {
        return ks_telegram()->botApi()->api();
    }
}

if (!function_exists('ks_telegram_bot_client')) {
    /**
     * Get the configured TelegramBot/Api Client for command handlers.
     *
     * @return \TelegramBot\Api\Client|\WP_Error
     */
    function ks_telegram_bot_client(): \TelegramBot\Api\Client|\WP_Error
    {
        return ks_telegram()->botApi()->client();
    }
}

if (!function_exists('ks_telegram_bot_request')) {
    /**
     * Call any Telegram Bot API method through the WordPress-friendly adapter.
     *
     * @param string $method Telegram Bot API method.
     * @param array<string,mixed> $args Method arguments.
     *
     * @return array<string,mixed>|\WP_Error
     */
    function ks_telegram_bot_request(string $method, array $args = []): array|\WP_Error
    {
        return ks_telegram()->apiClient()->request($method, $args);
    }
}
