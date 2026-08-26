<?php
/**
 * Telegram user profile fields template.
 *
 * This template renders Telegram account metadata on WordPress user profile
 * screens without exposing secrets or authorization tokens.
 *
 * @author  Konstantin Sorokin
 * @link    https://konstantinsorokin.com
 * @package KS_Telegram
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Template variables.
 *
 * @var string $phone       Telegram phone number.
 * @var string $telegram_id Telegram account ID.
 * @var string $username    Telegram username.
 */
?>
<h2><?php echo esc_html__('Telegram', 'ks-telegram'); ?></h2>

<table class="form-table" role="presentation">
    <?php if ('' !== $telegram_id) : ?>
        <tr>
            <th scope="row"><?php echo esc_html__('Telegram ID', 'ks-telegram'); ?></th>
            <td><code><?php echo esc_html($telegram_id); ?></code></td>
        </tr>
    <?php endif; ?>

    <?php if ('' !== $username) : ?>
        <tr>
            <th scope="row"><?php echo esc_html__('Telegram username', 'ks-telegram'); ?></th>
            <td><code>@<?php echo esc_html($username); ?></code></td>
        </tr>
    <?php endif; ?>

    <tr>
        <th scope="row">
            <label for="ks-telegram-user-phone"><?php echo esc_html__('Telegram phone', 'ks-telegram'); ?></label>
        </th>
        <td>
            <input
                id="ks-telegram-user-phone"
                class="regular-text"
                type="tel"
                name="ks_telegram_phone"
                value="<?php echo esc_attr($phone); ?>"
                autocomplete="tel"
                inputmode="tel"
            >
            <p class="description"><?php echo esc_html__('Stored from Telegram Login when the user shares a phone number, or entered manually.', 'ks-telegram'); ?></p>
        </td>
    </tr>
</table>
