<?php
/**
 * Plugin deactivation handler.
 *
 * This file contains small deactivation cleanup tasks. User settings are kept
 * so a later reactivation does not destroy configuration.
 *
 * @author  Konstantin Sorokin
 * @link    https://konstantinsorokin.com
 * @package KS_Telegram
 */

declare(strict_types=1);

namespace KonstantinSorokin\Telegram;

defined('ABSPATH') || exit;

/**
 * Handles deactivation.
 */
final class Deactivator {

    /**
     * Flush rewrite rules on deactivation.
     *
     * @return void
     */
    public static function deactivate(): void {
        wp_clear_scheduled_hook('ks_telegram_check_missed_scheduled_posts');
        flush_rewrite_rules(false);
    }
}
