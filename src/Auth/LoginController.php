<?php
/**
 * Telegram Login controller.
 *
 * This file implements Telegram Login with OpenID Connect Authorization Code
 * Flow and PKCE, then signs verified Telegram users into WordPress.
 *
 * @author  Konstantin Sorokin
 * @link    https://konstantinsorokin.com
 * @package KS_Telegram
 */

declare(strict_types=1);

namespace KonstantinSorokin\Telegram\Auth;

use KonstantinSorokin\Telegram\Http\WpHttpClient;
use KonstantinSorokin\Telegram\Settings\SettingsRepository;
use KonstantinSorokin\Telegram\Plugins\WooCommerce\CustomerPhoneSync;
use WP_Error;
use WP_User;

defined('ABSPATH') || exit;

/**
 * Handles Telegram Login.
 */
final readonly class LoginController {

    private const string AUTH_ENDPOINT = 'https://oauth.telegram.org/auth';
    private const string STATE_PREFIX = 'ks_telegram_login_state_';

    /**
     * Holds the SHA-256 of the state parameter for the length of the round
     * trip, so the callback can prove it reached the browser that started it.
     */
    private const string STATE_COOKIE = 'ks_telegram_login_state';

    /**
     * Cookie name for one flow.
     *
     * Per flow, not one fixed name: a second sign-in in another tab used to
     * overwrite the first cookie, and finishing the older tab then failed the
     * check with a 403.
     *
     * @param string $state State parameter.
     *
     * @return string
     */
    private function stateCookieName(string $state): string {
        return self::STATE_COOKIE . '_' . substr(hash('sha256', $state), 0, 16);
    }

    public function __construct(
        private SettingsRepository $settings,
        private ProfileCompletionController $profile_completion,
        private CustomerPhoneSync $phone_sync,
        private TelegramAvatarImporter $avatar_importer
    ) {}

    /**
     * Register login hooks.
     *
     * @return void
     */
    public function boot(): void {
        add_shortcode('ks_telegram_login', [$this, 'shortcode']);
        add_action('template_redirect', [$this, 'route'], 0);
        add_action('login_enqueue_scripts', [$this, 'enqueueAuthAssets']);
        add_action('login_form', [$this, 'renderLoginFormButton']);
    }

    /**
     * Route public Telegram Login requests before WordPress renders a template.
     *
     * @return void
     */
    public function route(): void {
        $route_raw = filter_input(INPUT_GET, 'ks_telegram_login', FILTER_UNSAFE_RAW);
        $route     = is_string($route_raw) ? sanitize_key($route_raw) : '';

        match ($route) {
            'start'    => $this->start(),
            'callback' => $this->callback(),
            default    => null,
        };
    }

    /**
     * Render the Telegram Login button shortcode.
     *
     * @param mixed $atts Shortcode attributes: `class` for extra CSS classes,
     *                    `redirect_to` for where to land after signing in.
     *
     * @return string
     */
    public function shortcode(mixed $atts = []): string {
        $atts = shortcode_atts(
            [
                'class'       => '',
                'redirect_to' => '',
            ],
            is_array($atts) ? $atts : [],
            'ks_telegram_login'
        );

        return $this->button((string) $atts['class'], (string) $atts['redirect_to']);
    }

    /**
     * Render the Telegram Login button for a theme or another plugin.
     *
     * Shared by the shortcode and ks_telegram_login_button(), so a
     * template gets the asset enqueue too. Returns '' when login is off,
     * unconfigured, or the visitor is signed in — safe to echo.
     *
     * @param string $class       Extra CSS classes.
     * @param string $redirect_to Where to land after signing in.
     *
     * @return string
     */
    public function button(string $class = '', string $redirect_to = ''): string {
        if (! $this->isLoginAvailable() || is_user_logged_in()) {
            return '';
        }

        $this->enqueueAuthAssets();

        $redirect_to = '' !== trim($redirect_to)
            ? wp_validate_redirect($redirect_to, $this->currentUrl())
            : $this->currentUrl();

        return $this->buttonMarkup(
            $this->loginUrl($redirect_to),
            implode(' ', array_filter(array_map('sanitize_html_class', preg_split('/\s+/', trim($class)) ?: [])))
        );
    }

    /**
     * Render the Telegram Login button on wp-login.php.
     *
     * @return void
     */
    public function renderLoginFormButton(): void {
        if (! $this->isLoginAvailable()) {
            return;
        }

        echo wp_kses(
            $this->buttonMarkup(
                $this->loginUrl($this->redirectFromRequest()),
                'ks-telegram-login__button--wp-login'
            ),
            $this->buttonAllowedHtml()
        );
    }

    /**
     * Start Telegram OIDC authorization.
     *
     * @return never
     */
    public function start(): never {
        if (! $this->settings->bool('telegram_login_enabled')) {
            wp_die(
                esc_html__('Telegram Login is disabled.', 'ks-telegram'),
                esc_html__('Telegram Login failed', 'ks-telegram'),
                ['response' => 403]
            );
        }

        $this->verifyStartNonce();

        $client_id = $this->settings->loginClientId();
        if ('' === $client_id) {
            wp_die(
                esc_html__('Telegram Login client ID is not configured.', 'ks-telegram'),
                esc_html__('Telegram Login failed', 'ks-telegram'),
                ['response' => 400]
            );
        }

        $state         = $this->randomToken(32);
        $nonce         = $this->randomToken(32);
        $code_verifier = $this->randomToken(64);
        $redirect_to   = $this->redirectFromRequest();

        /*
         * Intent is recorded here, where it is known. Deciding it at the callback
         * from whoever happened to be logged in is what allowed account takeover.
         */
        set_transient(
            self::STATE_PREFIX . $state,
            [
                'nonce'         => $nonce,
                'code_verifier' => $code_verifier,
                'redirect_to'   => $redirect_to,
                'intent'        => is_user_logged_in() ? 'link' : 'login',
                'user_id'       => get_current_user_id(),
                'session_token' => (string) wp_get_session_token(),
            ],
            10 * MINUTE_IN_SECONDS
        );

        /*
         * Binds the round trip to this browser: the state alone was a bearer token.
         * Only the hash, so the cookie cannot be replayed as the state parameter.
         * Lax, not Strict — Telegram returns via a cross-site top-level GET.
         */
        setcookie(
            $this->stateCookieName($state),
            hash('sha256', $state),
            [
                'expires'  => time() + 10 * MINUTE_IN_SECONDS,
                'path'     => COOKIEPATH ?: '/',
                'domain'   => COOKIE_DOMAIN,
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );

        $auth_url = add_query_arg(
            [
                'response_type'         => 'code',
                'client_id'             => $client_id,
                'redirect_uri'          => $this->callbackUrl(),
                'scope'                 => implode(' ', $this->scopes()),
                'state'                 => $state,
                'nonce'                 => $nonce,
                'code_challenge'        => $this->codeChallenge($code_verifier),
                'code_challenge_method' => 'S256',
            ],
            self::AUTH_ENDPOINT
        );

        add_filter(
            'allowed_redirect_hosts',
            static function (array $hosts): array {
                $hosts[] = 'oauth.telegram.org';

                return $hosts;
            }
        );

        wp_safe_redirect($auth_url);
        exit;
    }

    /**
     * Handle Telegram OIDC callback.
     *
     * @return never
     */
    public function callback(): never {
        /*
         * start() refuses when the feature is off; the callback did not, so a state
         * stayed redeemable for ten minutes after the switch was flipped.
         */
        if (! $this->settings->bool('telegram_login_enabled')) {
            wp_die(
                esc_html__('Telegram Login is disabled.', 'ks-telegram'),
                esc_html__('Telegram Login failed', 'ks-telegram'),
                ['response' => 403]
            );
        }

        $error = filter_input(INPUT_GET, 'error', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        if (is_string($error) && '' !== $error) {
            wp_die(esc_html($error), esc_html__('Telegram Login failed', 'ks-telegram'), ['response' => 403]);
        }

        $code  = filter_input(INPUT_GET, 'code', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $state = filter_input(INPUT_GET, 'state', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        if (! is_string($code) || '' === $code || ! is_string($state) || '' === $state) {
            wp_die(
                esc_html__('Telegram Login callback is missing required data.', 'ks-telegram'),
                esc_html__('Telegram Login failed', 'ks-telegram'),
                ['response' => 400]
            );
        }

        // Read and cleared first, so a failed attempt leaves no reusable cookie.
        /*
         * Unslashed and sanitized at the point of access. Neither alters a hex
         * digest, so the comparison below is unaffected.
         */
        $cookie_name = $this->stateCookieName($state);
        $cookie      = isset($_COOKIE[$cookie_name])
            ? sanitize_text_field(wp_unslash($_COOKIE[$cookie_name]))
            : '';

        // Cleared only for this flow, and only once it is ours: clearing first
        // let any stray hit on the callback URL kill an unrelated sign-in.
        if ('' !== $cookie) {
            setcookie(
                $cookie_name,
                '',
                [
                    'expires'  => time() - YEAR_IN_SECONDS,
                    'path'     => COOKIEPATH ?: '/',
                    'domain'   => COOKIE_DOMAIN,
                    'secure'   => is_ssl(),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]
            );
        }

        if ('' === $cookie || ! hash_equals(hash('sha256', $state), $cookie)) {
            wp_die(
                esc_html__('This Telegram Login link was not opened in the browser that started the sign-in. Please start again from this site.', 'ks-telegram'),
                esc_html__('Telegram Login failed', 'ks-telegram'),
                ['response' => 403]
            );
        }

        $state_data = get_transient(self::STATE_PREFIX . $state);
        delete_transient(self::STATE_PREFIX . $state);

        if (! is_array($state_data)) {
            wp_die(
                esc_html__('Telegram Login state has expired.', 'ks-telegram'),
                esc_html__('Telegram Login failed', 'ks-telegram'),
                ['response' => 403]
            );
        }

        /*
         * The session finishing the flow must be the one that began it: a link
         * returns to the same user, a login returns to no session at all.
         */
        $intent     = 'link' === ($state_data['intent'] ?? '') ? 'link' : 'login';
        $started_by = absint($state_data['user_id'] ?? 0);

        $same_session = 'link' === $intent
            ? get_current_user_id() === $started_by
                && $started_by > 0
                && hash_equals((string) ($state_data['session_token'] ?? ''), (string) wp_get_session_token())
            : ! is_user_logged_in();

        if (! $same_session) {
            wp_die(
                esc_html__('This Telegram Login attempt does not belong to the current session. Please start again from this site.', 'ks-telegram'),
                esc_html__('Telegram Login failed', 'ks-telegram'),
                ['response' => 403]
            );
        }

        $http   = new WpHttpClient();
        $tokens = (new OidcTokenClient($this->settings, $http))->exchangeCode(
            $code,
            $this->callbackUrl(),
            (string) ($state_data['code_verifier'] ?? '')
        );

        if (is_wp_error($tokens)) {
            wp_die(esc_html($tokens->get_error_message()), esc_html__('Telegram Login failed', 'ks-telegram'), ['response' => 403]);
        }

        $claims = (new OidcTokenValidator($this->settings, $http))->validate(
            (string) $tokens['id_token'],
            (string) ($state_data['nonce'] ?? '')
        );

        if (is_wp_error($claims)) {
            wp_die(esc_html($claims->get_error_message()), esc_html__('Telegram Login failed', 'ks-telegram'), ['response' => 403]);
        }

        $profile = $this->profileFromClaims($claims);
        $user    = $this->resolveUser($profile, $intent, $started_by);

        if (is_wp_error($user)) {
            wp_die(esc_html($user->get_error_message()), esc_html__('Telegram Login failed', 'ks-telegram'), ['response' => 403]);
        }

        $this->storeTelegramProfile($user->ID, $profile);

        /*
         * A link is already authenticated as this user; a new cookie would
         * silently promote the session to a 14-day one.
         */
        if ('login' === $intent) {
            wp_set_current_user($user->ID);
            wp_set_auth_cookie($user->ID, true);
        }

        $redirect_to = wp_validate_redirect((string) ($state_data['redirect_to'] ?? home_url('/')), home_url('/'));

        if ($this->profile_completion->needsCompletion($user)) {
            $redirect_to = $this->profile_completion->completionUrl($redirect_to);
        }

        wp_safe_redirect($redirect_to);
        exit;
    }

    /**
     * Resolve or create a WordPress user.
     *
     * @param array<string,string> $data Verified Telegram profile.
     *
     * @return WP_User|WP_Error
     */
    private function resolveUser(array $data, string $intent, int $started_by): WP_User|WP_Error {
        $telegram_id = $data['id'] ?? '';

        if ('' === $telegram_id) {
            return new WP_Error('ks_telegram_login_missing_id', __('Telegram account ID is missing.', 'ks-telegram'));
        }

        $linked_user_id = absint(get_option('ks_telegram_user_' . sanitize_key($telegram_id), 0));

        /*
         * The user comes from who started the flow, not from is_user_logged_in(),
         * which answers a question about the browser holding the callback.
         */
        if ('link' === $intent) {
            $user = $started_by > 0 ? get_user_by('id', $started_by) : false;

            if (! $user instanceof WP_User) {
                return new WP_Error('ks_telegram_login_invalid_current_user', __('Current user is invalid.', 'ks-telegram'));
            }

            /*
             * One Telegram account, one WordPress user. Otherwise a second link moves
             * the account and hands over a session that survives a password change.
             */
            if ($linked_user_id > 0 && $linked_user_id !== $user->ID) {
                return new WP_Error(
                    'ks_telegram_login_already_linked',
                    __('This Telegram account is already linked to a different user on this site.', 'ks-telegram')
                );
            }

            return $user;
        }

        $linked_user = $linked_user_id > 0 ? get_user_by('id', $linked_user_id) : false;
        if ($linked_user instanceof WP_User) {
            return $linked_user;
        }

        if (! $this->settings->bool('telegram_login_create_users')) {
            return new WP_Error('ks_telegram_login_user_not_found', __('No WordPress user is linked to this Telegram account.', 'ks-telegram'));
        }

        return $this->createUser($data);
    }

    /**
     * Create a WordPress user from a Telegram profile.
     *
     * @param array<string,string> $data Verified Telegram profile.
     *
     * @return WP_User|WP_Error
     */
    private function createUser(array $data): WP_User|WP_Error {
        $base_login      = sanitize_user('telegram_' . ($data['username'] ?: $data['id']), true);
        $user_login      = $base_login;
        $suffix          = 1;
        $email           = $this->emailFromProfile($data);
        $generated_email = '' === $email;

        while (username_exists($user_login)) {
            $user_login = $base_login . '_' . $suffix;
            ++$suffix;
        }

        $user_id = wp_insert_user(
            [
                'user_login'   => $user_login,
                'user_pass'    => wp_generate_password(32, true, true),
                'user_email'   => $generated_email ? $this->generatedEmail($data['id']) : $email,
                'display_name' => trim($data['first_name'] . ' ' . $data['last_name']) ?: $user_login,
                'first_name'   => $data['first_name'],
                'last_name'    => $data['last_name'],
                'role'         => get_option('default_role', 'subscriber'),
            ]
        );

        if (is_wp_error($user_id)) {
            return $user_id;
        }

        if ($generated_email) {
            $this->profile_completion->markGeneratedEmail((int) $user_id);
        }

        $user = get_user_by('id', (int) $user_id);

        return $user instanceof WP_User ? $user : new WP_Error('ks_telegram_login_user_create_failed', __('Could not create WordPress user.', 'ks-telegram'));
    }

    /**
     * Store Telegram profile data in user meta.
     *
     * @param int                  $user_id User ID.
     * @param array<string,string> $data    Verified Telegram profile.
     *
     * @return void
     */
    private function storeTelegramProfile(int $user_id, array $data): void {
        update_user_meta($user_id, 'ks_telegram_id', $data['id']);
        update_user_meta($user_id, 'ks_telegram_first_name', $data['first_name']);
        update_user_meta($user_id, 'ks_telegram_last_name', $data['last_name']);

        /*
         * Telegram not sending a value is not the same as the value being
         * empty. The phone is typed by hand in wp-admin and on the completion
         * form, and it was wiped on every sign-in whenever the phone scope was
         * not requested — which is the default.
         */
        $optional = [
            'username'  => 'ks_telegram_username',
            'photo_url' => 'ks_telegram_photo_url',
            'phone'     => 'ks_telegram_phone',
        ];

        foreach ($optional as $key => $meta) {
            if ('' !== (string) ($data[$key] ?? '')) {
                update_user_meta($user_id, $meta, $data[$key]);
            }
        }

        $this->phone_sync->copyToBillingPhone($user_id, (string) ($data['phone'] ?? ''));
        // The picture claim: $data['id'] is the OIDC sub, not a Bot API user id.
        $avatar = $this->avatar_importer->import($user_id, $data['id'], $data['photo_url'] ?? '');
        if (is_wp_error($avatar)) {
            do_action('ks_telegram_avatar_import_failed', $avatar, $user_id);
        }
        update_option('ks_telegram_user_' . sanitize_key($data['id']), $user_id, false);
    }

    /**
     * Build a normalized Telegram profile from OIDC claims.
     *
     * @param array<string,mixed> $claims ID token claims.
     *
     * @return array<string,string>
     */
    private function profileFromClaims(array $claims): array {
        $name = sanitize_text_field((string) ($claims['name'] ?? ''));
        $parts = preg_split('/\s+/', trim($name), 2) ?: [];

        return [
            'id'         => sanitize_text_field((string) ($claims['sub'] ?? '')),
            'first_name' => sanitize_text_field((string) ($claims['given_name'] ?? $parts[0] ?? '')),
            'last_name'  => sanitize_text_field((string) ($claims['family_name'] ?? $parts[1] ?? '')),
            'username'   => sanitize_user((string) ($claims['preferred_username'] ?? ''), true),
            'photo_url'  => esc_url_raw((string) ($claims['picture'] ?? '')),
            'phone'      => sanitize_text_field((string) ($claims['phone_number'] ?? '')),
            'email'      => sanitize_email((string) ($claims['email'] ?? '')),
        ];
    }

    /**
     * Get a verified usable email from Telegram claims when available.
     *
     * @param array<string,string> $data Verified Telegram profile.
     *
     * @return string
     */
    private function emailFromProfile(array $data): string {
        $email = sanitize_email($data['email'] ?? '');

        return '' !== $email && is_email($email) && ! email_exists($email) ? $email : '';
    }

    /**
     * Build a placeholder email for accounts that must complete their profile.
     *
     * @param string $telegram_id Telegram account ID.
     *
     * @return string
     */
    private function generatedEmail(string $telegram_id): string {
        return 'telegram-' . sanitize_key($telegram_id) . '@example.invalid';
    }

    /**
     * Check whether Telegram Login can be shown.
     *
     * @return bool
     */
    private function isLoginAvailable(): bool {
        return $this->settings->bool('telegram_login_enabled') && '' !== $this->settings->loginClientId();
    }

    /**
     * Build the public Telegram Login start URL.
     *
     * @param string $redirect_to Final redirect target after successful login.
     *
     * @return string
     */
    private function loginUrl(string $redirect_to): string {
        /*
         * rawurlencode, because add_query_arg does not encode its values: a
         * redirect target carrying its own query lost everything after the
         * first ampersand, and those parameters leaked into this URL instead.
         */
        return wp_nonce_url(
            add_query_arg(
                [
                    'ks_telegram_login' => 'start',
                    'redirect_to'                => rawurlencode($redirect_to),
                ],
                home_url('/')
            ),
            'ks_telegram_login_start'
        );
    }

    /**
     * Enqueue public Telegram Login assets.
     *
     * @return void
     */
    public function enqueueAuthAssets(): void {
        if (! $this->isLoginAvailable()) {
            return;
        }

        wp_enqueue_style(
            'ks-telegram-auth',
            KS_TELEGRAM_URL . 'assets/css/auth.css',
            [],
            KS_TELEGRAM_VERSION
        );
    }

    /**
     * Build the Telegram Login button markup.
     *
     * @param string $url         Login start URL.
     * @param string $extra_class Optional extra button class.
     *
     * @return string
     */
    private function buttonMarkup(string $url, string $extra_class = ''): string {
        $class = trim('button ks-telegram-login__button ' . $extra_class);

        return sprintf(
            '<p class="ks-telegram-login"><a class="%1$s" href="%2$s">%3$s<span>%4$s</span></a></p>',
            esc_attr($class),
            esc_url($url),
            wp_kses($this->telegramIcon(), $this->svgAllowedHtml()),
            esc_html__('Log in with Telegram', 'ks-telegram')
        );
    }

    /**
     * Get the allowed tags for rendered Telegram Login button markup.
     *
     * @return array<string,array<string,bool>>
     */
    private function buttonAllowedHtml(): array {
        return array_merge(
            [
                'p'    => [
                    'class' => true,
                ],
                'a'    => [
                    'class' => true,
                    'href'  => true,
                ],
                'span' => [],
            ],
            $this->svgAllowedHtml()
        );
    }

    /**
     * Get the Telegram icon SVG.
     *
     * @return string
     */
    private function telegramIcon(): string {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="ks-telegram-login__icon bi bi-telegram" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0M8.287 5.906q-1.168.486-4.666 2.01-.567.225-.595.442c-.03.243.275.339.69.47l.175.055c.408.133.958.288 1.243.294q.39.01.868-.32 3.269-2.206 3.374-2.23c.05-.012.12-.026.166.016s.042.12.037.141c-.03.129-1.227 1.241-1.846 1.817-.193.18-.33.307-.358.336a8 8 0 0 1-.188.186c-.38.366-.664.64.015 1.088.327.216.589.393.85.571.284.194.568.387.936.629q.14.092.27.187c.331.236.63.448.997.414.214-.02.435-.22.547-.82.265-1.417.786-4.486.906-5.751a1.4 1.4 0 0 0-.013-.315.34.34 0 0 0-.114-.217.53.53 0 0 0-.31-.093c-.3.005-.763.166-2.984 1.09"/></svg>';
    }

    /**
     * Get the allowed SVG tags for the hardcoded Telegram icon.
     *
     * @return array<string,array<string,bool>>
     */
    private function svgAllowedHtml(): array {
        return [
            'svg'  => [
                'xmlns'       => true,
                'width'       => true,
                'height'      => true,
                'fill'        => true,
                'class'       => true,
                'viewbox'     => true,
                'aria-hidden' => true,
                'focusable'   => true,
            ],
            'path' => [
                'd' => true,
            ],
        ];
    }

    /**
     * Build Telegram OAuth scopes.
     *
     * @return array<int,string>
     */
    private function scopes(): array {
        $scopes = ['openid', 'profile'];

        if ($this->settings->bool('telegram_login_request_phone')) {
            $scopes[] = 'phone';
        }

        if ($this->settings->bool('telegram_login_request_write')) {
            $scopes[] = 'telegram:bot_access';
        }

        return $scopes;
    }

    /**
     * Build the fixed Telegram Login callback URL.
     *
     * @return string
     */
    private function callbackUrl(): string {
        return add_query_arg(
            ['ks_telegram_login' => 'callback'],
            home_url('/')
        );
    }

    /**
     * Verify the public start request nonce.
     *
     * @return void
     */
    private function verifyStartNonce(): void {
        /*
         * Only enforced for a signed-in visitor, where the flow means "link my
         * account" and the nonce is tied to their session. For a logged-out
         * visitor uid is 0 and the session token is empty, so the nonce is the
         * same string for everybody and protects nothing — while a full-page
         * cache serving it past its lifetime turned the button into a 403. The
         * flow's real CSRF binding is the state cookie set in start().
         */
        if (! is_user_logged_in()) {
            return;
        }

        $nonce = filter_input(INPUT_GET, '_wpnonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        if (is_string($nonce) && wp_verify_nonce($nonce, 'ks_telegram_login_start')) {
            return;
        }

        wp_die(
            esc_html__('Telegram Login security check failed.', 'ks-telegram'),
            esc_html__('Telegram Login failed', 'ks-telegram'),
            ['response' => 403]
        );
    }

    /**
     * Get the redirect target from the current request.
     *
     * @return string
     */
    private function redirectFromRequest(): string {
        $redirect_raw = filter_input(INPUT_GET, 'redirect_to', FILTER_SANITIZE_URL);
        $redirect_to  = is_string($redirect_raw) && '' !== $redirect_raw
            ? esc_url_raw(rawurldecode($redirect_raw))
            : home_url('/');

        return wp_validate_redirect($redirect_to, home_url('/'));
    }

    /**
     * Get the current absolute URL.
     *
     * @return string
     */
    private function currentUrl(): string {
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_HOST'])) : wp_parse_url(home_url(), PHP_URL_HOST);
        $uri  = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REQUEST_URI'])) : '/';

        return set_url_scheme('https://' . $host . $uri, is_ssl() ? 'https' : 'http');
    }

    /**
     * Generate a PKCE code challenge.
     *
     * @param string $verifier Code verifier.
     *
     * @return string
     */
    private function codeChallenge(string $verifier): string {
        return $this->base64UrlEncode(hash('sha256', $verifier, true));
    }

    /**
     * Generate a URL-safe random token.
     *
     * @param int $bytes Number of random bytes.
     *
     * @return string
     */
    private function randomToken(int $bytes): string {
        try {
            return $this->base64UrlEncode(random_bytes($bytes));
        } catch (\Throwable) {
            return wp_generate_password(max(43, $bytes), false, false);
        }
    }

    /**
     * Base64url-encode raw bytes.
     *
     * @param string $value Raw bytes.
     *
     * @return string
     */
    private function base64UrlEncode(string $value): string {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
