<?php
/**
 * Telegram avatar importer.
 *
 * This file downloads the largest available Telegram profile photo through
 * Bot API and stores it as a WordPress media attachment for the user.
 *
 * @author  Konstantin Sorokin
 * @link    https://konstantinsorokin.com
 * @package KS_Telegram
 */

declare(strict_types=1);

namespace KonstantinSorokin\Telegram\Auth;

use TelegramBot\Api\Types\PhotoSize;
use KonstantinSorokin\Telegram\Bot\BotApiManager;
use KonstantinSorokin\Telegram\Settings\SettingsRepository;
use Throwable;
use WP_Error;

defined('ABSPATH') || exit;

/**
 * Imports Telegram profile photos into the WordPress media library.
 */
final readonly class TelegramAvatarImporter {

    public const string ATTACHMENT_META = 'ks_telegram_avatar_attachment_id';
    public const string FILE_UNIQUE_ID_META = 'ks_telegram_avatar_file_unique_id';
    public const string IMPORTED_AT_META = 'ks_telegram_avatar_imported_at';

    private const int MAX_FILE_SIZE = 20 * MB_IN_BYTES;

    public function __construct(
        private SettingsRepository $settings,
        private BotApiManager $bot_api
    ) {}

    /**
     * Import the latest Telegram profile photo for a user.
     *
     * @param int    $user_id     WordPress user ID.
     * @param string $telegram_id Telegram user ID.
     *
     * @return int|WP_Error|null Attachment ID, error, or null when disabled/unavailable.
     */
    public function import(int $user_id, string $telegram_id, string $photo_url = ''): int|WP_Error|null {
        if (! $this->settings->bool('telegram_login_upload_avatar')) {
            return null;
        }

        if ($user_id <= 0) {
            return null;
        }

        /*
         * The picture claim first: a login gives the OIDC sub, which is not a Bot
         * API id and saturates to PHP_INT_MAX when cast, so getUserProfilePhotos
         * was asked about user 9223372036854775807 and answered nothing, silently.
         */
        $from_url = $this->importFromUrl($user_id, $telegram_id, $photo_url);
        if (null !== $from_url) {
            return $from_url;
        }

        /*
         * Bot API stays for a genuine numeric id; the guard now requires the value
         * to survive the round trip through int.
         */
        if ('' === $telegram_id || ! ctype_digit($telegram_id) || (string) (int) $telegram_id !== $telegram_id) {
            return null;
        }

        $bot = $this->bot_api->api();
        if (is_wp_error($bot)) {
            return $bot;
        }

        try {
            $photos = $bot->getUserProfilePhotos((int) $telegram_id, 0, 1)->getPhotos();
            $photo  = $this->largestPhoto($photos[0] ?? []);

            if (! $photo instanceof PhotoSize) {
                return null;
            }

            $file_unique_id = $photo->getFileUniqueId();
            $existing_id    = absint(get_user_meta($user_id, self::ATTACHMENT_META, true));

            if ($this->isCurrentAvatar($user_id, $existing_id, $file_unique_id)) {
                return $existing_id;
            }

            $file     = $bot->getFile($photo->getFileId());
            $file_path = (string) $file->getFilePath();

            if ('' === $file_path) {
                return new WP_Error('ks_telegram_avatar_missing_file_path', __('Telegram avatar file path is missing.', 'ks-telegram'));
            }

            $contents = $bot->downloadFile($photo->getFileId());

            return $this->storeAttachment($user_id, $telegram_id, $file_unique_id, $file_path, $contents, $existing_id);
        } catch (Throwable $exception) {
            $message = sanitize_text_field($exception->getMessage());

            return new WP_Error(
                'ks_telegram_avatar_import_failed',
                '' !== $message ? $message : __('Telegram avatar import failed.', 'ks-telegram'),
                ['status' => 502]
            );
        }
    }

    /**
     * Import the avatar from the OIDC `picture` claim.
     *
     * Null means "no usable URL" — the caller then tries Bot API. The URL comes
     * from a verified token but is checked again here, because this is the
     * method that makes the site fetch it: HTTPS, Telegram hosts, size capped,
     * and through wp_remote_get so the site's HTTP filters see it.
     *
     * @return int|WP_Error|null
     */
    private function importFromUrl(int $user_id, string $telegram_id, string $photo_url): int|WP_Error|null {
        $photo_url = trim($photo_url);

        if ('' === $photo_url || ! $this->isTelegramImageUrl($photo_url)) {
            return null;
        }

        /*
         * The userpic URL embeds a hash of the image, so it stands in for Bot API's
         * file_unique_id in the dedupe check below.
         */
        $file_unique_id = 'url' . substr(sha1($photo_url), 0, 24);
        $existing_id    = absint(get_user_meta($user_id, self::ATTACHMENT_META, true));

        if ($this->isCurrentAvatar($user_id, $existing_id, $file_unique_id)) {
            return $existing_id;
        }

        $response = wp_remote_get(
            $photo_url,
            [
                'timeout'    => 15,
                'redirection' => 3,
                'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url('/'),
            ]
        );

        if (is_wp_error($response)) {
            return $response;
        }

        if (200 !== (int) wp_remote_retrieve_response_code($response)) {
            return new WP_Error(
                'ks_telegram_avatar_http_error',
                sprintf(
                    /* translators: %d: HTTP status code. */
                    __('Telegram returned HTTP %d for the profile photo.', 'ks-telegram'),
                    (int) wp_remote_retrieve_response_code($response)
                )
            );
        }

        $contents = (string) wp_remote_retrieve_body($response);

        if ('' === $contents) {
            return new WP_Error('ks_telegram_avatar_empty_body', __('Telegram profile photo download was empty.', 'ks-telegram'));
        }

        return $this->storeAttachment(
            $user_id,
            $telegram_id,
            $file_unique_id,
            (string) wp_parse_url($photo_url, PHP_URL_PATH),
            $contents,
            $existing_id
        );
    }

    /**
     * Check that a URL is an HTTPS image on a host Telegram actually serves.
     *
     * @param string $url Candidate URL.
     *
     * @return bool
     */
    private function isTelegramImageUrl(string $url): bool {
        $parts = wp_parse_url($url);

        if (! is_array($parts) || 'https' !== ($parts['scheme'] ?? '') || '' === ($parts['host'] ?? '')) {
            return false;
        }

        $host = strtolower((string) $parts['host']);

        foreach (['t.me', 'telegram.org', 'telegram-cdn.org'] as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pick the largest photo size returned by Telegram.
     *
     * @param array<int,mixed> $photos Photo sizes for one profile photo.
     *
     * @return PhotoSize|null
     */
    private function largestPhoto(array $photos): ?PhotoSize {
        $largest = null;
        $score   = 0;

        foreach ($photos as $photo) {
            if (! $photo instanceof PhotoSize) {
                continue;
            }

            $photo_score = max((int) $photo->getFileSize(), (int) $photo->getWidth() * (int) $photo->getHeight());
            if ($photo_score > $score) {
                $largest = $photo;
                $score   = $photo_score;
            }
        }

        return $largest;
    }

    /**
     * Check whether the already imported attachment matches Telegram's file.
     *
     * @param int    $user_id        WordPress user ID.
     * @param int    $attachment_id  Current imported attachment ID.
     * @param string $file_unique_id Telegram file unique ID.
     *
     * @return bool
     */
    private function isCurrentAvatar(int $user_id, int $attachment_id, string $file_unique_id): bool {
        if ($attachment_id <= 0 || '' === $file_unique_id) {
            return false;
        }

        $stored_file_id = (string) get_user_meta($user_id, self::FILE_UNIQUE_ID_META, true);

        return hash_equals($stored_file_id, $file_unique_id) && '' !== (string) wp_get_attachment_url($attachment_id);
    }

    /**
     * Store downloaded Telegram avatar bytes as a WordPress attachment.
     *
     * @param int    $user_id        WordPress user ID.
     * @param string $telegram_id    Telegram user ID.
     * @param string $file_unique_id Telegram unique file ID.
     * @param string $file_path      Telegram file path.
     * @param string $contents       Downloaded file bytes.
     * @param int    $old_id         Previous imported attachment ID.
     *
     * @return int|WP_Error
     */
    private function storeAttachment(
        int $user_id,
        string $telegram_id,
        string $file_unique_id,
        string $file_path,
        string $contents,
        int $old_id
    ): int|WP_Error {
        if ('' === $contents || strlen($contents) > self::MAX_FILE_SIZE) {
            return new WP_Error('ks_telegram_avatar_invalid_file_size', __('Telegram avatar file size is invalid.', 'ks-telegram'));
        }

        $this->loadMediaFunctions();

        $extension = strtolower((string) pathinfo($file_path, PATHINFO_EXTENSION));
        $extension = in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true) ? $extension : 'jpg';
        $filename  = 'telegram-avatar-' . sanitize_key($telegram_id) . '-' . sanitize_key($file_unique_id) . '.' . $extension;
        $upload    = wp_upload_bits($filename, null, $contents);

        if (! empty($upload['error']) || empty($upload['file'])) {
            return new WP_Error(
                'ks_telegram_avatar_upload_failed',
                sanitize_text_field((string) ($upload['error'] ?? __('Telegram avatar upload failed.', 'ks-telegram')))
            );
        }

        $file = (string) $upload['file'];
        $mime = wp_get_image_mime($file);

        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            wp_delete_file($file);

            return new WP_Error('ks_telegram_avatar_invalid_mime', __('Telegram avatar is not a supported image.', 'ks-telegram'));
        }

        $attachment_id = wp_insert_attachment(
            [
                'post_mime_type' => $mime,
                'post_title'     => 'Telegram avatar ' . sanitize_text_field($telegram_id),
                'post_content'   => '',
                'post_status'    => 'inherit',
            ],
            $file
        );

        // 0 means wp_insert_post refused; treating it as success deleted the
        // previous avatar and stored an attachment id of nothing.
        if (is_wp_error($attachment_id) || (int) $attachment_id <= 0) {
            wp_delete_file($file);

            return is_wp_error($attachment_id)
                ? $attachment_id
                : new WP_Error('ks_telegram_avatar_insert_failed', __('Telegram avatar could not be stored.', 'ks-telegram'));
        }

        $metadata = wp_generate_attachment_metadata((int) $attachment_id, $file);
        if (is_array($metadata)) {
            wp_update_attachment_metadata((int) $attachment_id, $metadata);
        }

        update_user_meta($user_id, self::ATTACHMENT_META, (int) $attachment_id);
        update_user_meta($user_id, self::FILE_UNIQUE_ID_META, $file_unique_id);
        update_user_meta($user_id, self::IMPORTED_AT_META, time());

        if ($old_id > 0 && $old_id !== (int) $attachment_id) {
            wp_delete_attachment($old_id, true);
        }

        return (int) $attachment_id;
    }

    /**
     * Load WordPress media functions used outside wp-admin.
     *
     * @return void
     */
    private function loadMediaFunctions(): void {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
    }
}
