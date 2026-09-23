<?php
/**
 * Publish newly public content to a configured Telegram channel.
 *
 * @package KS_Telegram
 */

declare(strict_types=1);

namespace KonstantinSorokin\Telegram\Publishing;

use KonstantinSorokin\Telegram\Bot\MessageSender;
use KonstantinSorokin\Telegram\Bot\TelegramApiClient;
use KonstantinSorokin\Telegram\Settings\SettingsRepository;
use WP_Post;

defined('ABSPATH') || exit;

/**
 * Keeps public channel posts separate from private administrator alerts.
 */
final readonly class ChannelPublisher {

    private const string CRON_HOOK = 'ks_telegram_publish_to_channel';

    private const int MAX_ATTEMPTS = 3;

    public function __construct(
        private SettingsRepository $settings,
        private MessageSender $sender,
        private TelegramApiClient $client
    ) {}

    /**
     * Whether the KS News post type is available from the actual plugin.
     */
    public static function newsAvailable(): bool {
        return defined('KS_NEWS_FILE')
            && defined('KS_NEWS_POST_TYPE')
            && post_type_exists((string) KS_NEWS_POST_TYPE);
    }

    /**
     * Find custom post types that can be opened publicly and edited in admin.
     *
     * @return array<string,\WP_Post_Type>
     */
    public static function availableCustomPostTypes(): array {
        $types = get_post_types(
            [
                '_builtin'           => false,
                'public'             => true,
                'publicly_queryable' => true,
                'show_ui'            => true,
            ],
            'objects'
        );

        uasort($types, static fn($left, $right): int => strnatcasecmp((string) $left->label, (string) $right->label));
        return $types;
    }

    /**
     * Register publication and background delivery hooks.
     */
    public function boot(): void {
        add_action('transition_post_status', [$this, 'postStatusChanged'], 10, 3);
        add_action(self::CRON_HOOK, [$this, 'deliver'], 10, 3);
    }

    /**
     * Queue only a real transition into the published state.
     */
    public function postStatusChanged(string $new_status, string $old_status, WP_Post $post): void {
        if ('publish' !== $new_status || 'publish' === $old_status || ! $this->canPublish($post)) {
            return;
        }

        $chat_id = $this->channelId();
        if ('' === $chat_id || '' === $this->settings->botToken()) {
            return;
        }

        $this->schedule($post->ID, $chat_id, 0, 5);
    }

    /**
     * Send one queued publication, with bounded retries for failed delivery.
     */
    public function deliver(int $post_id, string $chat_id, int $attempt): void {
        if ($attempt < 0 || $attempt >= self::MAX_ATTEMPTS || $chat_id !== $this->settings->channelId() || '' === $this->settings->botToken()) {
            return;
        }

        $post = get_post($post_id);
        if (! $post instanceof WP_Post || 'publish' !== $post->post_status || ! $this->canPublish($post)) {
            return;
        }

        $sent_key = $this->sentKey($chat_id);
        if (get_post_meta($post_id, $sent_key, true)) {
            return;
        }

        $permalink = get_permalink($post);
        if (! is_string($permalink) || ! $this->isSiteUrl($permalink)) {
            return;
        }

        $lock_key = 'ks_telegram_channel_lock_' . $post_id . '_' . md5($chat_id);
        $locked_at = (int) get_option($lock_key, 0);
        if ($locked_at > 0 && $locked_at < time() - 300) {
            delete_option($lock_key);
        }

        if (! add_option($lock_key, time(), '', false)) {
            return;
        }

        try {
            // A second worker may have completed while this one acquired the lock.
            if (get_post_meta($post_id, $sent_key, true)) {
                return;
            }

            $format = $this->settings->channelFormat($post->post_type);
            [$message, $visible_text] = $this->message($post, $permalink, $format);
            $photo = $format['image'] ? get_the_post_thumbnail_url($post, 'large') : false;
            $photo = is_string($photo) && $this->isSiteUrl($photo) ? $photo : '';

            // Telegram photo captions have a 1024-character limit. One API
            // request per publication avoids partial sends and duplicate text.
            if ('' !== $photo && mb_strlen($visible_text) <= 1024) {
                $args = [
                    'parse_mode'           => 'HTML',
                    'disable_notification' => $this->settings->bool('channel_silent_publish'),
                ];
                $result = $this->client->sendPhoto($chat_id, $photo, $message, $args);
                do_action('ks_telegram_message_sent', $chat_id, $message, $args, $result);
                $sent = ! is_wp_error($result);
            } else {
                if ('' === $message) {
                    $title = $this->plainText(get_the_title($post), 300);
                    $message = '<a href="' . esc_url($permalink) . '">' . esc_html('' !== $title ? $title : $permalink) . '</a>';
                }

                $args = [
                    'parse_mode'               => 'HTML',
                    'disable_web_page_preview' => $format['disable_preview'],
                    'disable_notification'      => $this->settings->bool('channel_silent_publish'),
                ];
                if ($format['disable_preview']) {
                    $args['link_preview_options'] = ['is_disabled' => true];
                }

                $sent = $this->sender->send($chat_id, $message, $args);
            }

            if ($sent) {
                update_post_meta($post_id, $sent_key, time());
                delete_post_meta($post_id, $this->failedKey($chat_id));
                return;
            }

            $next_attempt = $attempt + 1;
            if ($next_attempt < self::MAX_ATTEMPTS) {
                $delay = 0 === $attempt ? 60 : 300;
                if ($this->schedule($post_id, $chat_id, $next_attempt, $delay)) {
                    return;
                }
            }

            update_post_meta($post_id, $this->failedKey($chat_id), time());
        } finally {
            delete_option($lock_key);
        }
    }

    /**
     * Compose one HTML caption or text message and its visible character count.
     *
     * @param array<string,bool> $format Per-type format choices.
     * @return array{string,string}
     */
    private function message(WP_Post $post, string $permalink, array $format): array {
        $parts = [];
        $visible = [];
        $link = esc_url($permalink);

        if ($format['title']) {
            $title = $this->plainText(get_the_title($post), 300);
            if ('' !== $title) {
                $parts[] = $format['linked_title'] ? '<a href="' . $link . '">' . esc_html($title) . '</a>' : esc_html($title);
                $visible[] = $title;
            }
        }

        if ($format['description']) {
            $description = $this->plainText(get_the_excerpt($post), 160);
            if ('' !== $description) {
                $parts[] = esc_html($description);
                $visible[] = $description;
            }
        }

        if ($format['permalink']) {
            $parts[] = '<a href="' . $link . '">' . esc_html($permalink) . '</a>';
            $visible[] = $permalink;
        }

        return [implode("\n\n", $parts), implode("\n\n", $visible)];
    }

    /**
     * Strip formatting and bound visible text, including the ellipsis.
     */
    private function plainText(string $value, int $limit): string {
        $value = trim(wp_strip_all_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit - 1) . '…' : $value;
    }

    /**
     * Only public, unprotected content from explicitly enabled types is eligible.
     */
    private function canPublish(WP_Post $post): bool {
        if ($post->ID <= 0 || '' !== $post->post_password || wp_is_post_revision($post->ID) || wp_is_post_autosave($post->ID)) {
            return false;
        }

        return match ($post->post_type) {
            'post' => $this->settings->bool('channel_publish_posts'),
            'page' => $this->settings->bool('channel_publish_pages'),
            default => in_array($post->post_type, $this->settings->selectedCustomPostTypes(), true),
        };
    }

    /**
     * Return the single configured destination, never the default alert chats.
     */
    private function channelId(): string {
        return $this->settings->channelId();
    }

    /**
     * Keep outgoing links on this site, not on a URL inserted by a permalink filter.
     */
    private function isSiteUrl(string $url): bool {
        $site_url = home_url('/');
        $scheme   = wp_parse_url($url, PHP_URL_SCHEME);
        $host     = wp_parse_url($url, PHP_URL_HOST);
        return in_array($scheme, ['http', 'https'], true)
            && $scheme === wp_parse_url($site_url, PHP_URL_SCHEME)
            && is_string($host)
            && strtolower($host) === strtolower((string) wp_parse_url($site_url, PHP_URL_HOST))
            && wp_parse_url($url, PHP_URL_PORT) === wp_parse_url($site_url, PHP_URL_PORT)
            && null === wp_parse_url($url, PHP_URL_USER)
            && null === wp_parse_url($url, PHP_URL_PASS);
    }

    /**
     * Queue one attempt. WordPress will run it on its next cron invocation.
     */
    private function schedule(int $post_id, string $chat_id, int $attempt, int $delay): bool {
        $args = [$post_id, $chat_id, $attempt];
        if (wp_next_scheduled(self::CRON_HOOK, $args)) {
            return true;
        }

        return true === wp_schedule_single_event(time() + $delay, self::CRON_HOOK, $args);
    }

    private function sentKey(string $chat_id): string {
        return '_ks_telegram_channel_sent_' . md5($chat_id);
    }

    private function failedKey(string $chat_id): string {
        return '_ks_telegram_channel_failed_' . md5($chat_id);
    }
}
