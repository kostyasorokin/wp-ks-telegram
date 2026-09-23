<?php
/**
 * Admin settings page template.
 *
 * This template renders the KS Telegram settings form and admin
 * actions. It expects sanitized settings from the SettingsPage class.
 *
 * @author  Konstantin Sorokin
 * @link    https://konstantinsorokin.com
 * @package KS_Telegram
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Render a native WordPress table checkbox field.
 *
 * @param string              $key      Setting key.
 * @param string              $label    Field label.
 * @param array<string,mixed> $settings Current settings.
 *
 * @return void
 */
$ks_telegram_table_checkbox = static function (string $key, string $label, array $settings): void {
    ?>
    <label>
        <input type="checkbox" name="<?php echo esc_attr(\KonstantinSorokin\Telegram\Settings\SettingsRepository::OPTION_NAME . '[' . $key . ']'); ?>" value="1" <?php checked(! empty($settings[$key])); ?>>
        <?php echo esc_html($label); ?>
    </label><br>
    <?php
};

/**
 * Get a readable chat type label.
 *
 * @param string $type Telegram chat type.
 *
 * @return string
 */
$ks_telegram_chat_type_label = static function (string $type): string {
    return match ($type) {
        'channel'    => __('Channel', 'ks-telegram'),
        'group'      => __('Group', 'ks-telegram'),
        'private'    => __('Private chat', 'ks-telegram'),
        'supergroup' => __('Supergroup', 'ks-telegram'),
        default      => __('Unknown', 'ks-telegram'),
    };
};

/**
 * Get a readable chat discovery source label.
 *
 * @param string $source Discovery source.
 *
 * @return string
 */
$ks_telegram_chat_source_label = static function (string $source): string {
    return match ($source) {
        'webhook'    => __('Webhook', 'ks-telegram'),
        'getupdates' => __('getUpdates', 'ks-telegram'),
        default      => __('Unknown', 'ks-telegram'),
    };
};
?>

