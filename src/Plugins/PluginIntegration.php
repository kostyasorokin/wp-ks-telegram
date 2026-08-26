<?php
/**
 * Plugin integration contract.
 *
 * This file declares the minimal contract shared by optional third-party
 * plugin integrations.
 *
 * @author  Konstantin Sorokin
 * @link    https://konstantinsorokin.com
 * @package KS_Telegram
 */

declare(strict_types=1);

namespace KonstantinSorokin\Telegram\Plugins;

defined('ABSPATH') || exit;

/**
 * Describes an optional third-party plugin integration.
 */
interface PluginIntegration {

    /**
     * Check whether the integration can register its hooks.
     *
     * @return bool
     */
    public function isAvailable(): bool;
}
