<?php
/** Test double for the Telegram API client. */

declare(strict_types=1);

namespace KonstantinSorokin\Telegram\Bot;

use WP_Error;

final class TelegramApiClient {
    /** @var array<int,array{chat_id:string,text:string,args:array<string,mixed>}> */
    public array $sent = [];

    public bool $fail = false;

    public function sendMessage(string|int $chat_id, string $text, array $args = []): array|WP_Error {
        $this->sent[] = ['chat_id' => (string) $chat_id, 'text' => $text, 'args' => $args];
        return $this->fail ? new WP_Error('telegram_failed') : ['ok' => true];
    }
}
