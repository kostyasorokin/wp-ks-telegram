<?php
/**
 * Telegram Login profile completion controller.
 *
 * This file renders and processes the public form used to collect contact data
 * that Telegram Login cannot provide directly, such as the user's email.
 *
 * @author  Konstantin Sorokin
 * @link    https://konstantinsorokin.com
 * @package KS_Telegram
 */

declare(strict_types=1);

namespace KonstantinSorokin\Telegram\Auth;

use KonstantinSorokin\Telegram\Settings\SettingsRepository;
use KonstantinSorokin\Telegram\Plugins\WooCommerce\CustomerPhoneSync;
use WP_User;

defined('ABSPATH') || exit;

/**
 * Collects missing profile data after Telegram Login.
 */
final readonly class ProfileCompletionController {

    private const string GENERATED_EMAIL_META = '_ks_telegram_generated_email';

    public function __construct(
        private SettingsRepository $settings,
        private CustomerPhoneSync $phone_sync
    ) {}

    /**
     * Register public profile completion routes.
     *
     * @return void
     */
    public function boot(): void {
        add_action('template_redirect', [$this, 'route'], 1);
    }

    /**
     * Route profile completion requests.
     *
     * @return void
     */
    public function route(): void {
        $route_raw = filter_input(INPUT_GET, 'ks_telegram_login', FILTER_UNSAFE_RAW);
        $route     = is_string($route_raw) ? sanitize_key($route_raw) : '';

        match ($route) {
            'complete_profile' => $this->render(),
            'save_profile'     => $this->save(),
            default            => null,
        };
    }

    /**
     * Build the profile completion URL.
     *
     * @param string $redirect_to Final redirect target after completion.
     *
     * @return string
     */
    public function completionUrl(string $redirect_to): string {
        return add_query_arg(
            [
                'ks_telegram_login' => 'complete_profile',
                'redirect_to'                => $redirect_to,
            ],
            home_url('/')
        );
    }

    /**
     * Mark a user as having a generated placeholder email.
     *
     * @param int $user_id WordPress user ID.
     *
     * @return void
     */
    public function markGeneratedEmail(int $user_id): void {
        update_user_meta($user_id, self::GENERATED_EMAIL_META, '1');
    }

    /**
     * Check whether the user must complete contact data.
     *
     * @param WP_User $user WordPress user.
     *
     * @return bool
     */
    public function needsCompletion(WP_User $user): bool {
        return $this->settings->bool('telegram_login_require_email') && $this->hasGeneratedEmail($user);
    }

    /**
     * Render the profile completion form.
     *
     * @return never
     */
    private function render(): never {
        if (! is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(home_url('/')));
            exit;
        }

        $user        = wp_get_current_user();
        $redirect_to = $this->redirectFromRequest(INPUT_GET);

        if (! $this->needsCompletion($user)) {
            wp_safe_redirect($redirect_to);
            exit;
        }

        $email         = $this->hasGeneratedEmail($user) ? '' : sanitize_email($user->user_email);
        $phone         = sanitize_text_field((string) get_user_meta($user->ID, 'ks_telegram_phone', true));
        $show_phone    = '' === $phone;
        $error_message = $this->errorMessage();
        $action_url    = add_query_arg(['ks_telegram_login' => 'save_profile'], home_url('/'));

        wp_enqueue_style(
            'ks-telegram-auth',
            KS_TELEGRAM_URL . 'assets/css/auth.css',
            [],
            KS_TELEGRAM_VERSION
        );

        status_header(200);
        nocache_headers();

        require KS_TELEGRAM_DIR . 'templates/auth/profile-completion.php';
        exit;
    }

    /**
     * Save profile completion data.
     *
     * @return never
     */
    private function save(): never {
        if (! is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(home_url('/')));
            exit;
        }

        $nonce = filter_input(INPUT_POST, '_wpnonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        if (! is_string($nonce) || ! wp_verify_nonce($nonce, 'ks_telegram_complete_profile')) {
            wp_die(
                esc_html__('Profile security check failed.', 'ks-telegram'),
                esc_html__('Telegram profile update failed', 'ks-telegram'),
                ['response' => 403]
            );
        }

        $user        = wp_get_current_user();
        $redirect_to = $this->redirectFromRequest(INPUT_POST);
        $email       = sanitize_email((string) filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL));
        $phone_raw   = filter_input(INPUT_POST, 'phone', FILTER_UNSAFE_RAW);
        $phone       = $this->sanitizePhone(is_string($phone_raw) ? $phone_raw : '');

        if ($this->needsCompletion($user) && ('' === $email || ! is_email($email))) {
            $this->redirectWithError('email_required', $redirect_to);
        }

        if (is_string($phone_raw) && '' !== trim($phone_raw) && '' === $phone) {
            $this->redirectWithError('phone_invalid', $redirect_to);
        }

        if ('' !== $email && ! $this->saveEmail($user, $email)) {
            $this->redirectWithError('email_exists', $redirect_to);
        }

        if ('' !== $phone) {
            update_user_meta($user->ID, 'ks_telegram_phone', $phone);
            $this->phone_sync->copyToBillingPhone($user->ID, $phone);
        }

        wp_safe_redirect($redirect_to);
        exit;
    }

    /**
     * Save a user email if it is not already used by another user.
     *
     * @param WP_User $user  WordPress user.
     * @param string  $email New email address.
     *
     * @return bool
     */
    private function saveEmail(WP_User $user, string $email): bool {
        $existing_user_id = email_exists($email);

        if ($existing_user_id && (int) $existing_user_id !== $user->ID) {
            return false;
        }

        $result = wp_update_user(
            [
                'ID'         => $user->ID,
                'user_email' => $email,
            ]
        );

        if (is_wp_error($result)) {
            return false;
        }

        delete_user_meta($user->ID, self::GENERATED_EMAIL_META);

        return true;
    }

    /**
     * Check whether the current email is a generated placeholder.
     *
     * @param WP_User $user WordPress user.
     *
     * @return bool
     */
    private function hasGeneratedEmail(WP_User $user): bool {
        $marked = '1' === (string) get_user_meta($user->ID, self::GENERATED_EMAIL_META, true);
        $email  = strtolower($user->user_email);

        return $marked || (str_starts_with($email, 'telegram-') && str_ends_with($email, '@example.invalid'));
    }

    /**
     * Get a redirect target from request data.
     *
     * @param int $type Input type, such as INPUT_GET or INPUT_POST.
     *
     * @return string
     */
    private function redirectFromRequest(int $type): string {
        $redirect_raw = filter_input($type, 'redirect_to', FILTER_SANITIZE_URL);
        $redirect_to  = is_string($redirect_raw) && '' !== $redirect_raw
            ? esc_url_raw($redirect_raw)
            : home_url('/');

        return wp_validate_redirect($redirect_to, home_url('/'));
    }

    /**
     * Redirect back to the completion form with a sanitized error code.
     *
     * @param string $code        Error code.
     * @param string $redirect_to Final redirect target.
     *
     * @return never
     */
    private function redirectWithError(string $code, string $redirect_to): never {
        wp_safe_redirect(
            add_query_arg(
                [
                    'ks_telegram_login' => 'complete_profile',
                    'ks_telegram_profile_error'     => sanitize_key($code),
                    'redirect_to'                => $redirect_to,
                ],
                home_url('/')
            )
        );
        exit;
    }

    /**
     * Build an error message from the current request.
     *
     * @return string
     */
    private function errorMessage(): string {
        $error_raw = filter_input(INPUT_GET, 'ks_telegram_profile_error', FILTER_UNSAFE_RAW);
        $error     = is_string($error_raw) ? sanitize_key($error_raw) : '';

        return match ($error) {
            'email_required' => __('Enter a valid email address.', 'ks-telegram'),
            'email_exists'   => __('This email address is already used by another account.', 'ks-telegram'),
            'phone_invalid'  => __('Enter a valid phone number or leave the field empty.', 'ks-telegram'),
            default          => '',
        };
    }

    /**
     * Sanitize a phone number for user meta storage.
     *
     * @param string $value Raw phone number.
     *
     * @return string
     */
    private function sanitizePhone(string $value): string {
        $value = sanitize_text_field(wp_unslash($value));
        $value = preg_replace('/[^\d+]/', '', $value) ?? '';

        return preg_match('/^\+?\d{7,20}$/', $value) ? $value : '';
    }
}