<div class="wrap ks-telegram-admin">
    <h1><?php echo esc_html__('KS Telegram', 'ks-telegram'); ?></h1>

    <?php settings_errors(); ?>

    <?php if (! empty($notice['message'])) : ?>
        <div class="notice notice-<?php echo esc_attr('success' === $notice['type'] ? 'success' : 'error'); ?> is-dismissible">
            <p><?php echo esc_html($notice['message']); ?></p>
        </div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('options.php')); ?>" class="ks-telegram-form">
        <?php settings_fields('ks_telegram'); ?>

        <div class="ks-telegram-tabs" data-ks-telegram-tabs>
            <nav class="nav-tab-wrapper ks-telegram-tabs__nav" aria-label="<?php echo esc_attr__('KS Telegram settings sections', 'ks-telegram'); ?>">
                <button id="ks-telegram-tab-settings" class="nav-tab nav-tab-active ks-telegram-tabs__tab" type="button" role="tab" aria-selected="true" aria-controls="ks-telegram-panel-settings" data-ks-telegram-tab="settings"><?php echo esc_html__('Settings', 'ks-telegram'); ?></button>
                <button id="ks-telegram-tab-messages" class="nav-tab ks-telegram-tabs__tab" type="button" role="tab" aria-selected="false" aria-controls="ks-telegram-panel-messages" data-ks-telegram-tab="messages"><?php echo esc_html__('Messages', 'ks-telegram'); ?></button>
                <button id="ks-telegram-tab-notifications" class="nav-tab ks-telegram-tabs__tab" type="button" role="tab" aria-selected="false" aria-controls="ks-telegram-panel-notifications" data-ks-telegram-tab="notifications"><?php echo esc_html__('Notifications', 'ks-telegram'); ?></button>
                <button id="ks-telegram-tab-plugins" class="nav-tab ks-telegram-tabs__tab" type="button" role="tab" aria-selected="false" aria-controls="ks-telegram-panel-plugins" data-ks-telegram-tab="plugins"><?php echo esc_html__('Plugins', 'ks-telegram'); ?></button>
                <button id="ks-telegram-tab-tests" class="nav-tab ks-telegram-tabs__tab" type="button" role="tab" aria-selected="false" aria-controls="ks-telegram-panel-tests" data-ks-telegram-tab="tests"><?php echo esc_html__('Tests', 'ks-telegram'); ?></button>
            </nav>

            <section id="ks-telegram-panel-settings" class="ks-telegram-tabs__panel is-active" role="tabpanel" aria-labelledby="ks-telegram-tab-settings" data-ks-telegram-panel="settings">
                <h2><?php echo esc_html__('Bot Settings', 'ks-telegram'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="ks-telegram-token"><?php echo esc_html__('Bot token', 'ks-telegram'); ?></label></th>
                        <td>
                            <input id="ks-telegram-token" class="regular-text" type="text" autocomplete="off" name="<?php echo esc_attr(\KonstantinSorokin\Telegram\Settings\SettingsRepository::OPTION_NAME . '[bot_token]'); ?>" value="<?php echo esc_attr((string) $settings['bot_token']); ?>" placeholder="<?php echo esc_attr__('Paste token from BotFather', 'ks-telegram'); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ks-telegram-chat-ids"><?php echo esc_html__('Default chat IDs', 'ks-telegram'); ?></label></th>
                        <td>
                            <textarea id="ks-telegram-chat-ids" class="large-text code" rows="4" name="<?php echo esc_attr(\KonstantinSorokin\Telegram\Settings\SettingsRepository::OPTION_NAME . '[default_chat_ids]'); ?>"><?php echo esc_textarea((string) $settings['default_chat_ids']); ?></textarea>
                            <p class="description"><?php echo esc_html__('Use one chat ID per line, or separate IDs with commas.', 'ks-telegram'); ?></p>
                            <p class="description">
                                <?php
                                printf(
                                    wp_kses(
                                        /* translators: %s: Link to the official Telegram Bot API sendMessage documentation. */
                                        __('Telegram Bot API accepts numeric chat IDs and public channel usernames such as @channelusername. To discover numeric IDs, use the Chat ID helper in the Tests tab. Documentation: %s.', 'ks-telegram'),
                                        ['a' => ['href' => [], 'target' => [], 'rel' => []]]
                                    ),
                                    '<a href="https://core.telegram.org/bots/api#sendmessage" target="_blank" rel="noopener noreferrer">core.telegram.org/bots/api#sendmessage</a>'
                                );
                                ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ks-telegram-parse-mode"><?php echo esc_html__('Parse mode', 'ks-telegram'); ?></label></th>
                        <td>
                            <select id="ks-telegram-parse-mode" name="<?php echo esc_attr(\KonstantinSorokin\Telegram\Settings\SettingsRepository::OPTION_NAME . '[parse_mode]'); ?>">
                                <?php foreach (['MarkdownV2', 'HTML', 'Markdown', 'None'] as $mode) : ?>
                                    <option value="<?php echo esc_attr($mode); ?>" <?php selected((string) $settings['parse_mode'], $mode); ?>><?php echo esc_html($mode); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php
                                printf(
                                    wp_kses(
                                        /* translators: %s: Link to the Telegram Bot API formatting options documentation. */
                                        __('Controls how Telegram parses message formatting. Use MarkdownV2 or HTML when messages contain markup. Documentation: %s.', 'ks-telegram'),
                                        ['a' => ['href' => [], 'target' => [], 'rel' => []]]
                                    ),
                                    '<a href="https://core.telegram.org/bots/api#formatting-options" target="_blank" rel="noopener noreferrer">core.telegram.org/bots/api#formatting-options</a>'
                                );
                                ?>
                            </p>
                            <?php
                            $ks_telegram_table_checkbox('disable_web_page_preview', __('Disable web page previews', 'ks-telegram'), $settings);
                            $ks_telegram_table_checkbox('silent_notifications', __('Silent notifications', 'ks-telegram'), $settings);
                            ?>
                            <p class="description"><?php echo esc_html__('Telegram will deliver messages without notification sound when this option is enabled.', 'ks-telegram'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php echo esc_html__('Telegram Login and Webhook', 'ks-telegram'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__('Login', 'ks-telegram'); ?></th>
                        <td>
                            <?php
                            $ks_telegram_table_checkbox('telegram_login_require_email', __('Ask new Telegram users for an email address after login', 'ks-telegram'), $settings);
                            $ks_telegram_table_checkbox('telegram_login_create_users', __('Create WordPress users for new Telegram accounts', 'ks-telegram'), $settings);
                            $ks_telegram_table_checkbox('telegram_login_upload_avatar', __('Download Telegram profile avatars to this site', 'ks-telegram'), $settings);
                            $ks_telegram_table_checkbox('telegram_login_enabled', __('Enable Telegram Login shortcode', 'ks-telegram'), $settings);
                            $ks_telegram_table_checkbox('telegram_login_request_write', __('Request permission to send direct messages from the bot', 'ks-telegram'), $settings);
                            $ks_telegram_table_checkbox('telegram_login_request_phone', __('Request phone number permission', 'ks-telegram'), $settings);
                            ?>
                            <p class="description"><code>[ks_telegram_login]</code></p>
                            <p class="description">
                                <?php
                                printf(
                                    wp_kses(
                                        /* translators: %s: Link to the official Telegram Login scopes documentation. */
                                        __('Direct message permission uses the telegram:bot_access scope. Telegram does not return a separate ID token claim for it; send attempts should still handle Bot API errors. Documentation: %s.', 'ks-telegram'),
                                        ['a' => ['href' => [], 'target' => [], 'rel' => []]]
                                    ),
                                    '<a href="https://core.telegram.org/bots/telegram-login#available-scopes" target="_blank" rel="noopener noreferrer">core.telegram.org/bots/telegram-login#available-scopes</a>'
                                );
                                ?>
                            </p>
                            <p class="description">
                                <?php
                                printf(
                                    wp_kses(
                                        /* translators: %s: Link to the Telegram Bot API file documentation. */
                                        __('Avatar import uses Bot API profile photos and stores the largest available image in the WordPress media library. It requires a bot token. Documentation: %s.', 'ks-telegram'),
                                        ['a' => ['href' => [], 'target' => [], 'rel' => []]]
                                    ),
                                    '<a href="https://core.telegram.org/bots/api#getuserprofilephotos" target="_blank" rel="noopener noreferrer">core.telegram.org/bots/api#getuserprofilephotos</a>'
                                );
                                ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ks-telegram-login-client-id"><?php echo esc_html__('Login client ID', 'ks-telegram'); ?></label></th>
                        <td>
                            <input id="ks-telegram-login-client-id" class="regular-text" type="text" inputmode="numeric" name="<?php echo esc_attr(\KonstantinSorokin\Telegram\Settings\SettingsRepository::OPTION_NAME . '[telegram_login_client_id]'); ?>" value="<?php echo esc_attr((string) $settings['telegram_login_client_id']); ?>">
                            <p class="description">
                                <?php
                                printf(
                                    wp_kses(
                                        /* translators: %s: Link to the official Telegram Login documentation. */
                                        __('Open @BotFather, choose your bot, then go to Bot Settings > Web Login. Add the allowed URLs and copy the Client ID shown there. Official guide: %s.', 'ks-telegram'),
                                        ['a' => ['href' => [], 'target' => [], 'rel' => []]]
                                    ),
                                    '<a href="https://core.telegram.org/bots/telegram-login#registering-your-allowed-urls" target="_blank" rel="noopener noreferrer">core.telegram.org/bots/telegram-login</a>'
                                );
                                ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ks-telegram-login-client-secret"><?php echo esc_html__('Login client secret', 'ks-telegram'); ?></label></th>
                        <td>
                            <input id="ks-telegram-login-client-secret" class="regular-text" type="text" autocomplete="off" name="<?php echo esc_attr(\KonstantinSorokin\Telegram\Settings\SettingsRepository::OPTION_NAME . '[telegram_login_client_secret]'); ?>" value="<?php echo esc_attr((string) $settings['telegram_login_client_secret']); ?>" placeholder="<?php echo esc_attr__('Paste Web Login secret from BotFather', 'ks-telegram'); ?>">
                            <p class="description">
                                <?php echo esc_html__('BotFather shows the Client Secret in the same Web Login section after allowed URLs are configured. Keep it private and do not commit it to code.', 'ks-telegram'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Login callback URL', 'ks-telegram'); ?></th>
                        <td>
                            <code><?php echo esc_html(add_query_arg(['ks_telegram_login' => 'callback'], home_url('/'))); ?></code>
                            <p class="description"><?php echo esc_html__('Add this URL to the allowed URLs for Telegram Login in BotFather.', 'ks-telegram'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ks-telegram-webhook-secret"><?php echo esc_html__('Webhook secret', 'ks-telegram'); ?></label></th>
                        <td>
                            <input id="ks-telegram-webhook-secret" class="regular-text code" type="text" readonly name="<?php echo esc_attr(\KonstantinSorokin\Telegram\Settings\SettingsRepository::OPTION_NAME . '[webhook_secret]'); ?>" value="<?php echo esc_attr((string) $settings['webhook_secret']); ?>">
                            <p class="description">
                                <code class="ks-telegram-inline-url"><?php echo esc_html(ks_telegram()->settings()->webhookUrl()); ?></code>
                            </p>
                            <?php $ks_telegram_table_checkbox('webhook_enabled', __('Enable REST webhook endpoint', 'ks-telegram'), $settings); ?>
                        </td>
                    </tr>
                </table>

            </section>

            <section id="ks-telegram-panel-messages" class="ks-telegram-tabs__panel" role="tabpanel" aria-labelledby="ks-telegram-tab-messages" data-ks-telegram-panel="messages">
                <h2><?php echo esc_html__('Message Content', 'ks-telegram'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__('Message header', 'ks-telegram'); ?></th>
                        <td>
                        <?php
                        $ks_telegram_table_checkbox('message_header_date', __('Date', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('message_header_time', __('Time', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('message_header_type_hashtag', __('Message type hashtag', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('message_header_site_hashtag', __('Site hashtag', 'ks-telegram'), $settings);
                        ?>
                            <p class="description"><?php echo esc_html__('Controls the first line of Telegram notifications, for example date, time, message type, and site hashtag.', 'ks-telegram'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Request', 'ks-telegram'); ?></th>
                        <td><?php $ks_telegram_table_checkbox('include_request_context', __('Request context', 'ks-telegram'), $settings); ?></td>
                    </tr>
                </table>
            </section>

            <section id="ks-telegram-panel-notifications" class="ks-telegram-tabs__panel" role="tabpanel" aria-labelledby="ks-telegram-tab-notifications" data-ks-telegram-panel="notifications">
                <h2><?php echo esc_html__('Notifications', 'ks-telegram'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__('Core', 'ks-telegram'); ?></th>
                        <td>
                        <?php
                        $ks_telegram_table_checkbox('notifications_enabled', __('Enable notifications', 'ks-telegram'), $settings);
                        ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Comments', 'ks-telegram'); ?></th>
                        <td>
                        <?php
                        $ks_telegram_table_checkbox('notify_comment_approved', __('Approved comments', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('notify_comment_deleted', __('Deleted comments', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('notify_comment_new', __('New comments', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('notify_comment_pending', __('Pending comments', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('notify_comment_spam', __('Spam comments', 'ks-telegram'), $settings);
                        ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Content', 'ks-telegram'); ?></th>
                        <td>
                        <?php
                        $ks_telegram_table_checkbox('notify_new_posts', __('All post status changes', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('notify_post_deleted', __('Deleted posts', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('notify_missed_scheduled_posts', __('Missed scheduled posts', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('notify_post_pending', __('Posts pending review', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('notify_post_published', __('Published posts', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('notify_post_scheduled', __('Scheduled posts', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('notify_post_updated', __('Updated posts', 'ks-telegram'), $settings);
                        ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Media', 'ks-telegram'); ?></th>
                        <td>
                        <?php
                        $ks_telegram_table_checkbox('notify_media_deleted', __('Deleted media files', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('notify_media_large_upload', __('Large media uploads', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('notify_media_uploaded', __('Uploaded media files', 'ks-telegram'), $settings);
                        ?>
                            <p>
                                <label for="ks-telegram-large-upload-threshold"><?php echo esc_html__('Large upload threshold, MB', 'ks-telegram'); ?></label><br>
                                <input id="ks-telegram-large-upload-threshold" class="small-text" type="number" min="1" max="1024" name="<?php echo esc_attr(\KonstantinSorokin\Telegram\Settings\SettingsRepository::OPTION_NAME . '[large_upload_threshold_mb]'); ?>" value="<?php echo esc_attr((string) absint($settings['large_upload_threshold_mb'] ?? 10)); ?>">
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Security', 'ks-telegram'); ?></th>
                        <td>
                        <?php
                        $ks_telegram_table_checkbox('include_plain_passwords', __('Include plain passwords in authorization alerts', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('notify_admin_login', __('Administrator logins', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('notify_application_passwords', __('Application password changes', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('notify_authorization', __('Authorization attempts', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('notify_failed_login', __('Failed logins', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('notify_recovery_mode', __('Recovery mode emails', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('notify_wp_mail_failed', __('Failed WordPress emails', 'ks-telegram'), $settings);
                        ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('System', 'ks-telegram'); ?></th>
                        <td>
                        <?php
                        $ks_telegram_table_checkbox('notify_option_changes', __('Important option changes', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('notify_plugin_activation', __('Plugin activations', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('notify_plugin_deactivation', __('Plugin deactivations', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('notify_site_health', __('Site Health issues', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('notify_theme_switch', __('Theme switches', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('notify_updates', __('WordPress, plugin, and theme updates', 'ks-telegram'), $settings);
                        ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Users', 'ks-telegram'); ?></th>
                        <td>
                        <?php
                        $ks_telegram_table_checkbox('notify_new_admin', __('New administrators', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('notify_password_reset', __('Completed password resets', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('notify_password_reset_request', __('Password reset requests', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('notify_profile_update', __('Profile updates', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('notify_user_email_change', __('User email changes', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('notify_user_registration', __('User registrations', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('notify_user_role_change', __('User role changes', 'ks-telegram'), $settings);
                        ?>
                        </td>
                    </tr>
                </table>

                <h2><?php echo esc_html__('Publish to Telegram channel', 'ks-telegram'); ?></h2>
                <p class="description"><?php echo esc_html__('Send a linked title without a preview when selected content is published for the first time. This is separate from administrator notifications and never uses the default chat IDs.', 'ks-telegram'); ?></p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="ks-telegram-channel-id"><?php echo esc_html__('Channel ID or @username', 'ks-telegram'); ?></label></th>
                        <td>
                            <input id="ks-telegram-channel-id" class="regular-text code" type="text" autocomplete="off" name="<?php echo esc_attr(\KonstantinSorokin\Telegram\Settings\SettingsRepository::OPTION_NAME . '[channel_chat_id]'); ?>" value="<?php echo esc_attr((string) $settings['channel_chat_id']); ?>" placeholder="@channelusername">
                            <p class="description"><?php echo esc_html__('Enter exactly one channel. The bot must be a channel administrator with permission to post messages. Leave this empty to disable public publishing.', 'ks-telegram'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('WordPress content', 'ks-telegram'); ?></th>
                        <td>
                            <?php
                            $ks_telegram_table_checkbox('channel_publish_posts', __('Posts', 'ks-telegram'), $settings);
                            $ks_telegram_table_checkbox('channel_publish_pages', __('Pages', 'ks-telegram'), $settings);
                            ?>
                        </td>
                    </tr>
                </table>
                <div class="ks-telegram-custom-post-types">
                    <h3><?php echo esc_html__('Custom post types', 'ks-telegram'); ?></h3>
                    <p class="description"><?php echo esc_html__('Public custom post types with an admin screen appear here automatically. New types remain off until selected.', 'ks-telegram'); ?></p>
                    <?php if ([] === $custom_post_types) : ?>
                        <p><?php echo esc_html__('No eligible custom post types found.', 'ks-telegram'); ?></p>
                    <?php else : ?>
                        <?php foreach ($custom_post_types as $custom_post_type) : ?>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(\KonstantinSorokin\Telegram\Settings\SettingsRepository::OPTION_NAME . '[channel_publish_custom_types][]'); ?>" value="<?php echo esc_attr($custom_post_type->name); ?>" <?php checked(in_array($custom_post_type->name, $selected_custom_post_types, true)); ?>>
                                <?php echo esc_html($custom_post_type->label); ?> <code><?php echo esc_html($custom_post_type->name); ?></code>
                            </label><br>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <p class="description"><?php echo esc_html__('Messages are queued after publication and sent by WordPress cron. Earlier publications and later edits are not sent again. Failed deliveries are retried up to three times.', 'ks-telegram'); ?></p>
            </section>

            <section id="ks-telegram-panel-plugins" class="ks-telegram-tabs__panel" role="tabpanel" aria-labelledby="ks-telegram-tab-plugins" data-ks-telegram-panel="plugins">
                <h2><?php echo esc_html__('Plugins', 'ks-telegram'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__('Contact Form 7', 'ks-telegram'); ?></th>
                        <td>
                            <?php
                            $ks_telegram_table_checkbox('notify_contact_form_7', __('Contact Form 7 submissions', 'ks-telegram'), $settings);
                            $ks_telegram_table_checkbox('notify_contact_form_7_rejected', __('Also send submissions Contact Form 7 rejected (spam, mail failure)', 'ks-telegram'), $settings);
                            ?>
                            <p class="description"><?php echo esc_html__('A submission rejected as spam never reaches the notification hook, so it arrives nowhere and the visitor is told the message could not be sent. Forwarding rejections lets you see whether the spam check is right.', 'ks-telegram'); ?></p>
                            <?php $ks_telegram_table_checkbox('cf7_skip_mail', __('Do not let Contact Form 7 send email — Telegram is the delivery channel', 'ks-telegram'), $settings); ?>
                            <p class="description"><?php echo esc_html__('Contact Form 7 shows an error whenever the site cannot send mail, whatever the reason. On a site that delivers enquiries through Telegram, skipping the mail step makes it report the truth. Ignored unless a bot token and a chat are configured, so this can never accept an enquiry and deliver it nowhere.', 'ks-telegram'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ks-telegram-cf7-spam-streak"><?php echo esc_html__('Warn about a broken spam check', 'ks-telegram'); ?></label></th>
                        <td>
                            <input type="number" min="0" max="100" step="1" class="small-text" id="ks-telegram-cf7-spam-streak"
                                   name="<?php echo esc_attr(\KonstantinSorokin\Telegram\Settings\SettingsRepository::OPTION_NAME . '[cf7_spam_streak_threshold]'); ?>"
                                   value="<?php echo esc_attr((string) ($settings['cf7_spam_streak_threshold'] ?? 5)); ?>">
                            <p class="description"><?php echo esc_html__('Send a warning once this many submissions in a row have been rejected as spam. Real spam arrives in bursts; a check that has stopped working rejects everything, so an unbroken run is evidence about the check rather than about the senders. Set to 0 to never warn.', 'ks-telegram'); ?></p>
                            <?php $ks_telegram_table_checkbox('health_recaptcha_probe', __('Let Site Health test the reCAPTCHA key against Google', 'ks-telegram'), $settings); ?>
                            <p class="description"><?php echo esc_html__('Off by default because it sends your Contact Form 7 reCAPTCHA secret key to Google in order to ask whether the key is still accepted. That is the only request this plugin ever makes to Google. With it off, Site Health still reports a run of spam rejections, which is the same symptom without the third party.', 'ks-telegram'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Mailchimp', 'ks-telegram'); ?></th>
                        <td>
                        <?php
                        $ks_telegram_table_checkbox('notify_mailchimp_subscribe', __('Mailchimp subscriptions', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('notify_mailchimp_unsubscribe', __('Mailchimp unsubscribes', 'ks-telegram'), $settings);
                        ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('WooCommerce', 'ks-telegram'); ?></th>
                        <td>
                        <?php
                        $ks_telegram_table_checkbox('notify_wc_add_to_cart', __('Add to cart events', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('wc_checkout_telegram_field', __('Checkout Telegram handle field', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('notify_wc_low_stock', __('Low stock alerts', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('notify_wc_order_status', __('Order status changes', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('notify_wc_order', __('New orders', 'ks-telegram'), $settings);
                        ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('WooCommerce billing message', 'ks-telegram'); ?></th>
                        <td>
                        <?php
                        $ks_telegram_table_checkbox('include_billing_address', __('Billing address', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('include_billing_company', __('Billing company', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('include_billing_email', __('Billing email', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('include_billing_name', __('Billing name', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('include_billing_phone', __('Billing phone', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('include_billing', __('Billing section', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('include_billing_telegram', __('Billing Telegram handle', 'ks-telegram'), $settings);
                        ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('WooCommerce order message', 'ks-telegram'); ?></th>
                        <td>
                        <?php
                        $ks_telegram_table_checkbox('include_order_customer_note', __('Customer note', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('include_order_transaction_id', __('Order transaction ID', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('include_order_utm', __('Order UTM fields', 'ks-telegram'), $settings);
                        ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('WooCommerce shipping message', 'ks-telegram'); ?></th>
                        <td>
                        <?php
                        $ks_telegram_table_checkbox('include_shipping_address', __('Shipping address', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('include_shipping_company', __('Shipping company', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('include_shipping_name', __('Shipping name', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('include_shipping_phone', __('Shipping phone', 'ks-telegram'), $settings);
                        $ks_telegram_table_checkbox('include_shipping', __('Shipping section', 'ks-telegram'), $settings);
                        ?>
                        </td>
                    </tr>
                </table>
            </section>
        </div>

        <div data-ks-telegram-save-actions>
            <?php submit_button(__('Save Settings', 'ks-telegram')); ?>
        </div>
    </form>

    <div class="ks-telegram-related-panel is-active" data-ks-telegram-related-panel="settings">
        <hr>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ks-telegram-action ks-telegram-webhook-actions">
            <h2><?php echo esc_html__('Webhook Actions', 'ks-telegram'); ?></h2>
            <p class="description">
                <?php echo esc_html__('Register the webhook after changing the bot token, HTTPS URL, or webhook secret. Delete it when the bot should stop sending updates to this site.', 'ks-telegram'); ?>
            </p>
            <?php wp_nonce_field('ks_telegram_webhook_action'); ?>
            <input type="hidden" name="action" value="ks_telegram_webhook_action">
            <button class="button button-secondary" type="submit" name="command" value="set"><?php echo esc_html__('Register Webhook', 'ks-telegram'); ?></button>
            <button class="button" type="submit" name="command" value="delete"><?php echo esc_html__('Delete Webhook', 'ks-telegram'); ?></button>
        </form>
    </div>

    <section id="ks-telegram-panel-tests" class="ks-telegram-related-panel" role="tabpanel" aria-labelledby="ks-telegram-tab-tests" data-ks-telegram-related-panel="tests" hidden>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ks-telegram-action">
            <h2><?php echo esc_html__('Test Message', 'ks-telegram'); ?></h2>
            <?php wp_nonce_field('ks_telegram_test_message'); ?>
            <input type="hidden" name="action" value="ks_telegram_test_message">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="ks-telegram-test-chat"><?php echo esc_html__('Chat ID override', 'ks-telegram'); ?></label></th>
                    <td><input id="ks-telegram-test-chat" class="regular-text" type="text" name="chat_id" value=""></td>
                </tr>
                <tr>
                    <th scope="row"><label for="ks-telegram-test-message"><?php echo esc_html__('Message', 'ks-telegram'); ?></label></th>
                    <td><textarea id="ks-telegram-test-message" class="large-text" rows="3" name="message"></textarea></td>
                </tr>
            </table>
            <?php submit_button(__('Send Test Message', 'ks-telegram'), 'secondary'); ?>
        </form>

        <hr>

        <h2><?php echo esc_html__('Delivery', 'ks-telegram'); ?></h2>
        <?php if ($delivery_is_failing) : ?>
            <p class="notice notice-error inline"><strong><?php echo esc_html__('The last attempt failed and nothing has been delivered since.', 'ks-telegram'); ?></strong></p>
        <?php endif; ?>
        <p class="description">
            <?php
            echo 0 === $delivery_last_ok
                ? esc_html__('No message has been delivered yet.', 'ks-telegram')
                : esc_html(
                    sprintf(
                        /* translators: %s: human-readable time difference. */
                        __('Last delivered %s ago.', 'ks-telegram'),
                        human_time_diff($delivery_last_ok)
                    )
                );
            ?>
        </p>

        <?php if ([] === $delivery_failures) : ?>
            <p><?php echo esc_html__('No failures on record.', 'ks-telegram'); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th scope="col"><?php echo esc_html__('When', 'ks-telegram'); ?></th>
                        <th scope="col"><?php echo esc_html__('Chat', 'ks-telegram'); ?></th>
                        <th scope="col"><?php echo esc_html__('Telegram said', 'ks-telegram'); ?></th>
                        <th scope="col"><?php echo esc_html__('Size', 'ks-telegram'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($delivery_failures as $ks_telegram_failure) : ?>
                    <tr>
                        <td><?php echo esc_html(wp_date('Y-m-d H:i', (int) ($ks_telegram_failure['time'] ?? 0))); ?></td>
                        <td><code><?php echo esc_html((string) ($ks_telegram_failure['chat_id'] ?? '')); ?></code></td>
                        <td><?php echo esc_html((string) ($ks_telegram_failure['message'] ?? '')); ?></td>
                        <td>
                            <?php
                            /*
                             * The count must be a plain variable: `wp i18n make-pot` silently fails to
                             * extract an _n() whose count argument is a complex expression.
                             */
                            $ks_telegram_length = (int) ($ks_telegram_failure['length'] ?? 0);

                            /* translators: %d: message length in characters. */
                            echo esc_html(sprintf(_n('%d character', '%d characters', $ks_telegram_length, 'ks-telegram'), $ks_telegram_length));
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description"><?php echo esc_html__('These are the words Telegram itself returned. "chat not found" and "bot was blocked by the user" are different problems with different fixes; both used to arrive as a bare failure with no explanation.', 'ks-telegram'); ?></p>
        <?php endif; ?>

        <hr>

        <h2><?php echo esc_html__('Chat ID helper', 'ks-telegram'); ?></h2>
        <p class="description">
            <?php echo esc_html__('Send a message to the bot, add it to a group, or add it as a channel admin, then scan updates. If webhook is enabled, incoming webhook updates are saved here automatically without message text.', 'ks-telegram'); ?>
        </p>
        <h3><?php echo esc_html__('How to find a chat ID', 'ks-telegram'); ?></h3>
        <ul class="ul-disc">
            <li><?php echo esc_html__('Private profile: open the bot in Telegram and send any message to it. Then scan latest updates, or refresh this page if webhook is enabled.', 'ks-telegram'); ?></li>
            <li><?php echo esc_html__('Group or supergroup: add the bot to the group and send a command or message the bot can receive. If BotFather privacy mode is enabled, send a command addressed to the bot, for example /start@your_bot_username.', 'ks-telegram'); ?></li>
            <li><?php echo esc_html__('Channel: add the bot as a channel administrator and publish a new post. Public channels can also be used as @channelusername.', 'ks-telegram'); ?></li>
            <li><?php echo esc_html__('Copy the discovered ID from the table into Default chat IDs. Negative IDs for groups, supergroups, and channels are normal.', 'ks-telegram'); ?></li>
        </ul>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ks-telegram-action">
            <?php wp_nonce_field('ks_telegram_chat_id_helper'); ?>
            <input type="hidden" name="action" value="ks_telegram_chat_id_helper">
            <p>
                <button class="button button-secondary" type="submit" name="command" value="refresh"><?php echo esc_html__('Scan latest updates', 'ks-telegram'); ?></button>
                <button class="button" type="submit" name="command" value="clear"><?php echo esc_html__('Clear list', 'ks-telegram'); ?></button>
            </p>
            <p class="description">
                <?php echo esc_html__('The scan button uses getUpdates and can fail while a Telegram webhook is active. In that case, send a fresh message and refresh this page after the webhook receives it.', 'ks-telegram'); ?>
            </p>
        </form>

        <?php if (empty($chat_id_helper_items)) : ?>
            <p><?php echo esc_html__('No Telegram chats discovered yet.', 'ks-telegram'); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th scope="col"><?php echo esc_html__('Chat ID', 'ks-telegram'); ?></th>
                        <th scope="col"><?php echo esc_html__('Type', 'ks-telegram'); ?></th>
                        <th scope="col"><?php echo esc_html__('Name', 'ks-telegram'); ?></th>
                        <th scope="col"><?php echo esc_html__('Source', 'ks-telegram'); ?></th>
                        <th scope="col"><?php echo esc_html__('Last seen', 'ks-telegram'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($chat_id_helper_items as $ks_telegram_chat_item) : ?>
                        <?php
                        $ks_telegram_chat_title    = (string) ($ks_telegram_chat_item['title'] ?? '');
                        $ks_telegram_chat_username = (string) ($ks_telegram_chat_item['username'] ?? '');
                        $ks_telegram_chat_name     = '' !== $ks_telegram_chat_title ? $ks_telegram_chat_title : ('' !== $ks_telegram_chat_username ? '@' . $ks_telegram_chat_username : __('Private chat', 'ks-telegram'));
                        $ks_telegram_chat_date     = ! empty($ks_telegram_chat_item['last_seen'])
                            ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), absint($ks_telegram_chat_item['last_seen']))
                            : '';
                        ?>
                        <tr>
                            <td><code><?php echo esc_html((string) ($ks_telegram_chat_item['id'] ?? '')); ?></code></td>
                            <td><?php echo esc_html($ks_telegram_chat_type_label((string) ($ks_telegram_chat_item['type'] ?? 'unknown'))); ?></td>
                            <td><?php echo esc_html($ks_telegram_chat_name); ?></td>
                            <td><?php echo esc_html($ks_telegram_chat_source_label((string) ($ks_telegram_chat_item['source'] ?? 'unknown'))); ?></td>
                            <td><?php echo esc_html($ks_telegram_chat_date); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</div>
