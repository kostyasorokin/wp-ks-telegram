<?php
/**
 * Telegram message sender service.
 *
 * This file exposes a reusable sending service for internal integrations and
 * other plugins that call the public KS Telegram API.
 *
 * @author  Konstantin Sorokin
 * @link    https://konstantinsorokin.com
 * @package KS_Telegram
 */

declare(strict_types=1);

namespace KonstantinSorokin\Telegram\Bot;

use KonstantinSorokin\Telegram\Bot\Enum\ParseMode;
use KonstantinSorokin\Telegram\Settings\SettingsRepository;

defined('ABSPATH') || exit;

/**
 * Sends messages to one or more Telegram chats.
 */
final readonly class MessageSender {

    private const int MAX_MESSAGE_LENGTH = 3900;

    /**
     * How many chunks one message may become.
     *
     * Each chunk is a blocking cURL call made inline in the request that caused
     * it, so anything a visitor can make long turned one HTTP request into
     * arbitrarily many outbound ones. Five chunks is ~19,500 characters.
     */
    private const int MAX_CHUNKS = 5;

    public function __construct(
        private SettingsRepository $settings,
        private TelegramApiClient $client
    ) {}

    /**
     * Send a message.
     *
     * @param string|int|array<int,string|int>|null $chat_ids Optional target chat IDs.
     * @param string                                $message  Message text.
     * @param array<string,mixed>                   $args     Additional Telegram sendMessage arguments.
     *
     * @return bool
     */
    public function send(string|int|array|null $chat_ids, string $message, array $args = []): bool {
        $message = trim($message);
        if ('' === $message) {
            return false;
        }

        $targets = $this->settings->parseChatIds($chat_ids);
        if ([] === $targets) {
            return false;
        }

        $args = $this->prepareArgs($args);
        $ok   = true;

        foreach ($targets as $chat_id) {
            /**
             * Filter outgoing message text before each chat delivery.
             *
             * @param string              $message Message text.
             * @param string              $chat_id Chat ID.
             * @param array<string,mixed> $args    Telegram sendMessage arguments.
             */
            $text = (string) apply_filters('ks_telegram_message_text', $message, $chat_id, $args);

            foreach ($this->splitMessage($text, (string) ($args['parse_mode'] ?? 'None')) as $chunk) {
                /**
                 * Filter Telegram sendMessage arguments before delivery.
                 *
                 * @param array<string,mixed> $args    Telegram sendMessage arguments.
                 * @param string              $chat_id Chat ID.
                 * @param string              $chunk   Message chunk.
                 */
                $send_args = (array) apply_filters('ks_telegram_send_args', $args, $chat_id, $chunk);
                $result    = $this->client->sendMessage($chat_id, $chunk, $send_args);
                $ok        = ! is_wp_error($result) && $ok;

                do_action('ks_telegram_message_sent', $chat_id, $chunk, $send_args, $result);

                /*
                 * No break on failure. It was tried and abandoned: a chunk can
                 * be refused on its own merits (entity parsing, length) while
                 * the rest would deliver, and stopping threw away content the
                 * reader needed. MAX_CHUNKS already bounds the fan-out.
                 */
            }
        }

        return $ok;
    }

    /**
     * Prepare Telegram sendMessage arguments.
     *
     * @param array<string,mixed> $args Raw arguments.
     *
     * @return array<string,mixed>
     */
    private function prepareArgs(array $args): array {
        $parse_mode = $args['parse_mode'] ?? $this->settings->string('parse_mode', 'MarkdownV2');
        unset($args['parse_mode']);

        if ('None' !== $parse_mode) {
            $args['parse_mode'] = (string) $parse_mode;
        }

        $args['disable_web_page_preview'] = isset($args['disable_web_page_preview'])
            ? (bool) $args['disable_web_page_preview']
            : $this->settings->bool('disable_web_page_preview', true);

        $args['disable_notification'] = isset($args['disable_notification'])
            ? (bool) $args['disable_notification']
            : $this->settings->bool('silent_notifications');

        return $args;
    }

    /**
     * Split long Telegram messages into safe chunks.
     *
     * @param string $message    Message text.
     * @param string $parse_mode Active parse mode, for escaping the cap marker.
     *
     * @return array<int,string>
     */
    private function splitMessage(string $message, string $parse_mode = 'None'): array {
        if (function_exists('mb_strlen') && mb_strlen($message) <= self::MAX_MESSAGE_LENGTH) {
            return [$message];
        }

        if (! function_exists('mb_substr')) {
            return $this->capChunks(str_split($message, self::MAX_MESSAGE_LENGTH), $parse_mode);
        }

        $chunks = [];
        while (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
            $chunks[] = mb_substr($message, 0, self::MAX_MESSAGE_LENGTH);
            $message  = mb_substr($message, self::MAX_MESSAGE_LENGTH);
        }

        if ('' !== $message) {
            $chunks[] = $message;
        }

        return $this->capChunks($chunks, $parse_mode);
    }

    /**
     * Bound the number of chunks one message may become.
     *
     * @param array<int,string> $chunks     Message chunks.
     * @param string            $parse_mode Active parse mode.
     *
     * @return array<int,string>
     */
    private function capChunks(array $chunks, string $parse_mode = 'None'): array {
        if (count($chunks) <= self::MAX_CHUNKS) {
            return $chunks;
        }

        $dropped = count($chunks) - self::MAX_CHUNKS;
        $chunks  = array_slice($chunks, 0, self::MAX_CHUNKS);

        $marker = sprintf(
            /* translators: %d: number of message parts that were not sent. */
            _n('(message truncated, %d part omitted)', '(message truncated, %d parts omitted)', $dropped, 'ks-telegram'),
            $dropped
        );

        /*
         * The marker must be escaped like any other text. Appended raw it broke
         * the chunk it was added to: under MarkdownV2 the brackets and the full
         * stop are reserved, so Telegram rejected the whole message — the one
         * part the reader most needed, on default settings.
         */
        $mode = ParseMode::tryFrom($parse_mode);
        if ($mode instanceof ParseMode && ParseMode::None !== $mode) {
            $marker = (new Formatter($mode))->esc($marker);
        }

        $chunks[self::MAX_CHUNKS - 1] .= "\n\n" . $marker;

        return $chunks;
    }
}
