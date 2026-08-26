<?php
/**
 * Telegram parse mode enum.
 *
 * This file defines the parse modes supported by outgoing Telegram messages.
 *
 * @author  Konstantin Sorokin
 * @link    https://konstantinsorokin.com
 * @package KS_Telegram
 */

declare(strict_types=1);

namespace KonstantinSorokin\Telegram\Bot\Enum;

defined('ABSPATH') || exit;

/**
 * Type-safe Telegram parse modes.
 */
enum ParseMode: string {

    case MarkdownV2 = 'MarkdownV2';
    case Markdown = 'Markdown';
    case Html = 'HTML';
    case None = 'None';
}
