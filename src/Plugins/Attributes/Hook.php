<?php
/**
 * Plugin integration hook attribute.
 *
 * This file defines a small attribute used by plugin integrations to register
 * WordPress hooks only when their related setting is enabled.
 *
 * @author  Konstantin Sorokin
 * @link    https://konstantinsorokin.com
 * @package KS_Telegram
 */

declare(strict_types=1);

namespace KonstantinSorokin\Telegram\Plugins\Attributes;

use Attribute;

defined('ABSPATH') || exit;

/**
 * Marks a plugin integration method as a WordPress hook callback.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class Hook {

    public function __construct(
        public string $name,
        public string $setting,
        public int $priority = 10,
        public int $accepted_args = 1
    ) {}
}
