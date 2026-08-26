<?php
/**
 * Telegram Login profile completion template.
 *
 * This template asks a newly authenticated Telegram user for contact data that
 * is required by WordPress but not available from Telegram Login.
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
 * @var string $action_url    Form action URL.
 * @var string $email         Current user email or empty placeholder.
 * @var string $error_message Current validation error message.
 * @var string $phone         Current Telegram phone or empty string.
 * @var string $redirect_to   Final redirect target.
 * @var bool   $show_phone    Whether the phone field should be shown.
 */
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class('ks-telegram-profile-completion'); ?>>
    <main class="ks-telegram-profile-completion__screen">
        <form class="ks-telegram-profile-completion__form" method="post" action="<?php echo esc_url($action_url); ?>">
            <?php wp_nonce_field('ks_telegram_complete_profile'); ?>
            <input type="hidden" name="redirect_to" value="<?php echo esc_url($redirect_to); ?>">

            <h1><?php echo esc_html__('Complete your profile', 'ks-telegram'); ?></h1>
            <p><?php echo esc_html__('Telegram does not provide an email address, so we need one to finish creating your WordPress account.', 'ks-telegram'); ?></p>

            <?php if ('' !== $error_message) : ?>
                <div class="ks-telegram-profile-completion__error" role="alert">
                    <?php echo esc_html($error_message); ?>
                </div>
            <?php endif; ?>

            <label for="ks-telegram-profile-email">
                <?php echo esc_html__('Email address', 'ks-telegram'); ?>
            </label>
            <input
                id="ks-telegram-profile-email"
                type="email"
                name="email"
                value="<?php echo esc_attr($email); ?>"
                autocomplete="email"
                required
            >

            <?php if ($show_phone) : ?>
                <label for="ks-telegram-profile-phone">
                    <?php echo esc_html__('Phone number', 'ks-telegram'); ?>
                    <span><?php echo esc_html__('optional', 'ks-telegram'); ?></span>
                </label>
                <input
                    id="ks-telegram-profile-phone"
                    type="tel"
                    name="phone"
                    value="<?php echo esc_attr($phone); ?>"
                    autocomplete="tel"
                    inputmode="tel"
                >
            <?php endif; ?>

            <button type="submit">
                <?php echo esc_html__('Save and continue', 'ks-telegram'); ?>
            </button>
        </form>
    </main>
    <?php wp_footer(); ?>
</body>
</html>
