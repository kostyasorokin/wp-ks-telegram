<?php
/**
 * Telegram message formatter.
 *
 * This file contains small helpers for MarkdownV2-safe Telegram messages and
 * consistent notification headers.
 *
 * @author  Konstantin Sorokin
 * @link    https://konstantinsorokin.com
 * @package KS_Telegram
 */

declare(strict_types=1);

namespace KonstantinSorokin\Telegram\Bot;

use KonstantinSorokin\Telegram\Bot\Enum\ParseMode;

defined('ABSPATH') || exit;

/**
 * Formats text for Telegram messages.
 */
final readonly class Formatter {

    /**
     * Header component defaults.
     *
     * @var array<string,bool>
     */
    private const array HEADER_DEFAULTS = [
        'date'         => true,
        'time'         => true,
        'type_hashtag' => true,
        'site_hashtag' => true,
    ];

    /**
     * Internal notification type to public hashtag map.
     *
     * @var array<string,string>
     */
    private const array HASHTAGS = [
        'admin_login'             => 'AdminLogin',
        'application_password'    => 'ApplicationPassword',
        'authorization'           => 'Authorization',
        'comment'                 => 'Comment',
        'contact_form_7'          => 'ContactForm7',
        'content'                 => 'Content',
        'failed_login'            => 'FailedLogin',
        'mailchimp_subscribe'     => 'MailchimpSubscribe',
        'mailchimp_unsubscribe'   => 'MailchimpUnsubscribe',
        'media'                   => 'Media',
        'password_reset'          => 'PasswordReset',
        'post'                    => 'Post',
        'recovery_mode'           => 'RecoveryMode',
        'system'                  => 'System',
        'telegram'                => 'Telegram',
        'user'                    => 'User',
        'user_registration'       => 'UserRegistration',
        'wc_add_to_cart'          => 'AddToCart',
        'wc_low_stock'            => 'LowStock',
        'wc_order'                => 'Order',
        'wc_order_status'         => 'OrderStatus',
        'wp_mail_failed'          => 'MailFailed',
    ];

    private ParseMode $parseMode;

    /**
     * Enabled header components.
     *
     * @var array<string,bool>
     */
    private array $headerOptions;

    /**
     * @param ParseMode          $parseMode     Telegram parse mode.
     * @param array<string,bool> $headerOptions Enabled header components.
     */
    public function __construct(
        ParseMode $parseMode = ParseMode::MarkdownV2,
        array $headerOptions = []
    ) {
        $this->parseMode     = $parseMode;
        $this->headerOptions = $this->normalizeHeaderOptions($headerOptions);
    }

    /**
     * Escape text for the configured parse mode.
     *
     * @param string $text Raw text.
     *
     * @return string
     */
    public function esc(string $text): string {
        $text = $this->plainText($text);

        return match ($this->parseMode) {
            ParseMode::MarkdownV2 => $this->escapeMarkdownV2($text),
            ParseMode::Markdown => $this->escapeMarkdown($text),
            ParseMode::Html => $this->escapeHtml($text),
            ParseMode::None => $text,
        };
    }

    /**
     * Format text as bold.
     *
     * @param string $text Already escaped text.
     *
     * @return string
     */
    public function bold(string $text): string {
        return match ($this->parseMode) {
            ParseMode::MarkdownV2, ParseMode::Markdown => '*' . $text . '*',
            ParseMode::Html => '<b>' . $text . '</b>',
            ParseMode::None => $text,
        };
    }

    /**
     * Format text as inline code.
     *
     * @param string $text Raw text.
     *
     * @return string
     */
    public function code(string $text): string {
        $text = $this->plainText($text);

        return match ($this->parseMode) {
            ParseMode::MarkdownV2, ParseMode::Markdown => '`' . $this->escapeInlineCode($text) . '`',
            ParseMode::Html => '<code>' . $this->escapeHtml($text) . '</code>',
            ParseMode::None => $text,
        };
    }

    /**
     * Format a link.
     *
     * @param string $label Link label.
     * @param string $url   Link URL.
     *
     * @return string
     */
    public function link(string $label, string $url): string {
        $label = $this->esc($label);
        $url   = esc_url_raw($url);

        return match ($this->parseMode) {
            ParseMode::MarkdownV2, ParseMode::Markdown => '[' . $label . '](' . $this->escapeLinkUrl($url) . ')',
            ParseMode::Html => '<a href="' . esc_url($url) . '">' . $label . '</a>',
            ParseMode::None => $label . ': ' . $url,
        };
    }

    /**
     * Build a notification header.
     *
     * @param string $type Notification type.
     *
     * @return string
     */
    public function header(string $type): string {
        $parts = $this->headerParts($type);

        return [] === $parts ? '' : implode(' ', array_map(fn (string $part): string => $this->esc($part), $parts)) . "\n\n";
    }

    /**
     * Build a labeled line.
     *
     * @param string          $label Label.
     * @param string|int|null $value Value.
     *
     * @return string
     */
    public function line(string $label, string|int|null $value): string {
        $value = $this->lineValue($value);

        return '' !== $value ? $this->esc($label . ': ' . $value) . "\n" : '';
    }

    /**
     * Build enabled header parts before parse-mode escaping.
     *
     * @param string $type Notification type.
     *
     * @return array<int,string>
     */
    private function headerParts(string $type): array {
        $parts = [];

        if ($this->headerOption('date')) {
            $parts[] = wp_date('Y-m-d');
        }

        if ($this->headerOption('time')) {
            $parts[] = wp_date('H:i:s');
        }

        if ($this->headerOption('type_hashtag')) {
            $parts[] = '#' . $this->hashtag($type);
        }

        if ($this->headerOption('site_hashtag')) {
            $parts[] = '#' . $this->siteHashtag();
        }

        return $parts;
    }

    /**
     * Check whether a header component is enabled.
     *
     * @param string $key Header option key.
     *
     * @return bool
     */
    private function headerOption(string $key): bool {
        return $this->headerOptions[$key] ?? self::HEADER_DEFAULTS[$key] ?? true;
    }

    /**
     * Normalize header settings to known boolean keys.
     *
     * @param array<string,bool> $options Raw header options.
     *
     * @return array<string,bool>
     */
    private function normalizeHeaderOptions(array $options): array {
        $normalized = self::HEADER_DEFAULTS;

        foreach ($normalized as $key => $default) {
            $normalized[$key] = isset($options[$key]) ? (bool) $options[$key] : $default;
        }

        return $normalized;
    }

    /**
     * Map internal notification types to hashtags.
     *
     * @param string $type Notification type.
     *
     * @return string
     */
    private function hashtag(string $type): string {
        $type = sanitize_key($type);

        if (isset(self::HASHTAGS[$type])) {
            return self::HASHTAGS[$type];
        }

        $hashtag = str_replace(['-', '_'], ' ', $type);
        $hashtag = str_replace(' ', '', ucwords($hashtag));
        $hashtag = (string) preg_replace('/[^A-Za-z0-9]/', '', $hashtag);

        return '' !== $hashtag ? $hashtag : 'Telegram';
    }

    /**
     * Build a safe site hashtag.
     *
     * @return string
     */
    private function siteHashtag(): string {
        $site = (string) wp_parse_url(home_url(), PHP_URL_HOST);

        return preg_replace('/[^A-Za-z0-9]/', '', $site) ?: 'WordPress';
    }

    /**
     * Strip HTML while preserving user-entered plain text.
     *
     * @param string $text Raw text.
     *
     * @return string
     */
    private function plainText(string $text): string {
        return wp_strip_all_tags($text);
    }

    /**
     * Normalize optional line values.
     *
     * @param string|int|null $value Raw value.
     *
     * @return string
     */
    private function lineValue(string|int|null $value): string {
        return null === $value ? '' : trim((string) $value);
    }

    /**
     * Escape MarkdownV2 control characters.
     *
     * @param string $text Plain text.
     *
     * @return string
     */
    private function escapeMarkdownV2(string $text): string {
        return (string) preg_replace('/([\\\\_*\[\]()~`>#+\-=|{}.!])/u', '\\\\$1', $text);
    }

    /**
     * Escape legacy Telegram Markdown control characters.
     *
     * @param string $text Plain text.
     *
     * @return string
     */
    private function escapeMarkdown(string $text): string {
        return strtr(
            $text,
            [
                '\\' => '\\\\',
                '*'  => '\*',
                '_'  => '\_',
                '`'  => '\`',
                '['  => '\[',
            ]
        );
    }

    /**
     * Escape plain text for HTML parse mode.
     *
     * @param string $text Plain text.
     *
     * @return string
     */
    private function escapeHtml(string $text): string {
        return esc_html($text);
    }

    /**
     * Escape text inside Markdown inline-code entities.
     *
     * @param string $text Plain text.
     *
     * @return string
     */
    private function escapeInlineCode(string $text): string {
        return str_replace(['\\', '`'], ['\\\\', '\`'], $text);
    }

    /**
     * Escape URL pieces that can close Markdown links.
     *
     * @param string $url Sanitized URL.
     *
     * @return string
     */
    private function escapeLinkUrl(string $url): string {
        return str_replace(['\\', ')'], ['\\\\', '\)'], $url);
    }
}
