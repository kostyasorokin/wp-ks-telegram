<?php
/**
 * WordPress avatar integration.
 *
 * This file lets WordPress use Telegram avatar attachments imported for users
 * instead of falling back to the default external avatar provider.
 *
 * @author  Konstantin Sorokin
 * @link    https://konstantinsorokin.com
 * @package KS_Telegram
 */

declare(strict_types=1);

namespace KonstantinSorokin\Telegram\Auth;

use KonstantinSorokin\Telegram\Settings\SettingsRepository;
use WP_Comment;
use WP_Post;
use WP_User;

defined('ABSPATH') || exit;

/**
 * Serves imported Telegram images through the WordPress avatar API.
 */
final readonly class UserAvatarController {

    public function __construct(private SettingsRepository $settings) {}

    /**
     * Register avatar filters.
     *
     * @return void
     */
    public function boot(): void {
        add_filter('get_avatar_data', [$this, 'filterAvatarData'], 10, 2);
    }

    /**
     * Replace avatar URL with the imported Telegram attachment when available.
     *
     * @param array<string,mixed> $args        Avatar data.
     * @param mixed               $id_or_email User identifier accepted by get_avatar().
     *
     * @return array<string,mixed>
     */
    public function filterAvatarData(array $args, mixed $id_or_email): array {
        if (! $this->settings->bool('telegram_login_upload_avatar')) {
            return $args;
        }

        $user = $this->userFromAvatarInput($id_or_email);
        if (! $user instanceof WP_User) {
            return $args;
        }

        $attachment_id = absint(get_user_meta($user->ID, TelegramAvatarImporter::ATTACHMENT_META, true));
        if ($attachment_id <= 0) {
            return $args;
        }

        $size  = max(1, absint($args['size'] ?? 96));
        $image = wp_get_attachment_image_src($attachment_id, [$size, $size]);
        $url   = is_array($image) ? (string) ($image[0] ?? '') : (string) wp_get_attachment_url($attachment_id);

        if ('' === $url) {
            return $args;
        }

        $args['url']          = esc_url_raw($url);
        $args['found_avatar'] = true;

        if (is_array($image)) {
            $args['width']  = absint($image[1] ?? $size);
            $args['height'] = absint($image[2] ?? $size);
        }

        return $args;
    }

    /**
     * Resolve a WordPress user from a get_avatar() input value.
     *
     * @param mixed $id_or_email User identifier.
     *
     * @return WP_User|null
     */
    private function userFromAvatarInput(mixed $id_or_email): ?WP_User {
        $user = match (true) {
            $id_or_email instanceof WP_User => $id_or_email,
            $id_or_email instanceof WP_Post => get_user_by('id', (int) $id_or_email->post_author),
            $id_or_email instanceof WP_Comment => get_user_by('id', (int) $id_or_email->user_id),
            is_numeric($id_or_email) => get_user_by('id', (int) $id_or_email),
            is_string($id_or_email) && is_email($id_or_email) => get_user_by('email', $id_or_email),
            default => null,
        };

        return $user instanceof WP_User ? $user : null;
    }
}
