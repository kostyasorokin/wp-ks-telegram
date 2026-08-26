<?php
/**
 * Site Health tests.
 *
 * This file adds the plugin's own tests to WordPress Site Health, so that
 * "notifications stopped arriving" becomes a thing a site owner can see rather
 * than something they eventually notice.
 *
 * @author  Konstantin Sorokin
 * @link    https://konstantinsorokin.com
 * @package KS_Telegram
 */

declare(strict_types=1);

namespace KonstantinSorokin\Telegram\Admin;

use KonstantinSorokin\Telegram\Bot\TelegramApiClient;
use KonstantinSorokin\Telegram\Settings\SettingsRepository;
use KonstantinSorokin\Telegram\Support\DeliveryLog;
use WP_Error;

defined('ABSPATH') || exit;

/**
 * Registers Site Health tests and debug information for the plugin.
 *
 * Both tests are `direct` and cache their network calls, so reloading the
 * screen does not ask Telegram or Google again.
 */
final readonly class HealthCheck {

    /**
     * Caches the getMe probe. Short, because a revoked token should surface
     * within minutes rather than within an hour.
     */
    private const string BOT_TRANSIENT = 'ks_telegram_health_bot';

    private const int BOT_TTL = 300;

    /**
     * Caches the spam-gate probe. Longer: this tests a configuration that
     * changes when somebody edits it, not something that drifts on its own.
     */
    private const string GATE_TRANSIENT = 'ks_telegram_health_gate';

    private const int GATE_TTL = 3600;

    public function __construct(
        private SettingsRepository $settings,
        private TelegramApiClient $client,
        private DeliveryLog $log
    ) {}

    /**
     * Register the tests and the debug panel.
     *
     * @return void
     */
    public function boot(): void {
        add_filter('site_status_tests', [$this, 'registerTests']);
        add_filter('debug_information', [$this, 'registerDebugInformation']);
    }

    /**
     * Add the plugin's tests to Site Health.
     *
     * @param array<string,mixed> $tests Registered tests.
     *
     * @return array<string,mixed>
     */
    public function registerTests(array $tests): array {
        $tests['direct']['ks_telegram_delivery'] = [
            'label' => __('Telegram notifications can be delivered', 'ks-telegram'),
            'test'  => [$this, 'testDelivery'],
        ];

        $tests['direct']['ks_telegram_form_gate'] = [
            'label' => __('Contact form spam checks are answering', 'ks-telegram'),
            'test'  => [$this, 'testFormGate'],
        ];

        return $tests;
    }

    /**
     * Test that the plugin can actually deliver a message.
     *
     * Three things in order of how badly they break delivery: a token Telegram
     * refuses, no chat to send to, and a last attempt that failed. The first
     * two are configuration and the third is history, so they are reported
     * separately rather than folded into one verdict.
     *
     * @return array<string,mixed>
     */
    public function testDelivery(): array {
        if ('' === trim($this->settings->string('bot_token'))) {
            return $this->result(
                'recommended',
                __('KS Telegram has no bot token', 'ks-telegram'),
                __('No bot token is configured, so no notification can be sent. This is expected if you have not set the plugin up yet.', 'ks-telegram'),
                'ks_telegram_delivery'
            );
        }

        $bot = $this->probeBot();

        if ($bot instanceof WP_Error) {
            return $this->result(
                'critical',
                __('Telegram is refusing the bot token', 'ks-telegram'),
                sprintf(
                    /* translators: %s: error message returned by Telegram. */
                    __('Telegram answered: %s. Until this is fixed no notification of any kind will arrive.', 'ks-telegram'),
                    '<code>' . esc_html($bot->get_error_message()) . '</code>'
                ),
                'ks_telegram_delivery'
            );
        }

        if ([] === $this->settings->parseChatIds(null)) {
            return $this->result(
                'critical',
                __('KS Telegram has nowhere to send', 'ks-telegram'),
                __('The bot token works, but no default chat ID is configured. Messages are built and then discarded.', 'ks-telegram'),
                'ks_telegram_delivery'
            );
        }

        if ($this->log->isFailing()) {
            $failures = $this->log->failures();
            $newest   = $failures[0] ?? [];

            return $this->result(
                'critical',
                __('The last Telegram delivery failed', 'ks-telegram'),
                sprintf(
                    /* translators: 1: error message from Telegram, 2: human-readable time difference. */
                    __('The most recent attempt was refused with: %1$s (%2$s ago). Nothing has been delivered since.', 'ks-telegram'),
                    '<code>' . esc_html((string) ($newest['message'] ?? '')) . '</code>',
                    esc_html(human_time_diff((int) ($newest['time'] ?? time())))
                ),
                'ks_telegram_delivery'
            );
        }

        $last = $this->log->lastSuccess();

        return $this->result(
            'good',
            __('Telegram notifications can be delivered', 'ks-telegram'),
            0 === $last
                ? __('The bot token works and a chat is configured. Nothing has been sent yet, so there is no delivery history.', 'ks-telegram')
                : sprintf(
                    /* translators: %s: human-readable time difference. */
                    __('The bot token works, a chat is configured, and the last message was delivered %s ago.', 'ks-telegram'),
                    esc_html(human_time_diff($last))
                ),
            'ks_telegram_delivery'
        );
    }

    /**
     * Test that the spam checks in front of the contact forms still answer.
     *
     * A retired reCAPTCHA key does not announce itself: verification returns
     * refused, CF7 scores every submission as spam, and the visitor sees the
     * same sentence CF7 uses when the mailer breaks.
     *
     * @return array<string,mixed>
     */
    public function testFormGate(): array {
        $streak = $this->spamStreak();

        /*
         * The owner's own threshold, including the documented 0 = "never warn".
         * Four places used to hardcode 5 and contradict the setting beside them.
         */
        $threshold = $this->settings->int('cf7_spam_streak_threshold', 5);
        $streaking = $threshold > 0 && $streak >= $threshold;

        if (! defined('WPCF7_VERSION')) {
            return $this->result(
                'good',
                __('No contact form spam check to test', 'ks-telegram'),
                __('Contact Form 7 is not active, so there is no spam check in front of a form here.', 'ks-telegram'),
                'ks_telegram_form_gate'
            );
        }

        $secret = $this->recaptchaSecret();

        if ('' === $secret) {
            return $this->result(
                $streaking ? 'recommended' : 'good',
                __('No reCAPTCHA keys are configured', 'ks-telegram'),
                $streaking
                    ? sprintf(
                        /* translators: %d: number of consecutive rejected submissions. */
                        __('Contact Form 7 has no reCAPTCHA keys, yet the last %d submissions in a row were rejected as spam. Something else is refusing them.', 'ks-telegram'),
                        $streak
                    )
                    : __('Contact Form 7 is active with no reCAPTCHA integration, so nothing here can silently reject submissions.', 'ks-telegram'),
                'ks_telegram_form_gate'
            );
        }

        /*
         * Opt-in: the probe sends CF7's reCAPTCHA secret to Google. Without it
         * a run of spam rejections is the same signal, with no third party.
         */
        if (! $this->settings->bool('health_recaptcha_probe')) {
            return $this->result(
                $streaking ? 'recommended' : 'good',
                $streaking
                    ? __('Every recent submission was rejected as spam', 'ks-telegram')
                    : __('A reCAPTCHA key is configured', 'ks-telegram'),
                $streaking
                    ? sprintf(
                        /* translators: %d: number of consecutive rejected submissions. */
                        __('The last %d submissions in a row were rejected as spam. Real spam arrives in bursts, so an unbroken run points at the spam check rather than at the senders — an expired or unmigrated reCAPTCHA key does exactly this. The key itself was not tested: that check is off by default because it means sending your reCAPTCHA secret to Google, and you can switch it on in the plugin settings.', 'ks-telegram'),
                        $streak
                    )
                    : __('Submissions are not being rejected in a run, so the spam check appears to be working. The key itself was not tested: that check is off by default because it means sending your reCAPTCHA secret to Google, and you can switch it on in the plugin settings.', 'ks-telegram'),
                'ks_telegram_form_gate'
            );
        }

        $verdict = $this->probeRecaptcha($secret);

        return match ($verdict['state']) {
            'ok' => $this->result(
                'good',
                __('The reCAPTCHA key is answering', 'ks-telegram'),
                __('Google accepted the key and rejected the test token, which is exactly the right answer. Submissions are being scored rather than refused outright.', 'ks-telegram'),
                'ks_telegram_form_gate'
            ),
            'unreachable' => $this->result(
                'recommended',
                __('The reCAPTCHA service could not be reached', 'ks-telegram'),
                __('The key could not be checked because the request to Google failed. If this persists, every submission will be scored as spam and rejected.', 'ks-telegram'),
                'ks_telegram_form_gate'
            ),
            default => $this->result(
                'critical',
                __('The reCAPTCHA key is being refused', 'ks-telegram'),
                sprintf(
                    /* translators: 1: error code returned by Google, 2: sentence about the consequence. */
                    __('Google refused the key itself: %1$s. %2$s', 'ks-telegram'),
                    '<code>' . esc_html($verdict['detail']) . '</code>',
                    $streak > 0
                        ? sprintf(
                            /* translators: %d: number of consecutive rejected submissions. */
                            _n('%d submission in a row has already been rejected as spam because of it.', '%d submissions in a row have already been rejected as spam because of it.', $streak, 'ks-telegram'),
                            $streak
                        )
                        : __('Every submission to a protected form will be rejected as spam, and the visitor will be told the message could not be sent.', 'ks-telegram')
                ),
                'ks_telegram_form_gate'
            ),
        };
    }

    /**
     * Add plugin facts to the Site Health Info screen.
     *
     * @param array<string,mixed> $info Debug information.
     *
     * @return array<string,mixed>
     */
    public function registerDebugInformation(array $info): array {
        $failures = $this->log->failures();
        $newest   = $failures[0] ?? [];
        $success  = $this->log->lastSuccess();

        $info['ks_telegram'] = [
            'label'  => __('KS Telegram', 'ks-telegram'),
            'fields' => [
                'bot_token'      => [
                    'label'   => __('Bot token', 'ks-telegram'),
                    'value'   => '' !== trim($this->settings->string('bot_token')) ? __('Configured', 'ks-telegram') : __('Not configured', 'ks-telegram'),
                    'private' => false,
                ],
                'chats'          => [
                    'label' => __('Configured chats', 'ks-telegram'),
                    'value' => (string) count($this->settings->parseChatIds(null)),
                ],
                'last_success'   => [
                    'label' => __('Last delivered', 'ks-telegram'),
                    'value' => 0 === $success ? __('Never', 'ks-telegram') : gmdate('Y-m-d H:i:s', $success) . ' UTC',
                ],
                'last_failure'   => [
                    'label' => __('Last failure', 'ks-telegram'),
                    'value' => [] === $newest
                        ? __('None recorded', 'ks-telegram')
                        : gmdate('Y-m-d H:i:s', (int) ($newest['time'] ?? 0)) . ' UTC — ' . (string) ($newest['message'] ?? ''),
                ],
                'failures_kept'  => [
                    'label' => __('Failures on record', 'ks-telegram'),
                    'value' => (string) count($failures),
                ],
                'cf7_spam_streak' => [
                    'label' => __('Consecutive spam rejections', 'ks-telegram'),
                    'value' => (string) $this->spamStreak(),
                ],
            ],
        ];

        return $info;
    }

    /**
     * Get the spam streak, ignoring a count nobody has touched for a week.
     *
     * The counter only moves while submissions arrive. Without an age check a
     * long-fixed outage kept Site Health red for good.
     *
     * @return int
     */
    private function spamStreak(): int {
        $streak = (int) get_option('ks_telegram_cf7_spam_streak', 0);

        if (0 === $streak) {
            return 0;
        }

        // No timestamp means the count predates it — an upgrade from a version
        // that never wrote one. Unknown age is not evidence of anything current.
        $seen = (int) get_option('ks_telegram_cf7_spam_streak_seen', 0);

        return ($seen > 0 && (time() - $seen) <= WEEK_IN_SECONDS) ? $streak : 0;
    }

    /**
     * Ask Telegram whether the token works, at most once per BOT_TTL.
     *
     * @return array<string,mixed>|WP_Error
     */
    private function probeBot(): array|WP_Error {
        $cached = get_transient(self::BOT_TRANSIENT);

        if (is_array($cached) && isset($cached['ok'])) {
            return true === $cached['ok']
                ? (array) ($cached['data'] ?? [])
                : new WP_Error('ks_telegram_bot_unavailable', (string) ($cached['message'] ?? ''));
        }

        $result = $this->client->getMe();

        set_transient(
            self::BOT_TRANSIENT,
            $result instanceof WP_Error
                ? ['ok' => false, 'message' => $result->get_error_message()]
                : ['ok' => true, 'data' => $result],
            self::BOT_TTL
        );

        return $result;
    }

    /**
     * Ask Google whether the reCAPTCHA secret is still accepted.
     *
     * @param string $secret reCAPTCHA secret key.
     *
     * @return array{state:string,detail:string}
     */
    private function probeRecaptcha(string $secret): array {
        $key    = self::GATE_TRANSIENT . '_' . md5($secret);
        $cached = get_transient($key);

        if (is_array($cached) && isset($cached['state'], $cached['detail'])) {
            return ['state' => (string) $cached['state'], 'detail' => (string) $cached['detail']];
        }

        $response = wp_remote_post(
            'https://www.google.com/recaptcha/api/siteverify',
            [
                'timeout' => 10,
                'body'    => ['secret' => $secret, 'response' => 'ks-telegram-health-probe'],
            ]
        );

        if (is_wp_error($response)) {
            $verdict = ['state' => 'unreachable', 'detail' => $response->get_error_message()];
        } elseif (200 !== (int) wp_remote_retrieve_response_code($response)) {
            // A 502 or an HTML error page from a proxy says nothing about the key.
            $verdict = ['state' => 'unreachable', 'detail' => (string) wp_remote_retrieve_response_code($response)];
        } else {
            $body   = json_decode((string) wp_remote_retrieve_body($response), true);
            $codes  = is_array($body) && is_array($body['error-codes'] ?? null) ? $body['error-codes'] : [];
            $codes  = array_map('strval', $codes);

            /*
             * `invalid-input-response` means Google found the secret and objected
             * only to the bogus token — the pass condition. Anything else is the key.
             */
            if (! is_array($body)) {
                $verdict = ['state' => 'unreachable', 'detail' => __('the reply was not JSON', 'ks-telegram')];
            } else {
                $verdict = in_array('invalid-input-response', $codes, true)
                    ? ['state' => 'ok', 'detail' => '']
                    : ['state' => 'refused', 'detail' => implode('; ', $codes) ?: __('no error code returned', 'ks-telegram')];
            }
        }

        set_transient($key, $verdict, self::GATE_TTL);

        return $verdict;
    }

    /**
     * Read the reCAPTCHA secret Contact Form 7 has stored.
     *
     * Read from the option rather than through WPCF7_RECAPTCHA so that a change
     * in that class cannot turn this test into a silent pass.
     *
     * @return string
     */
    private function recaptchaSecret(): string {
        $wpcf7 = get_option('wpcf7', []);
        $keys  = is_array($wpcf7) && is_array($wpcf7['recaptcha'] ?? null) ? $wpcf7['recaptcha'] : [];

        foreach ($keys as $secret) {
            if (is_string($secret) && '' !== $secret) {
                return $secret;
            }
        }

        return '';
    }

    /**
     * Build a Site Health result array.
     *
     * @param string $status      One of good, recommended, critical.
     * @param string $label       Short result label.
     * @param string $description Sentence explaining the result. May hold HTML.
     * @param string $test        Test identifier.
     *
     * @return array<string,mixed>
     */
    private function result(string $status, string $label, string $description, string $test): array {
        return [
            'label'       => $label,
            'status'      => $status,
            'badge'       => [
                'label' => __('Telegram', 'ks-telegram'),
                'color' => 'blue',
            ],
            'description' => '<p>' . $description . '</p>',
            'actions'     => sprintf(
                '<p><a href="%s">%s</a></p>',
                esc_url(admin_url('options-general.php?page=ks-telegram')),
                esc_html__('Open KS Telegram settings', 'ks-telegram')
            ),
            'test'        => $test,
        ];
    }
}
