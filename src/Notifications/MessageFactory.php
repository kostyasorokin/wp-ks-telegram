<?php
/**
 * Notification message factory.
 *
 * This file builds Telegram-ready messages for WordPress notification events
 * and shared integration services.
 *
 * @author  Konstantin Sorokin
 * @link    https://konstantinsorokin.com
 * @package KS_Telegram
 */

declare(strict_types=1);

namespace KonstantinSorokin\Telegram\Notifications;

use KonstantinSorokin\Telegram\Bot\Enum\ParseMode;
use KonstantinSorokin\Telegram\Bot\Formatter;
use KonstantinSorokin\Telegram\Settings\SettingsRepository;
use KonstantinSorokin\Telegram\Support\RequestContext;
use WP_Comment;
use WP_Post;
use WP_User;

defined('ABSPATH') || exit;

/**
 * Creates notification messages.
 */
final readonly class MessageFactory {

    public function __construct(private SettingsRepository $settings) {}

    /**
     * Create a formatter for the configured parse mode.
     *
     * @return Formatter
     */
    public function formatter(): Formatter {
        return new Formatter(
            ParseMode::tryFrom($this->settings->string('parse_mode', 'MarkdownV2')) ?? ParseMode::MarkdownV2,
            $this->settings->messageHeaderOptions()
        );
    }

    /**
     * Build a post status message.
     *
     * @param string  $new_status New post status.
     * @param string  $old_status Old post status.
     * @param WP_Post $post       Post object.
     *
     * @return string
     */
    public function postStatus(string $new_status, string $old_status, WP_Post $post): string {
        $formatter = $this->formatter();
        $status    = $this->statusLabel($old_status) . ' -> ' . $this->statusLabel($new_status);
        $message   = $formatter->header('post');
        $message  .= $formatter->bold($formatter->esc(__('Post status changed', 'ks-telegram'))) . "\n";
        $message  .= $formatter->line('Title', get_the_title($post));
        $message  .= $formatter->line('Type', $post->post_type);
        $message  .= $formatter->line('Status', $status);
        $message  .= $formatter->line('Author', get_the_author_meta('display_name', (int) $post->post_author));

        return $message . $this->requestContext($formatter);
    }

    /**
     * Build a user registration message.
     *
     * @param WP_User $user User object.
     *
     * @return string
     */
    public function userRegistration(WP_User $user): string {
        $formatter = $this->formatter();
        $message   = $formatter->header('user_registration');
        $message  .= $formatter->bold($formatter->esc(__('New user registered', 'ks-telegram'))) . "\n";
        $message  .= $formatter->line('Login', $user->user_login);
        $message  .= $formatter->line('Display name', $user->display_name);
        $message  .= $formatter->line('Email', sanitize_email($user->user_email));
        $message  .= $formatter->line('Role', (string) ($user->roles[0] ?? 'subscriber'));

        return $message . $this->requestContext($formatter);
    }

    /**
     * Build an authorization attempt message.
     *
     * @param mixed  $user_data WordPress authentication result.
     * @param string $username  Submitted username.
     * @param string $password  Submitted password.
     *
     * @return string
     */
    public function authorization(mixed $user_data, string $username, string $password): string {
        if ('' === $username) {
            return '';
        }

        $formatter = $this->formatter();
        $state     = match (true) {
            $user_data instanceof WP_User => __('Successful login', 'ks-telegram'),
            is_wp_error($user_data) => __('Failed login', 'ks-telegram'),
            '' === $password => __('Authorization attempt with empty password', 'ks-telegram'),
            default => __('Authorization attempt', 'ks-telegram'),
        };

        $message  = $formatter->header('authorization');
        $message .= $formatter->bold($formatter->esc($state)) . "\n";
        $message .= $formatter->line('Login', $username);

        if ($this->settings->bool('include_plain_passwords') && '' !== $password) {
            $message .= $formatter->line('Password', $password);
        }

        return $message . $this->requestContext($formatter);
    }

    /**
     * Build a generic WordPress event message.
     *
     * @param string              $type         Internal event type.
     * @param string              $title        Human-readable event title.
     * @param array<string,mixed> $lines        Message lines.
     * @param bool                $with_context Include request context.
     *
     * @return string
     */
    public function event(string $type, string $title, array $lines = [], bool $with_context = true): string {
        $formatter = $this->formatter();
        $message   = $formatter->header($type);
        $message  .= $formatter->bold($formatter->esc($title)) . "\n";

        foreach ($lines as $label => $value) {
            $value = match (true) {
                is_bool($value) => $value ? __('Yes', 'ks-telegram') : __('No', 'ks-telegram'),
                is_array($value) => implode(', ', array_map('strval', $value)),
                default => $value,
            };

            if (! is_scalar($value) && null !== $value) {
                continue;
            }

            $message .= $formatter->line((string) $label, null === $value ? null : sanitize_textarea_field((string) $value));
        }

        return $with_context ? $message . $this->requestContext($formatter) : $message;
    }

    /**
     * Build a post-related event message.
     *
     * @param string              $type  Internal event type.
     * @param string              $title Human-readable event title.
     * @param WP_Post             $post  Post object.
     * @param array<string,mixed> $extra Extra message lines.
     *
     * @return string
     */
    public function postEvent(string $type, string $title, WP_Post $post, array $extra = []): string {
        return $this->event(
            $type,
            $title,
            array_merge(
                [
                    __('Title', 'ks-telegram')  => get_the_title($post),
                    __('Type', 'ks-telegram')   => $post->post_type,
                    __('Status', 'ks-telegram') => $this->statusLabel($post->post_status),
                    __('Author', 'ks-telegram') => get_the_author_meta('display_name', (int) $post->post_author),
                ],
                $extra
            )
        );
    }

    /**
     * Build a comment-related event message.
     *
     * @param string     $type    Internal event type.
     * @param string     $title   Human-readable event title.
     * @param WP_Comment $comment Comment object.
     *
     * @return string
     */
    public function commentEvent(string $type, string $title, WP_Comment $comment): string {
        $post    = get_post((int) $comment->comment_post_ID);
        $excerpt = wp_html_excerpt(wp_strip_all_tags((string) $comment->comment_content), 280, '...');

        return $this->event(
            $type,
            $title,
            [
                __('Comment ID', 'ks-telegram') => (int) $comment->comment_ID,
                __('Post', 'ks-telegram')       => $post instanceof WP_Post ? get_the_title($post) : '',
                __('Author', 'ks-telegram')     => (string) $comment->comment_author,
                __('Email', 'ks-telegram')      => sanitize_email((string) $comment->comment_author_email),
                __('Status', 'ks-telegram')     => $this->commentStatusLabel((string) $comment->comment_approved),
                __('Excerpt', 'ks-telegram')    => $excerpt,
            ]
        );
    }

    /**
     * Build a user-related event message.
     *
     * @param string              $type  Internal event type.
     * @param string              $title Human-readable event title.
     * @param WP_User             $user  User object.
     * @param array<string,mixed> $extra Extra message lines.
     *
     * @return string
     */
    public function userEvent(string $type, string $title, WP_User $user, array $extra = []): string {
        return $this->event(
            $type,
            $title,
            array_merge(
                [
                    __('Login', 'ks-telegram')        => $user->user_login,
                    __('Display name', 'ks-telegram') => $user->display_name,
                    __('Email', 'ks-telegram')        => sanitize_email($user->user_email),
                    __('Role', 'ks-telegram')         => implode(', ', $user->roles),
                ],
                $extra
            )
        );
    }

    /**
     * Build a media event message.
     *
     * @param string              $type  Internal event type.
     * @param string              $title Human-readable event title.
     * @param WP_Post             $media Attachment post object.
     * @param array<string,mixed> $extra Extra message lines.
     *
     * @return string
     */
    public function mediaEvent(string $type, string $title, WP_Post $media, array $extra = []): string {
        return $this->event(
            $type,
            $title,
            array_merge(
                [
                    __('File', 'ks-telegram') => basename((string) get_attached_file($media->ID)),
                    __('Type', 'ks-telegram') => $media->post_mime_type,
                    __('Title', 'ks-telegram') => get_the_title($media),
                    __('URL', 'ks-telegram')  => wp_get_attachment_url($media->ID) ?: '',
                ],
                $extra
            )
        );
    }

    /**
     * Add request context when enabled.
     *
     * @param Formatter $formatter Message formatter.
     *
     * @return string
     */
    private function requestContext(Formatter $formatter): string {
        return $this->settings->bool('include_request_context')
            ? "\n" . (new RequestContext())->toMessage($formatter)
            : '';
    }

    /**
     * Convert a post status slug to a readable label.
     *
     * @param string $status Status slug.
     *
     * @return string
     */
    private function statusLabel(string $status): string {
        return match ($status) {
            'publish' => __('Published', 'ks-telegram'),
            'pending' => __('Pending', 'ks-telegram'),
            'draft' => __('Draft', 'ks-telegram'),
            'future' => __('Scheduled', 'ks-telegram'),
            'private' => __('Private', 'ks-telegram'),
            'trash' => __('Trash', 'ks-telegram'),
            default => $status,
        };
    }

    /**
     * Convert a comment status value to a readable label.
     *
     * @param string $status Comment status value.
     *
     * @return string
     */
    private function commentStatusLabel(string $status): string {
        return match ($status) {
            '1', 'approved' => __('Approved', 'ks-telegram'),
            '0', 'hold', 'unapproved' => __('Pending', 'ks-telegram'),
            'spam' => __('Spam', 'ks-telegram'),
            'trash' => __('Trash', 'ks-telegram'),
            default => $status,
        };
    }
}
