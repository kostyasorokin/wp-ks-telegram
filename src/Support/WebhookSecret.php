<?php
/**
 * Webhook secret helper.
 *
 * This file creates non-guessable secrets used by the REST webhook endpoint.
 *
 * @author  Konstantin Sorokin
 * @link    https://konstantinsorokin.com
 * @package KS_Telegram
 */

declare(strict_types=1);

namespace KonstantinSorokin\Telegram\Support;

defined('ABSPATH') || exit;

/**
 * Generates webhook secrets.
 */
final readonly class WebhookSecret {

    /**
     * Generate a secret suitable for URLs and Telegram secret tokens.
     *
     * @return string
     */
    public static function generate(): string {
        return wp_generate_password(32, false, false);
    }
}
