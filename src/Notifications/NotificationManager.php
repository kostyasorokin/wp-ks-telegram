<?php
/**
 * Notification hook manager.
 *
 * This file registers WordPress notification hooks and sends their messages
 * through the shared Telegram sender.
 *
 * @author  Konstantin Sorokin
 * @link    https://konstantinsorokin.com
 * @package KS_Telegram
 */

declare(strict_types=1);

namespace KonstantinSorokin\Telegram\Notifications;

use KonstantinSorokin\Telegram\Bot\MessageSender;
use KonstantinSorokin\Telegram\Settings\SettingsRepository;
use WP_Comment;
use WP_Error;
use WP_Post;
use WP_User;

defined('ABSPATH') || exit;

/**
 * Registers notification hooks.
 */
final readonly class NotificationManager {

    private const string MISSED_SCHEDULE_HOOK = 'ks_telegram_check_missed_scheduled_posts';

    private MessageFactory $messages;

    public function __construct(
        private SettingsRepository $settings,
        private MessageSender $sender
    ) {
        $this->messages = new MessageFactory($settings);
    }

    /**
     * Register enabled notification hooks.
     *
     * @return void
     */
    public function boot(): void {
        add_action(self::MISSED_SCHEDULE_HOOK, [$this, 'missedScheduledPosts']);

        if ($this->settings->bool('notifications_enabled') && $this->settings->bool('notify_missed_scheduled_posts')) {
            $this->ensureMissedScheduleCron();
        } else {
            wp_clear_scheduled_hook(self::MISSED_SCHEDULE_HOOK);
        }

        if (! $this->settings->bool('notifications_enabled')) {
            return;
        }

        if ($this->hasAny(['notify_new_posts', 'notify_post_pending', 'notify_post_published', 'notify_post_scheduled'])) {
            add_action('transition_post_status', [$this, 'postStatusChanged'], 10, 3);
        }

        if ($this->settings->bool('notify_post_updated')) {
            add_action('post_updated', [$this, 'postUpdated'], 10, 3);
        }

        if ($this->settings->bool('notify_post_deleted')) {
            add_action('trashed_post', [$this, 'postTrashed'], 10, 2);
            add_action('deleted_post', [$this, 'postDeleted'], 10, 2);
        }

        if ($this->hasAny(['notify_comment_approved', 'notify_comment_new', 'notify_comment_pending', 'notify_comment_spam'])) {
            add_action('comment_post', [$this, 'commentPosted'], 10, 3);
        }

        if ($this->hasAny(['notify_comment_approved', 'notify_comment_pending', 'notify_comment_spam'])) {
            add_action('transition_comment_status', [$this, 'commentStatusChanged'], 10, 3);
        }

        if ($this->settings->bool('notify_comment_deleted')) {
            add_action('deleted_comment', [$this, 'commentDeleted'], 10, 2);
            add_action('trashed_comment', [$this, 'commentTrashed'], 10, 2);
        }

        if ($this->hasAny(['notify_admin_login', 'notify_authorization', 'notify_failed_login'])) {
            add_filter('authenticate', [$this, 'authorizationAttempt'], 99, 3);
        }

        if ($this->hasAny(['notify_new_admin', 'notify_user_registration'])) {
            add_action('user_register', [$this, 'userRegistered']);
        }

        if ($this->hasAny(['notify_new_admin', 'notify_user_role_change'])) {
            add_action('set_user_role', [$this, 'userRoleChanged'], 10, 3);
        }

        if ($this->settings->bool('notify_password_reset_request')) {
            add_action('retrieve_password', [$this, 'passwordResetRequested']);
        }

        if ($this->settings->bool('notify_password_reset')) {
            add_action('password_reset', [$this, 'passwordReset'], 10, 2);
        }

        if ($this->hasAny(['notify_profile_update', 'notify_user_email_change'])) {
            add_action('profile_update', [$this, 'profileUpdated'], 10, 3);
        }

        if ($this->hasAny(['notify_media_large_upload', 'notify_media_uploaded'])) {
            add_action('add_attachment', [$this, 'attachmentAdded']);
        }

        if ($this->settings->bool('notify_media_deleted')) {
            add_action('delete_attachment', [$this, 'attachmentDeleted'], 10, 2);
        }

        if ($this->settings->bool('notify_application_passwords')) {
            add_action('wp_create_application_password', [$this, 'applicationPasswordCreated'], 10, 4);
            add_action('wp_delete_application_password', [$this, 'applicationPasswordDeleted'], 10, 2);
        }

        if ($this->settings->bool('notify_option_changes')) {
            add_action('update_option', [$this, 'optionUpdated'], 10, 3);
        }

        if ($this->settings->bool('notify_plugin_activation')) {
            add_action('activated_plugin', [$this, 'pluginActivated'], 10, 2);
        }

        if ($this->settings->bool('notify_plugin_deactivation')) {
            add_action('deactivated_plugin', [$this, 'pluginDeactivated'], 10, 2);
        }

        if ($this->settings->bool('notify_recovery_mode')) {
            add_filter('recovery_mode_email', [$this, 'recoveryModeEmail'], 10, 2);
        }

        if ($this->settings->bool('notify_theme_switch')) {
            add_action('switch_theme', [$this, 'themeSwitched'], 10, 3);
        }

        if ($this->settings->bool('notify_site_health')) {
            add_action('wp_site_health_scheduled_check', [$this, 'siteHealthChecked'], 20);
        }

        if ($this->settings->bool('notify_updates')) {
            add_action('upgrader_process_complete', [$this, 'upgraderProcessComplete'], 10, 2);
            add_action('automatic_updates_complete', [$this, 'automaticUpdatesComplete']);
        }

        if ($this->settings->bool('notify_wp_mail_failed')) {
            add_action('wp_mail_failed', [$this, 'mailFailed']);
        }

    }

    /**
     * Send a post status notification.
     *
     * @param string  $new_status New status.
     * @param string  $old_status Old status.
     * @param WP_Post $post       Post object.
     *
     * @return void
     */
    public function postStatusChanged(string $new_status, string $old_status, WP_Post $post): void {
        if ($new_status === $old_status || ! $this->canNotifyPost($post)) {
            return;
        }

        if ($this->settings->bool('notify_new_posts')) {
            $this->sendPostStatusMessage($new_status, $old_status, $post);

            return;
        }

        $title = match ($new_status) {
            'pending' => $this->settings->bool('notify_post_pending') ? __('Post pending review', 'ks-telegram') : '',
            'publish' => $this->settings->bool('notify_post_published') ? __('Post published', 'ks-telegram') : '',
            'future' => $this->settings->bool('notify_post_scheduled') ? __('Post scheduled', 'ks-telegram') : '',
            default => '',
        };

        if ('' === $title) {
            return;
        }

        $this->sendPostStatusMessage($new_status, $old_status, $post, $title);
    }

    /**
     * Send a post update notification.
     *
     * @param int     $post_id     Post ID.
     * @param WP_Post $post_after  Updated post.
     * @param WP_Post $post_before Previous post.
     *
     * @return void
     */
    public function postUpdated(int $post_id, WP_Post $post_after, WP_Post $post_before): void {
        unset($post_id);

        if (! $this->canNotifyPost($post_after) || $post_after->post_status !== $post_before->post_status) {
            return;
        }

        $changed = [];
        foreach (['post_title', 'post_content', 'post_excerpt', 'post_name'] as $field) {
            if (($post_after->{$field} ?? null) !== ($post_before->{$field} ?? null)) {
                $changed[] = $this->postFieldLabel($field);
            }
        }

        if ([] === $changed) {
            return;
        }

        $this->sender->send(
            null,
            $this->messages->postEvent(
                'content',
                __('Post updated', 'ks-telegram'),
                $post_after,
                [__('Changed fields', 'ks-telegram') => $changed]
            )
        );
    }

    /**
     * Send a post trash notification.
     *
     * @param int    $post_id         Post ID.
     * @param string $previous_status Previous post status.
     *
     * @return void
     */
    public function postTrashed(int $post_id, string $previous_status): void {
        $post = get_post($post_id);
        if (! $post instanceof WP_Post || ! $this->canNotifyPost($post)) {
            return;
        }

        $this->sender->send(
            null,
            $this->messages->postEvent(
                'content',
                __('Post moved to trash', 'ks-telegram'),
                $post,
                [__('Previous status', 'ks-telegram') => $previous_status]
            )
        );
    }

    /**
     * Send a permanent post deletion notification.
     *
     * @param int     $post_id Post ID.
     * @param WP_Post $post    Deleted post.
     *
     * @return void
     */
    public function postDeleted(int $post_id, WP_Post $post): void {
        unset($post_id);

        if (! $this->canNotifyPost($post)) {
            return;
        }

        $this->sender->send(null, $this->messages->postEvent('content', __('Post deleted permanently', 'ks-telegram'), $post));
    }

    /**
     * Scan for scheduled posts that are overdue.
     *
     * @return void
     */
    public function missedScheduledPosts(): void {
        if (! $this->settings->bool('notifications_enabled') || ! $this->settings->bool('notify_missed_scheduled_posts')) {
            return;
        }

        $query = new \WP_Query(
            [
                'date_query'     => [
                    [
                        'before'    => gmdate('Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS),
                        'column'    => 'post_date_gmt',
                        'inclusive' => true,
                    ],
                ],
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'post_status'    => 'future',
                'post_type'      => $this->postTypes(),
                'posts_per_page' => 25,
            ]
        );

        $sent = 0;
        foreach ($query->posts as $post_id) {
            if (10 <= $sent || get_post_meta((int) $post_id, '_ks_telegram_missed_schedule_notified', true)) {
                continue;
            }

            $post = get_post((int) $post_id);
            if (! $post instanceof WP_Post) {
                continue;
            }

            /*
             * The marker is written after a successful send, not before it.
             * Written first, a refused message still suppressed the post
             * forever: the guard above skips anything carrying the marker, and
             * the hourly cron would never look at it again. The notification
             * was lost and the record said it had been made.
             *
             * The cost of the correct order is that a permanently broken
             * Telegram connection makes the cron retry each hour. That is
             * bounded — the loop stops at ten per run — and a retry that keeps
             * failing is visible in the delivery log, which is where a delivery
             * problem belongs.
             */
            if ($this->sender->send(null, $this->messages->postEvent('content', __('Scheduled post missed publication time', 'ks-telegram'), $post))) {
                update_post_meta($post->ID, '_ks_telegram_missed_schedule_notified', time());
            }

            ++$sent;
        }
    }

    /**
     * Send a new comment notification.
     *
     * @param int              $comment_id       Comment ID.
     * @param string|int|false $comment_approved Approval state.
     * @param array<mixed>     $commentdata      Raw comment data.
     *
     * @return void
     */
    public function commentPosted(int $comment_id, string|int|false $comment_approved, array $commentdata): void {
        unset($comment_approved, $commentdata);

        $comment = get_comment($comment_id);
        if (! $comment instanceof WP_Comment) {
            return;
        }

        [$setting, $title] = match ((string) $comment->comment_approved) {
            '0' => ['notify_comment_pending', __('New comment pending moderation', 'ks-telegram')],
            '1' => ['notify_comment_approved', __('New approved comment', 'ks-telegram')],
            'spam' => ['notify_comment_spam', __('New spam comment', 'ks-telegram')],
            default => ['notify_comment_new', __('New comment', 'ks-telegram')],
        };

        if (! $this->settings->bool($setting)) {
            $setting = 'notify_comment_new';
            $title   = __('New comment', 'ks-telegram');
        }

        if ($this->settings->bool($setting)) {
            $this->sender->send(null, $this->messages->commentEvent('comment', $title, $comment));
        }
    }

    /**
     * Send a comment status transition notification.
     *
     * @param string     $new_status New status.
     * @param string     $old_status Old status.
     * @param WP_Comment $comment    Comment object.
     *
     * @return void
     */
    public function commentStatusChanged(string $new_status, string $old_status, WP_Comment $comment): void {
        if ($new_status === $old_status) {
            return;
        }

        [$setting, $title] = match ($new_status) {
            'approved' => ['notify_comment_approved', __('Comment approved', 'ks-telegram')],
            'spam' => ['notify_comment_spam', __('Comment marked as spam', 'ks-telegram')],
            'unapproved' => ['notify_comment_pending', __('Comment moved to moderation', 'ks-telegram')],
            default => ['', ''],
        };

        if ('' === $setting || ! $this->settings->bool($setting)) {
            return;
        }

        $this->sender->send(
            null,
            $this->messages->commentEvent(
                'comment',
                $title,
                $comment
            )
        );
    }

    /**
     * Send a deleted comment notification.
     *
     * @param string|int $comment_id Comment ID.
     * @param WP_Comment $comment    Comment object.
     *
     * @return void
     */
    public function commentDeleted(string|int $comment_id, WP_Comment $comment): void {
        unset($comment_id);

        $this->sender->send(null, $this->messages->commentEvent('comment', __('Comment deleted permanently', 'ks-telegram'), $comment));
    }

    /**
     * Send a trashed comment notification.
     *
     * @param string|int $comment_id Comment ID.
     * @param WP_Comment $comment    Comment object.
     *
     * @return void
     */
    public function commentTrashed(string|int $comment_id, WP_Comment $comment): void {
        unset($comment_id);

        $this->sender->send(null, $this->messages->commentEvent('comment', __('Comment moved to trash', 'ks-telegram'), $comment));
    }

    /**
     * Send a user registration notification.
     *
     * @param int $user_id User ID.
     *
     * @return void
     */
    public function userRegistered(int $user_id): void {
        $user = get_userdata($user_id);

        if (! $user instanceof WP_User) {
            return;
        }

        if ($this->settings->bool('notify_user_registration')) {
            $this->sender->send(null, $this->messages->userRegistration($user));
        }

        if ($this->settings->bool('notify_new_admin') && in_array('administrator', $user->roles, true)) {
            $this->sender->send(null, $this->messages->userEvent('user', __('New administrator created', 'ks-telegram'), $user));
        }
    }

    /**
     * Send an authorization notification and preserve WordPress auth flow.
     *
     * @param mixed  $user_data Authentication result.
     * @param string $username  Submitted username.
     * @param string $password  Submitted password.
     *
     * @return mixed
     */
    public function authorizationAttempt(mixed $user_data, string $username, string $password): mixed {
        if ($this->settings->bool('notify_authorization')) {
            $message = $this->messages->authorization($user_data, $username, $password);

            if ('' !== $message) {
                $this->sender->send(null, $message);
            }

            return $user_data;
        }

        if ($user_data instanceof WP_User && $this->settings->bool('notify_admin_login') && in_array('administrator', $user_data->roles, true)) {
            $this->sender->send(null, $this->messages->userEvent('admin_login', __('Administrator logged in', 'ks-telegram'), $user_data));
        }

        if (is_wp_error($user_data) && $this->settings->bool('notify_failed_login') && '' !== $username) {
            $this->sender->send(
                null,
                $this->messages->event(
                    'failed_login',
                    __('Failed login attempt', 'ks-telegram'),
                    [
                        __('Login', 'ks-telegram') => $username,
                        __('Reason', 'ks-telegram') => $user_data instanceof WP_Error ? $user_data->get_error_code() : '',
                    ]
                )
            );
        }

        return $user_data;
    }

    /**
     * Send a user role change notification.
     *
     * @param int          $user_id   User ID.
     * @param string       $role      New role.
     * @param array<mixed> $old_roles Previous roles.
     *
     * @return void
     */
    public function userRoleChanged(int $user_id, string $role, array $old_roles): void {
        $user = get_userdata($user_id);
        if (! $user instanceof WP_User) {
            return;
        }

        if ($this->settings->bool('notify_user_role_change')) {
            $this->sender->send(
                null,
                $this->messages->userEvent(
                    'user',
                    __('User role changed', 'ks-telegram'),
                    $user,
                    [
                        __('Old roles', 'ks-telegram') => implode(', ', array_map('strval', $old_roles)),
                        __('New role', 'ks-telegram')  => $role,
                    ]
                )
            );
        }

        if ($this->settings->bool('notify_new_admin') && 'administrator' === $role && ! in_array('administrator', $old_roles, true)) {
            $this->sender->send(null, $this->messages->userEvent('user', __('User became an administrator', 'ks-telegram'), $user));
        }
    }

    /**
     * Send a password reset request notification.
     *
     * @param string $user_login Requested login.
     *
     * @return void
     */
    public function passwordResetRequested(string $user_login): void {
        $user = get_user_by('login', $user_login);
        $user = $user instanceof WP_User ? $user : get_user_by('email', $user_login);

        if ($user instanceof WP_User) {
            $this->sender->send(null, $this->messages->userEvent('password_reset', __('Password reset requested', 'ks-telegram'), $user));
        }
    }

    /**
     * Send a completed password reset notification.
     *
     * @param WP_User $user     User object.
     * @param string  $new_pass New password. Never sent or stored.
     *
     * @return void
     */
    public function passwordReset(WP_User $user, string $new_pass): void {
        unset($new_pass);

        $this->sender->send(null, $this->messages->userEvent('password_reset', __('Password reset completed', 'ks-telegram'), $user));
    }

    /**
     * Send profile and email change notifications.
     *
     * @param int          $user_id       User ID.
     * @param WP_User      $old_user_data Previous user data.
     * @param array<mixed> $userdata      Submitted user data.
     *
     * @return void
     */
    public function profileUpdated(int $user_id, WP_User $old_user_data, array $userdata): void {
        unset($userdata);

        $user = get_userdata($user_id);
        if (! $user instanceof WP_User) {
            return;
        }

        $email_changed = $old_user_data->user_email !== $user->user_email;

        if ($this->settings->bool('notify_profile_update')) {
            $this->sender->send(
                null,
                $this->messages->userEvent(
                    'user',
                    __('User profile updated', 'ks-telegram'),
                    $user,
                    [__('Email changed', 'ks-telegram') => $email_changed]
                )
            );
        }

        if ($email_changed && $this->settings->bool('notify_user_email_change')) {
            $this->sender->send(
                null,
                $this->messages->userEvent(
                    'user',
                    __('User email changed', 'ks-telegram'),
                    $user,
                    [
                        __('Old email', 'ks-telegram') => sanitize_email($old_user_data->user_email),
                        __('New email', 'ks-telegram') => sanitize_email($user->user_email),
                    ]
                )
            );
        }
    }

    /**
     * Send a media upload notification.
     *
     * @param int $post_id Attachment ID.
     *
     * @return void
     */
    public function attachmentAdded(int $post_id): void {
        $media = get_post($post_id);
        if (! $media instanceof WP_Post) {
            return;
        }

        $bytes      = $this->attachmentSize($media);
        $threshold  = $this->settings->int('large_upload_threshold_mb', 10) * MB_IN_BYTES;
        $large      = $bytes >= $threshold;
        $extra      = [__('Size', 'ks-telegram') => size_format($bytes, 2)];
        $large_text = sprintf(
            /* translators: %d: Large upload threshold in megabytes. */
            __('Threshold: %d MB', 'ks-telegram'),
            $this->settings->int('large_upload_threshold_mb', 10)
        );

        if ($large && $this->settings->bool('notify_media_large_upload')) {
            $this->sender->send(null, $this->messages->mediaEvent('media', __('Large media file uploaded', 'ks-telegram'), $media, $extra + [__('Threshold', 'ks-telegram') => $large_text]));

            return;
        }

        if ($this->settings->bool('notify_media_uploaded')) {
            $this->sender->send(null, $this->messages->mediaEvent('media', __('Media file uploaded', 'ks-telegram'), $media, $extra));
        }
    }

    /**
     * Send a media deletion notification.
     *
     * @param int     $post_id Attachment ID.
     * @param WP_Post $post    Attachment post.
     *
     * @return void
     */
    public function attachmentDeleted(int $post_id, WP_Post $post): void {
        unset($post_id);

        $this->sender->send(null, $this->messages->mediaEvent('media', __('Media file deleted', 'ks-telegram'), $post));
    }

    /**
     * Send an application password creation notification.
     *
     * @param int          $user_id      User ID.
     * @param array<mixed> $item         Application password item.
     * @param string       $new_password Raw generated password. Never sent or stored.
     * @param array<mixed> $args         Creation arguments.
     *
     * @return void
     */
    public function applicationPasswordCreated(int $user_id, array $item, string $new_password, array $args): void {
        unset($new_password, $args);

        $user = get_userdata($user_id);
        if (! $user instanceof WP_User) {
            return;
        }

        $this->sender->send(
            null,
            $this->messages->userEvent(
                'application_password',
                __('Application password created', 'ks-telegram'),
                $user,
                [__('Name', 'ks-telegram') => sanitize_text_field((string) ($item['name'] ?? ''))]
            )
        );
    }

    /**
     * Send an application password deletion notification.
     *
     * @param int          $user_id User ID.
     * @param array<mixed> $item    Application password item.
     *
     * @return void
     */
    public function applicationPasswordDeleted(int $user_id, array $item): void {
        $user = get_userdata($user_id);
        if (! $user instanceof WP_User) {
            return;
        }

        $this->sender->send(
            null,
            $this->messages->userEvent(
                'application_password',
                __('Application password deleted', 'ks-telegram'),
                $user,
                [__('Name', 'ks-telegram') => sanitize_text_field((string) ($item['name'] ?? ''))]
            )
        );
    }

    /**
     * Send a watched option change notification.
     *
     * @param string $option    Option name.
     * @param mixed  $old_value Old value.
     * @param mixed  $value     New value.
     *
     * @return void
     */
    public function optionUpdated(string $option, mixed $old_value, mixed $value): void {
        $labels = $this->watchedOptionLabels();
        if (! isset($labels[$option])) {
            return;
        }

        $this->sender->send(
            null,
            $this->messages->event(
                'system',
                __('Important option changed', 'ks-telegram'),
                [
                    __('Option', 'ks-telegram')    => $labels[$option],
                    __('Old value', 'ks-telegram') => $this->optionValueSummary($old_value),
                    __('New value', 'ks-telegram') => $this->optionValueSummary($value),
                    __('Actor', 'ks-telegram')     => $this->currentActor(),
                ]
            )
        );
    }

    /**
     * Send a plugin activation notification.
     *
     * @param string $plugin       Plugin basename.
     * @param bool   $network_wide Whether activated network-wide.
     *
     * @return void
     */
    public function pluginActivated(string $plugin, bool $network_wide): void {
        $this->sender->send(
            null,
            $this->messages->event(
                'system',
                __('Plugin activated', 'ks-telegram'),
                [
                    __('Plugin', 'ks-telegram') => $this->pluginName($plugin),
                    __('Network wide', 'ks-telegram') => $network_wide,
                    __('Actor', 'ks-telegram')  => $this->currentActor(),
                ]
            )
        );
    }

    /**
     * Send a plugin deactivation notification.
     *
     * @param string $plugin                Plugin basename.
     * @param bool   $network_deactivating  Whether deactivated network-wide.
     *
     * @return void
     */
    public function pluginDeactivated(string $plugin, bool $network_deactivating): void {
        $this->sender->send(
            null,
            $this->messages->event(
                'system',
                __('Plugin deactivated', 'ks-telegram'),
                [
                    __('Plugin', 'ks-telegram') => $this->pluginName($plugin),
                    __('Network wide', 'ks-telegram') => $network_deactivating,
                    __('Actor', 'ks-telegram')  => $this->currentActor(),
                ]
            )
        );
    }

    /**
     * Send a recovery mode notification without leaking recovery tokens.
     *
     * @param array<mixed> $email Recovery email payload.
     * @param string       $url   Recovery mode URL. Never sent or stored.
     *
     * @return array<mixed>
     */
    public function recoveryModeEmail(array $email, string $url): array {
        unset($url);

        $this->sender->send(
            null,
            $this->messages->event(
                'recovery_mode',
                __('Critical site error detected', 'ks-telegram'),
                [__('Email subject', 'ks-telegram') => sanitize_text_field((string) ($email['subject'] ?? ''))],
                false
            )
        );

        return $email;
    }

    /**
     * Send a theme switch notification.
     *
     * @param string $new_name  New theme name.
     * @param mixed  $new_theme New theme object.
     * @param mixed  $old_theme Old theme object.
     *
     * @return void
     */
    public function themeSwitched(string $new_name, mixed $new_theme, mixed $old_theme): void {
        $this->sender->send(
            null,
            $this->messages->event(
                'system',
                __('Theme switched', 'ks-telegram'),
                [
                    __('New theme', 'ks-telegram') => $this->themeName($new_theme, $new_name),
                    __('Old theme', 'ks-telegram') => $this->themeName($old_theme),
                    __('Actor', 'ks-telegram')     => $this->currentActor(),
                ]
            )
        );
    }

    /**
     * Send a Site Health summary after WordPress scheduled checks.
     *
     * @return void
     */
    public function siteHealthChecked(): void {
        $status = get_transient('health-check-site-status-result');
        $status = is_string($status) ? json_decode($status, true) : [];
        $status = is_array($status) ? $status : [];

        $critical    = absint($status['critical'] ?? 0);
        $recommended = absint($status['recommended'] ?? 0);
        if (0 === $critical && 0 === $recommended) {
            return;
        }

        $this->sender->send(
            null,
            $this->messages->event(
                'system',
                __('Site Health issues detected', 'ks-telegram'),
                [
                    __('Critical issues', 'ks-telegram')    => $critical,
                    __('Recommended improvements', 'ks-telegram') => $recommended,
                    __('Passed tests', 'ks-telegram')        => absint($status['good'] ?? 0),
                ],
                false
            )
        );
    }

    /**
     * Send a manual update notification.
     *
     * @param mixed        $upgrader   Upgrader instance.
     * @param array<mixed> $hook_extra Update context.
     *
     * @return void
     */
    public function upgraderProcessComplete(mixed $upgrader, array $hook_extra): void {
        unset($upgrader);

        $action = sanitize_key((string) ($hook_extra['action'] ?? ''));
        if ('update' !== $action) {
            return;
        }

        $this->sender->send(
            null,
            $this->messages->event(
                'system',
                __('WordPress update completed', 'ks-telegram'),
                [
                    __('Type', 'ks-telegram')    => sanitize_key((string) ($hook_extra['type'] ?? 'unknown')),
                    __('Targets', 'ks-telegram') => $this->updateTargets($hook_extra),
                    __('Actor', 'ks-telegram')   => $this->currentActor(),
                ]
            )
        );
    }

    /**
     * Send an automatic update summary notification.
     *
     * @param array<mixed> $results Automatic update results.
     *
     * @return void
     */
    public function automaticUpdatesComplete(array $results): void {
        $counts = [];
        foreach ($results as $type => $items) {
            $counts[] = sanitize_key((string) $type) . ': ' . (is_countable($items) ? count($items) : 1);
        }

        $this->sender->send(
            null,
            $this->messages->event(
                'system',
                __('Automatic updates completed', 'ks-telegram'),
                [__('Results', 'ks-telegram') => implode(', ', $counts)],
                false
            )
        );
    }

    /**
     * Send a failed WordPress email notification.
     *
     * @param WP_Error $error Mail error.
     *
     * @return void
     */
    public function mailFailed(WP_Error $error): void {
        $this->sender->send(
            null,
            $this->messages->event(
                'wp_mail_failed',
                __('WordPress email failed', 'ks-telegram'),
                [
                    __('Error', 'ks-telegram') => $error->get_error_message(),
                    __('Code', 'ks-telegram')  => $error->get_error_code(),
                ]
            )
        );
    }

    /**
     * Schedule the missed publication checker.
     *
     * @return void
     */
    private function ensureMissedScheduleCron(): void {
        if (! wp_next_scheduled(self::MISSED_SCHEDULE_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::MISSED_SCHEDULE_HOOK);
        }
    }

    /**
     * Check whether any given setting is enabled.
     *
     * @param array<int,string> $keys Setting keys.
     *
     * @return bool
     */
    private function hasAny(array $keys): bool {
        foreach ($keys as $key) {
            if ($this->settings->bool($key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Send a post status message with inline edit action when possible.
     *
     * @param string       $new_status New status.
     * @param string       $old_status Old status.
     * @param WP_Post      $post       Post object.
     * @param string|null  $title      Optional custom title.
     *
     * @return void
     */
    private function sendPostStatusMessage(string $new_status, string $old_status, WP_Post $post, ?string $title = null): void {
        $args = [];
        $link = get_edit_post_link($post->ID, 'raw');
        if (is_string($link) && '' !== $link) {
            $args['reply_markup'] = [
                'inline_keyboard' => [
                    [
                        [
                            'text' => __('Edit post', 'ks-telegram'),
                            'url'  => $link,
                        ],
                    ],
                ],
            ];
        }

        $message = null === $title
            ? $this->messages->postStatus($new_status, $old_status, $post)
            : $this->messages->postEvent(
                'content',
                $title,
                $post,
                [__('Status change', 'ks-telegram') => $old_status . ' -> ' . $new_status]
            );

        $this->sender->send(null, $message, $args);
    }

    /**
     * Check whether a post can be included in content notifications.
     *
     * @param WP_Post $post Post object.
     *
     * @return bool
     */
    private function canNotifyPost(WP_Post $post): bool {
        if ('auto-draft' === $post->post_status || wp_is_post_revision($post) || wp_is_post_autosave($post)) {
            return false;
        }

        return in_array($post->post_type, $this->postTypes(), true);
    }

    /**
     * Get post types included in content notifications.
     *
     * @return array<int,string>
     */
    private function postTypes(): array {
        $post_types = (array) apply_filters('ks_telegram_post_notification_post_types', ['post', 'page', 'product']);

        return array_values(array_filter(array_map('sanitize_key', $post_types)));
    }

    /**
     * Get a readable changed post field label.
     *
     * @param string $field Post field.
     *
     * @return string
     */
    private function postFieldLabel(string $field): string {
        return match ($field) {
            'post_content' => __('Content', 'ks-telegram'),
            'post_excerpt' => __('Excerpt', 'ks-telegram'),
            'post_name' => __('Slug', 'ks-telegram'),
            'post_title' => __('Title', 'ks-telegram'),
            default => $field,
        };
    }

    /**
     * Get an attachment file size in bytes.
     *
     * @param WP_Post $media Attachment post.
     *
     * @return int
     */
    private function attachmentSize(WP_Post $media): int {
        $file = get_attached_file($media->ID);

        return is_string($file) && is_readable($file) ? (int) filesize($file) : 0;
    }

    /**
     * Get watched WordPress option labels.
     *
     * @return array<string,string>
     */
    private function watchedOptionLabels(): array {
        $labels = [
            'admin_email'            => 'admin_email',
            'blog_public'            => 'blog_public',
            'category_base'          => 'category_base',
            'comment_moderation'     => 'comment_moderation',
            'comment_registration'   => 'comment_registration',
            'comments_notify'        => 'comments_notify',
            'date_format'            => 'date_format',
            'default_comment_status' => 'default_comment_status',
            'default_role'           => 'default_role',
            'home'                   => 'home',
            'moderation_notify'      => 'moderation_notify',
            'new_admin_email'        => 'new_admin_email',
            'permalink_structure'    => 'permalink_structure',
            'siteurl'                => 'siteurl',
            'tag_base'               => 'tag_base',
            'time_format'            => 'time_format',
            'timezone_string'        => 'timezone_string',
            'users_can_register'     => 'users_can_register',
            'WPLANG'                 => 'WPLANG',
        ];

        $labels = (array) apply_filters('ks_telegram_watched_option_labels', $labels);

        return array_filter($labels, 'is_string');
    }

    /**
     * Summarize an option value without dumping large structures.
     *
     * @param mixed $value Raw value.
     *
     * @return string
     */
    private function optionValueSummary(mixed $value): string {
        return match (true) {
            is_bool($value) => $value ? __('Yes', 'ks-telegram') : __('No', 'ks-telegram'),
            is_scalar($value) || null === $value => $this->scalarOptionSummary($value),
            is_array($value) => sprintf(
                /* translators: %d: Number of array items. */
                __('Array with %d items', 'ks-telegram'),
                count($value)
            ),
            is_object($value) => get_class($value),
            default => gettype($value),
        };
    }

    /**
     * Summarize a scalar option value.
     *
     * @param bool|int|float|string|null $value Raw scalar value.
     *
     * @return string
     */
    private function scalarOptionSummary(bool|int|float|string|null $value): string {
        $summary = sanitize_text_field((string) $value);

        return strlen($summary) > 160 ? substr($summary, 0, 157) . '...' : $summary;
    }

    /**
     * Get the current admin actor when available.
     *
     * @return string
     */
    private function currentActor(): string {
        $user = wp_get_current_user();

        return $user->exists() ? sprintf('%s (#%d)', $user->user_login, $user->ID) : '';
    }

    /**
     * Get a readable plugin name from its basename.
     *
     * @param string $plugin Plugin basename.
     *
     * @return string
     */
    private function pluginName(string $plugin): string {
        if (! function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $file = WP_PLUGIN_DIR . '/' . $plugin;
        if (! is_readable($file)) {
            return $plugin;
        }

        $data = get_plugin_data($file, false, false);

        return sanitize_text_field((string) ($data['Name'] ?? $plugin)) ?: $plugin;
    }

    /**
     * Get a readable theme name.
     *
     * @param mixed  $theme    Theme object.
     * @param string $fallback Fallback name.
     *
     * @return string
     */
    private function themeName(mixed $theme, string $fallback = ''): string {
        if (is_object($theme) && method_exists($theme, 'get')) {
            $name = (string) $theme->get('Name');

            return '' !== $name ? $name : $fallback;
        }

        return '' !== $fallback ? $fallback : sanitize_text_field((string) $theme);
    }

    /**
     * Extract readable update targets.
     *
     * @param array<mixed> $hook_extra Updater context.
     *
     * @return string
     */
    private function updateTargets(array $hook_extra): string {
        $targets = [];

        foreach ((array) ($hook_extra['plugins'] ?? []) as $plugin) {
            $targets[] = $this->pluginName((string) $plugin);
        }

        if (! empty($hook_extra['plugin'])) {
            $targets[] = $this->pluginName((string) $hook_extra['plugin']);
        }

        foreach ((array) ($hook_extra['themes'] ?? []) as $theme) {
            $targets[] = sanitize_text_field((string) $theme);
        }

        if (! empty($hook_extra['theme'])) {
            $targets[] = sanitize_text_field((string) $hook_extra['theme']);
        }

        return implode(', ', array_unique(array_filter($targets))) ?: sanitize_key((string) ($hook_extra['type'] ?? 'core'));
    }

}
