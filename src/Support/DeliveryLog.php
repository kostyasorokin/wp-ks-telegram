<?php
/**
 * Delivery outcome log.
 *
 * This file records the outcome of every Telegram delivery so a failure leaves
 * something behind to look at, which is the one thing the send path never did.
 *
 * @author  Konstantin Sorokin
 * @link    https://konstantinsorokin.com
 * @package KS_Telegram
 */

declare(strict_types=1);

namespace KonstantinSorokin\Telegram\Support;

use KonstantinSorokin\Telegram\Settings\SettingsRepository;
use WP_Error;

defined('ABSPATH') || exit;

/**
 * Keeps a short history of failed deliveries and the last successful one.
 *
 * The send path builds a WP_Error carrying Telegram's own words and then
 * collapses it to a bool that every caller discards. This listens on the
 * long-unused `ks_telegram_message_sent` hook, so no new seam.
 */
final readonly class DeliveryLog {

    /**
     * Option holding the log. Not autoloaded: it is read on the settings screen
     * and by Site Health, never on a front-end request.
     */
    public const string OPTION_NAME = 'ks_telegram_delivery_log';

    /**
     * How many failures are kept. The option is written on every failure, so
     * this bounds both the row size and the cost of a bad afternoon.
     */
    private const int MAX_FAILURES = 20;

    /**
     * Successes are frequent and identical, so only the newest is stored, and
     * only when the previous one is older than this. Without the throttle a
     * busy site pays a database write per delivered message for a timestamp
     * nobody reads at that resolution.
     */
    private const int SUCCESS_THROTTLE = 300;

    public function __construct(private SettingsRepository $settings) {}

    /**
     * Register the listener.
     *
     * @return void
     */
    public function boot(): void {
        if (! $this->settings->bool('delivery_log_enabled', true)) {
            return;
        }

        add_action('ks_telegram_message_sent', [$this, 'record'], 10, 4);
    }

    /**
     * Record one delivery outcome.
     *
     * @param string|int          $chat_id Target chat ID.
     * @param string              $chunk   Message chunk that was sent.
     * @param array<string,mixed> $args    Telegram sendMessage arguments.
     * @param mixed               $result  Raw API result or WP_Error.
     *
     * @return void
     */
    public function record(string|int $chat_id, string $chunk, array $args, mixed $result): void {
        $log = $this->read();
        $now = time();

        if (! $result instanceof WP_Error) {
            /*
             * The throttle is skipped while the log still reads as failing,
             * otherwise a recovery inside the window left Site Health and the
             * settings screen claiming delivery was broken for five minutes
             * after it had come back.
             */
            $failing = (int) ($log['last_failure'] ?? 0) > (int) ($log['last_ok'] ?? 0);

            if (! $failing && $now - (int) ($log['last_ok'] ?? 0) < self::SUCCESS_THROTTLE) {
                return;
            }

            $log['last_ok'] = $now;
            $this->write($log);

            return;
        }

        $failures = is_array($log['failures'] ?? null) ? $log['failures'] : [];

        array_unshift(
            $failures,
            [
                'time'    => $now,
                'chat_id' => (string) $chat_id,
                'code'    => sanitize_text_field((string) $result->get_error_code()),
                // Telegram's own words. This is the whole point of the log:
                // "chat not found" and "bot was blocked" are different problems
                // with different fixes, and both used to arrive as `false`.
                'message' => sanitize_text_field((string) $result->get_error_message()),
                'status'  => absint(($result->get_error_data() ?: [])['status'] ?? 0),
                /*
                 * The body is not stored, only its size: a notification can carry a form
                 * submission or, with password alerts on, a password. The chat, the code
                 * and Telegram's words identify a failure; length answers "too long".
                 */
                'length'  => mb_strlen($chunk),
            ]
        );

        $log['failures']     = array_slice($failures, 0, self::MAX_FAILURES);
        $log['last_failure'] = $now;

        $this->write($log);
    }

    /**
     * Get the recorded failures, newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function failures(): array {
        $failures = $this->read()['failures'] ?? [];

        return is_array($failures) ? $failures : [];
    }

    /**
     * Get the timestamp of the last successful delivery.
     *
     * @return int Unix timestamp, or 0 when nothing has ever been delivered.
     */
    public function lastSuccess(): int {
        return (int) ($this->read()['last_ok'] ?? 0);
    }

    /**
     * Get the timestamp of the last failure.
     *
     * @return int Unix timestamp, or 0 when nothing has ever failed.
     */
    public function lastFailure(): int {
        return (int) ($this->read()['last_failure'] ?? 0);
    }

    /**
     * Check whether the newest event on record is a failure.
     *
     * Deliberately not "are there any failures": one refused message weeks ago
     * says nothing about now, and a log that cries about it forever gets
     * ignored. What matters is whether anything has got through since.
     *
     * @return bool
     */
    public function isFailing(): bool {
        $failure = $this->lastFailure();

        return 0 !== $failure && $failure > $this->lastSuccess();
    }

    /**
     * Forget everything recorded.
     *
     * @return void
     */
    public function clear(): void {
        delete_option(self::OPTION_NAME);
    }

    /**
     * Read the stored log.
     *
     * @return array<string,mixed>
     */
    private function read(): array {
        $log = get_option(self::OPTION_NAME, []);

        return is_array($log) ? $log : [];
    }

    /**
     * Persist the log.
     *
     * @param array<string,mixed> $log Log payload.
     *
     * @return void
     */
    private function write(array $log): void {
        // The third argument is deprecated and the fourth is autoload; passing
        // '' keeps the signature valid while `false` keeps this row out of the
        // alloptions cache that every front-end request loads.
        if (false === get_option(self::OPTION_NAME, false)) {
            add_option(self::OPTION_NAME, $log, '', false);

            return;
        }

        update_option(self::OPTION_NAME, $log, false);
    }
}
