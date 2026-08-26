<?php
/**
 * Plugin activation handler.
 *
 * This file contains activation-time setup for default options and rewrite
 * flushing without importing any settings from older plugins.
 *
 * @author  Konstantin Sorokin
 * @link    https://konstantinsorokin.com
 * @package KS_Telegram
 */

declare(strict_types=1);

namespace KonstantinSorokin\Telegram;

use KonstantinSorokin\Telegram\Settings\SettingsRepository;

defined('ABSPATH') || exit;

/**
 * Handles activation.
 */
final class Activator {

    /**
     * Create default settings and flush rewrite rules.
     *
     * @return void
     */
    public static function activate(): void {
        $settings = new SettingsRepository();

        if (false === get_option(SettingsRepository::OPTION_NAME, false)) {
            add_option(SettingsRepository::OPTION_NAME, $settings->defaults(), '', false);
        }

        flush_rewrite_rules(false);
    }
}
