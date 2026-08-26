<?php
/**
 * Request context helper.
 *
 * This file collects sanitized request metadata that can be included in
 * notifications when the administrator enables that option.
 *
 * @author  Konstantin Sorokin
 * @link    https://konstantinsorokin.com
 * @package KS_Telegram
 */

declare(strict_types=1);

namespace KonstantinSorokin\Telegram\Support;

use KonstantinSorokin\Telegram\Bot\Formatter;

defined('ABSPATH') || exit;

/**
 * Builds request context message blocks.
 */
final readonly class RequestContext {

    /**
     * Format request context for Telegram.
     *
     * @param Formatter $formatter Message formatter.
     *
     * @return string
     */
    public function toMessage(Formatter $formatter): string {
        $lines = [
            $formatter->line('IP', $this->ip()),
            $formatter->line('User agent', $this->userAgent()),
            $formatter->line('Referer', $this->referer()),
        ];

        return implode('', array_filter($lines));
    }

    /**
     * Get a sanitized client IP address.
     *
     * @return string
     */
    private function ip(): string {
        /*
         * Sanitized here, not in validIp(): the rule is about the point a
         * superglobal is read, and an analyser cannot follow it into a helper.
         */
        $observed = isset($_SERVER['REMOTE_ADDR'])
            ? $this->validIp(sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])))
            : '';

        /**
         * Filter the client IP reported in notifications.
         *
         * `REMOTE_ADDR` is the only address the site observes rather than is
         * told. Forwarding headers used to be preferred over it — first
         * `CF-Connecting-IP`, then `X-Forwarded-For`, then `Client-IP` — with
         * no check that the site sits behind a proxy at all, so any visitor
         * could send `X-Forwarded-For: 1.2.3.4` and choose the address printed
         * in the "failed login" alert. That is the wrong way round: these
         * headers are trustworthy only when a proxy the owner controls is
         * known to overwrite them.
         *
         * A site genuinely behind Cloudflare or a load balancer should read the
         * appropriate header here, having satisfied itself that `REMOTE_ADDR`
         * is the proxy. The observed address is passed along so the filter can
         * make that decision.
         *
         * @param string $ip       Address to report.
         * @param string $observed REMOTE_ADDR, as the server saw it.
         */
        $ip = (string) apply_filters('ks_telegram_client_ip', $observed, $observed);

        return $this->validIp($ip);
    }

    /**
     * Validate an IP address.
     *
     * Validation only: whatever reaches this has already been unslashed and
     * sanitized at the point it was read, and a filter's return value is a
     * plugin author's own string rather than request input.
     *
     * @param mixed $value Candidate address.
     *
     * @return string Empty string when the value is not an IP address.
     */
    private function validIp(mixed $value): string {
        if (! is_string($value) && ! is_numeric($value)) {
            return '';
        }

        $value = trim((string) $value);

        return filter_var($value, FILTER_VALIDATE_IP) ? $value : '';
    }

    /**
     * Get a sanitized user agent.
     *
     * @return string
     */
    private function userAgent(): string {
        return isset($_SERVER['HTTP_USER_AGENT'])
            ? sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_USER_AGENT']))
            : '';
    }

    /**
     * Get a sanitized referer URL.
     *
     * @return string
     */
    private function referer(): string {
        return isset($_SERVER['HTTP_REFERER'])
            ? esc_url_raw(wp_unslash((string) $_SERVER['HTTP_REFERER']))
            : '';
    }
}
