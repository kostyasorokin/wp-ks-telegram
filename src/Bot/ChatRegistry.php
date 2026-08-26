<?php
/**
 * Telegram chat registry.
 *
 * This file keeps a small admin-only list of recent Telegram chats discovered
 * from webhook payloads or getUpdates responses without storing message text.
 *
 * @author  Konstantin Sorokin
 * @link    https://konstantinsorokin.com
 * @package KS_Telegram
 */

declare(strict_types=1);

namespace KonstantinSorokin\Telegram\Bot;

defined('ABSPATH') || exit;

/**
 * Stores recent Telegram chat references for admin helpers.
 */
final class ChatRegistry {

    private const string OPTION_NAME = 'ks_telegram_recent_chats';
    private const int MAX_ITEMS = 25;

    /**
     * Get recent chat references.
     *
     * @return array<int,array{id:string,type:string,title:string,username:string,source:string,last_seen:int,update_id:int}>
     */
    public function recent(): array {
        $items = get_option(self::OPTION_NAME, []);
        $items = is_array($items) ? $items : [];

        return array_values(array_filter(array_map([$this, 'sanitizeRecord'], $items)));
    }

    /**
     * Clear stored chat references.
     *
     * @return void
     */
    public function clear(): void {
        delete_option(self::OPTION_NAME);
    }

    /**
     * Record a single webhook payload.
     *
     * @param array<string,mixed> $payload Telegram update payload.
     * @param string              $source  Discovery source.
     *
     * @return int Number of discovered chats.
     */
    public function recordUpdatePayload(array $payload, string $source = 'webhook'): int {
        return $this->recordUpdates([$payload], $source);
    }

    /**
     * Record multiple Telegram updates.
     *
     * @param array<int,mixed> $updates Telegram updates.
     * @param string           $source  Discovery source.
     *
     * @return int Number of discovered chats.
     */
    public function recordUpdates(array $updates, string $source = 'getUpdates'): int {
        $records = [];
        foreach ($this->recent() as $record) {
            $records[$record['id']] = $record;
        }

        $found = 0;
        foreach ($updates as $update) {
            if (! is_array($update)) {
                continue;
            }

            $chat = $this->chatFromUpdate($update);
            if (! is_array($chat)) {
                continue;
            }

            $record = $this->recordFromChat($chat, $update, $source);
            if (null === $record) {
                continue;
            }

            $records[$record['id']] = $record;
            ++$found;
        }

        uasort(
            $records,
            static fn (array $a, array $b): int => ($b['last_seen'] ?? 0) <=> ($a['last_seen'] ?? 0)
        );

        $this->persist(array_slice(array_values($records), 0, self::MAX_ITEMS));

        return $found;
    }

    /**
     * Build a normalized record from a Telegram chat object.
     *
     * @param array<string,mixed> $chat   Telegram chat data.
     * @param array<string,mixed> $update Telegram update data.
     * @param string              $source Discovery source.
     *
     * @return array{id:string,type:string,title:string,username:string,source:string,last_seen:int,update_id:int}|null
     */
    private function recordFromChat(array $chat, array $update, string $source): ?array {
        $id = sanitize_text_field((string) ($chat['id'] ?? ''));
        if ('' === $id || 1 !== preg_match('/^-?\d+$/', $id)) {
            return null;
        }

        $type     = sanitize_key((string) ($chat['type'] ?? 'unknown'));
        $username = ltrim(sanitize_text_field((string) ($chat['username'] ?? '')), '@');
        $title    = sanitize_text_field((string) ($chat['title'] ?? ''));

        if ('private' === $type) {
            $username = '';
            $title    = '';
        }

        return [
            'id'        => $id,
            'type'      => '' !== $type ? $type : 'unknown',
            'title'     => $this->limit($title),
            'username'  => $this->limit($username),
            'source'    => sanitize_key($source),
            'last_seen' => time(),
            'update_id' => absint($update['update_id'] ?? 0),
        ];
    }

    /**
     * Extract the chat object from a Telegram update.
     *
     * @param array<string,mixed> $update Telegram update data.
     *
     * @return array<string,mixed>|null
     */
    private function chatFromUpdate(array $update): ?array {
        foreach (['message', 'edited_message', 'channel_post', 'edited_channel_post'] as $key) {
            if (isset($update[$key]) && is_array($update[$key]) && isset($update[$key]['chat']) && is_array($update[$key]['chat'])) {
                return $update[$key]['chat'];
            }
        }

        if (isset($update['callback_query']) && is_array($update['callback_query'])) {
            $message = $update['callback_query']['message'] ?? null;
            if (is_array($message) && isset($message['chat']) && is_array($message['chat'])) {
                return $message['chat'];
            }
        }

        foreach (['my_chat_member', 'chat_member', 'chat_join_request', 'message_reaction', 'message_reaction_count'] as $key) {
            if (isset($update[$key]) && is_array($update[$key]) && isset($update[$key]['chat']) && is_array($update[$key]['chat'])) {
                return $update[$key]['chat'];
            }
        }

        return null;
    }

    /**
     * Sanitize a stored record.
     *
     * @param mixed $record Stored record.
     *
     * @return array{id:string,type:string,title:string,username:string,source:string,last_seen:int,update_id:int}|null
     */
    private function sanitizeRecord(mixed $record): ?array {
        if (! is_array($record)) {
            return null;
        }

        $id = sanitize_text_field((string) ($record['id'] ?? ''));
        if ('' === $id || 1 !== preg_match('/^-?\d+$/', $id)) {
            return null;
        }

        return [
            'id'        => $id,
            'type'      => sanitize_key((string) ($record['type'] ?? 'unknown')) ?: 'unknown',
            'title'     => $this->limit(sanitize_text_field((string) ($record['title'] ?? ''))),
            'username'  => $this->limit(sanitize_text_field((string) ($record['username'] ?? ''))),
            'source'    => sanitize_key((string) ($record['source'] ?? 'unknown')) ?: 'unknown',
            'last_seen' => absint($record['last_seen'] ?? 0),
            'update_id' => absint($record['update_id'] ?? 0),
        ];
    }

    /**
     * Store chat records without autoloading them on every request.
     *
     * @param array<int,array{id:string,type:string,title:string,username:string,source:string,last_seen:int,update_id:int}> $records Chat records.
     *
     * @return void
     */
    private function persist(array $records): void {
        if (false === get_option(self::OPTION_NAME, false)) {
            add_option(self::OPTION_NAME, $records, '', false);

            return;
        }

        update_option(self::OPTION_NAME, $records, false);
    }

    /**
     * Limit stored labels to a compact length.
     *
     * @param string $value Raw label.
     *
     * @return string
     */
    private function limit(string $value): string {
        return function_exists('mb_substr') ? mb_substr($value, 0, 120) : substr($value, 0, 120);
    }
}
