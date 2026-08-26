<?php
/**
 * Bootable plugin integration contract.
 *
 * This file declares an optional integration contract for third-party plugins
 * that need custom bootstrap logic beyond hook attributes.
 *
 * @author  Konstantin Sorokin
 * @link    https://konstantinsorokin.com
 * @package KS_Telegram
 */

declare(strict_types=1);

namespace KonstantinSorokin\Telegram\Plugins;

defined('ABSPATH') || exit;

/**
 * Describes an integration with its own bootstrap routine.
 */
interface BootablePluginIntegration extends PluginIntegration {

    /**
     * Register custom integration bootstrap hooks.
     *
     * @return void
     */
    public function boot(): void;
}
