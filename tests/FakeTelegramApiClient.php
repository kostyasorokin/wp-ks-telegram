<?php
/** Test double for the Telegram API client. */

declare(strict_types=1);

namespace KonstantinSorokin\Telegram\Bot;

use WP_Error;

final class TelegramApiClient {
    /** @var array<int,array{chat_id:string,text:string,args:array<string,mixed>}> */
    public array $sent = [];

    /** @var array<int,array{chat_id:string,photo:string,caption:string,args:array<string,mixed>}> */
    public array $photos = [];

    public bool $fail = false;

    public int $chatRequests = 0;

    /** @var array<string,mixed>|WP_Error */
    public array|WP_Error $chatResult = ['ok' => true, 'result' => ['id' => -1001234567890, 'type' => 'channel']];

    /** @param array<string,mixed> $args */
    public function request(string $method, array $args = []): array|WP_Error {
        if ('sendPhoto' === $method) {
            $this->photos[] = [
                'chat_id' => (string) ($args['chat_id'] ?? ''),
                'photo'   => (string) ($args['photo'] ?? ''),
                'caption' => (string) ($args['caption'] ?? ''),
                'args'    => $args,
            ];
            return $this->fail ? new WP_Error('telegram_failed') : ['ok' => true];
        }

        ++$this->chatRequests;
        return $this->chatResult;
    }

    public function sendMessage(string|int $chat_id, string $text, array $args = []): array|WP_Error {
        $this->sent[] = ['chat_id' => (string) $chat_id, 'text' => $text, 'args' => $args];
        return $this->fail ? new WP_Error('telegram_failed') : ['ok' => true];
    }

    public function sendPhoto(string|int $chat_id, string $photo, string $caption = '', array $args = []): array|WP_Error {
        return $this->request('sendPhoto', array_merge($args, ['chat_id' => $chat_id, 'photo' => $photo, 'caption' => $caption]));
    }
}
