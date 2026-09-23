<?php
/**
 * Run with: php tests/channel-publisher-smoke.php
 *
 * Isolated WordPress hook and settings checks; no database or Telegram access.
 */

declare(strict_types=1);

define('ABSPATH', __DIR__);

$options = [];
$events = [];
$news_registered = false;
$posts = [];
$meta = [];
$permalinks = [];
$excerpts = [];
$photos = [];
$transients = [];

define('DAY_IN_SECONDS', 86400);
define('MINUTE_IN_SECONDS', 60);

class WP_Error {
    public function __construct(public string $code) {}
}

class WP_Post {
    public function __construct(
        public int $ID,
        public string $post_type,
        public string $post_status = 'publish',
        public string $post_password = '',
        public string $post_title = 'Test title'
    ) {}
}

function get_option(string $key, mixed $default = false): mixed {
    global $options;
    return $options[$key] ?? $default;
}

function get_transient(string $key): mixed {
    global $transients;
    return $transients[$key] ?? false;
}

function set_transient(string $key, mixed $value, int $expiration): bool {
    global $transients;
    $transients[$key] = $value;
    return true;
}

function add_option(string $key, mixed $value): bool {
    global $options;
    if (array_key_exists($key, $options)) {
        return false;
    }
    $options[$key] = $value;
    return true;
}

function delete_option(string $key): bool {
    global $options;
    unset($options[$key]);
    return true;
}

function get_post(int $post_id): ?WP_Post {
    global $posts;
    return $posts[$post_id] ?? null;
}

function get_post_meta(int $post_id, string $key): mixed {
    global $meta;
    return $meta[$post_id][$key] ?? '';
}

function update_post_meta(int $post_id, string $key, mixed $value): void {
    global $meta;
    $meta[$post_id][$key] = $value;
}

function delete_post_meta(int $post_id, string $key): void {
    global $meta;
    unset($meta[$post_id][$key]);
}

function get_permalink(WP_Post $post): string {
    global $permalinks;
    return $permalinks[$post->ID] ?? 'https://example.test/?p=' . $post->ID;
}

function home_url(string $path): string {
    return 'https://example.test' . $path;
}

function wp_parse_url(string $url, int $component): string|false|null {
    return parse_url($url, $component);
}

function get_the_title(WP_Post $post): string {
    return $post->post_title;
}

function get_the_excerpt(WP_Post $post): string {
    global $excerpts;
    return $excerpts[$post->ID] ?? '';
}

function get_the_post_thumbnail_url(WP_Post $post, string $size): string|false {
    global $photos;
    return $photos[$post->ID] ?? false;
}

function wp_strip_all_tags(string $text): string {
    return strip_tags($text);
}

function esc_html(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function esc_url(string $url): string {
    return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function wp_html_excerpt(string $text, int $length, string $more): string {
    return mb_strlen($text) > $length ? mb_substr($text, 0, $length) . $more : $text;
}

function apply_filters(string $hook, mixed $value): mixed {
    return $value;
}

function do_action(string $hook, mixed ...$args): void {}

function is_wp_error(mixed $value): bool {
    return $value instanceof WP_Error;
}

function wp_generate_password(): string {
    return str_repeat('a', 32);
}

function wp_unslash(mixed $value): mixed {
    return $value;
}

function sanitize_text_field(string $value): string {
    return $value;
}

function sanitize_key(string $value): string {
    return $value;
}

function absint(mixed $value): int {
    return abs((int) $value);
}

function post_type_exists(string $post_type): bool {
    global $news_registered;
    return ('news' === $post_type && $news_registered) || in_array($post_type, ['ks_case', 'internal_type'], true);
}

function get_post_types(array $args = [], string $output = 'names'): array {
    global $news_registered;
    $types = [
        'ks_case' => (object) ['name' => 'ks_case', 'label' => 'Cases', '_builtin' => false, 'public' => true, 'publicly_queryable' => true, 'show_ui' => true],
        'internal_type' => (object) ['name' => 'internal_type', 'label' => 'Internal', '_builtin' => false, 'public' => false, 'publicly_queryable' => false, 'show_ui' => true],
    ];
    if ($news_registered) {
        $types['news'] = (object) ['name' => 'news', 'label' => 'News', '_builtin' => false, 'public' => true, 'publicly_queryable' => true, 'show_ui' => true];
    }

    return array_filter($types, static function ($type) use ($args): bool {
        foreach ($args as $key => $value) {
            if ($type->{$key} !== $value) {
                return false;
            }
        }
        return true;
    });
}

function wp_is_post_revision(int $post_id): bool {
    return false;
}

function wp_is_post_autosave(int $post_id): bool {
    return false;
}

function wp_next_scheduled(string $hook, array $args): int|false {
    global $events;
    foreach ($events as $event) {
        if ($event['hook'] === $hook && $event['args'] === $args) {
            return $event['time'];
        }
    }
    return false;
}

function wp_schedule_single_event(int $time, string $hook, array $args): bool {
    global $events;
    $events[] = compact('time', 'hook', 'args');
    return true;
}

function check(bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

require dirname(__DIR__) . '/src/Support/WebhookSecret.php';
require dirname(__DIR__) . '/src/Settings/SettingsRepository.php';
require __DIR__ . '/FakeTelegramApiClient.php';
require dirname(__DIR__) . '/src/Bot/MessageSender.php';
require dirname(__DIR__) . '/src/Publishing/ChannelPublisher.php';
require dirname(__DIR__) . '/src/Bot/ChatRegistry.php';
require dirname(__DIR__) . '/src/Support/DeliveryLog.php';
require dirname(__DIR__) . '/src/Admin/SettingsPage.php';

$settings = new \KonstantinSorokin\Telegram\Settings\SettingsRepository();
$options['ks_telegram'] = ['channel_publish_news' => true];

$clean = $settings->sanitize([
    'channel_chat_id' => '@publicchannel',
    'channel_publish_posts' => '1',
    'channel_publish_news' => '1',
]);
check('@publicchannel' === $clean['channel_chat_id'], 'One channel username is accepted.');
check(true === $clean['channel_publish_news'], 'Hidden KS News choice is preserved.');
check('' === $settings->sanitize(['channel_chat_id' => '@one @two'])['channel_chat_id'], 'Multiple destinations are rejected.');
check(['ks_case'] === $settings->sanitize(['channel_publish_custom_types' => ['ks_case', 'internal_type', 'unknown']])['channel_publish_custom_types'], 'Only public custom post types can be selected.');
check(false === $settings->sanitize([])['channel_silent_publish'], 'Silent channel publishing is off unless selected.');
check(true === $settings->sanitize(['channel_silent_publish' => '1'])['channel_silent_publish'], 'Silent channel publishing can be selected.');

$options['ks_telegram'] = [
    'bot_token' => 'test-token',
    'default_chat_ids' => '-100123456',
    'channel_chat_id' => '@publicchannel',
    'channel_publish_posts' => true,
    'channel_publish_pages' => false,
    'channel_publish_news' => true,
    'silent_notifications' => true,
];

$client = new \KonstantinSorokin\Telegram\Bot\TelegramApiClient();
$sender = new \KonstantinSorokin\Telegram\Bot\MessageSender($settings, $client);
$publisher = new \KonstantinSorokin\Telegram\Publishing\ChannelPublisher($settings, $sender, $client);

$publisher->postStatusChanged('publish', 'draft', new WP_Post(12, 'post'));
check(1 === count($events) && $events[0]['args'] === [12, '@publicchannel', 0], 'A first post publication queues only the channel destination.');

$publisher->postStatusChanged('publish', 'publish', new WP_Post(12, 'post'));
$publisher->postStatusChanged('publish', 'draft', new WP_Post(13, 'page'));
$publisher->postStatusChanged('publish', 'draft', new WP_Post(14, 'post', 'publish', 'secret'));
$publisher->postStatusChanged('publish', 'draft', new WP_Post(15, 'news'));
check(1 === count($events), 'Edits, disabled pages, protected posts, and unavailable news do not queue.');

define('KS_NEWS_FILE', '/test/ks-news.php');
define('KS_NEWS_POST_TYPE', 'news');
$news_registered = true;
$publisher->postStatusChanged('publish', 'draft', new WP_Post(15, 'news'));
check(2 === count($events) && $events[1]['args'] === [15, '@publicchannel', 0], 'Active KS News can queue separately.');

$options['ks_telegram']['channel_chat_id'] = '';
$publisher->postStatusChanged('publish', 'draft', new WP_Post(16, 'post'));
check(2 === count($events), 'A missing channel never falls back to private alert chats.');

$options['ks_telegram']['channel_chat_id'] = '@publicchannel';
$posts[12] = new WP_Post(12, 'post', 'publish', '', '<b>Public &amp; "title"</b>');
$permalinks[12] = 'https://example.test/?p=12&ref=news';
$publisher->deliver(12, '@publicchannel', 0);
check(1 === count($client->sent), 'The first queued delivery sends exactly once.');
check('@publicchannel' === $client->sent[0]['chat_id'], 'Delivery targets the configured public channel.');
check('<a href="https://example.test/?p=12&amp;ref=news">Public &amp; &quot;title&quot;</a>' === $client->sent[0]['text'], 'Delivery links the escaped title to the local permalink.');
check('HTML' === $client->sent[0]['args']['parse_mode'], 'The public message uses Telegram HTML.');
check(true === $client->sent[0]['args']['disable_web_page_preview'], 'Legacy link previews are disabled.');
check(['is_disabled' => true] === $client->sent[0]['args']['link_preview_options'], 'Modern link previews are disabled.');
check(false === $client->sent[0]['args']['disable_notification'], 'Channel posts are not silent just because administrator notifications are silent.');
$publisher->deliver(12, '@publicchannel', 0);
check(1 === count($client->sent), 'A successful delivery is not repeated.');

$posts[17] = new WP_Post(17, 'post');
$client->fail = true;
$publisher->deliver(17, '@publicchannel', 0);
check(3 === count($events) && $events[2]['args'] === [17, '@publicchannel', 1], 'Failed delivery queues a bounded retry.');
$publisher->deliver(17, '@publicchannel', 1);
$publisher->deliver(17, '@publicchannel', 2);
check(4 === count($client->sent), 'At most three delivery attempts are made.');
check(4 === count($events), 'No retry is queued after the third failure.');

$posts[18] = new WP_Post(18, 'post', 'draft');
$publisher->deliver(18, '@publicchannel', 0);
$publisher->deliver(17, '@differentchannel', 0);
check(4 === count($client->sent), 'Unpublished posts and stale channel destinations are never sent.');

$client->fail = false;
$posts[19] = new WP_Post(19, 'post');
$permalinks[19] = 'https://external.test/?p=19';
$publisher->deliver(19, '@publicchannel', 0);
check(4 === count($client->sent), 'A filtered off-site permalink is rejected.');

$posts[20] = new WP_Post(20, 'post');
$options['ks_telegram']['channel_publish_posts'] = false;
$publisher->deliver(20, '@publicchannel', 0);
check(4 === count($client->sent), 'Disabling a content type cancels pending delivery.');

$options['ks_telegram']['channel_publish_custom_types'] = ['ks_case', 'internal_type'];
check(['ks_case', 'news'] === $settings->selectedCustomPostTypes(), 'Custom cases and legacy News are selected, but internal types are excluded.');
$publisher->postStatusChanged('publish', 'draft', new WP_Post(21, 'ks_case'));
check(5 === count($events) && [21, '@publicchannel', 0] === $events[4]['args'], 'New cases can be queued when selected.');
$publisher->postStatusChanged('publish', 'draft', new WP_Post(22, 'internal_type'));
check(5 === count($events), 'Non-public types cannot be queued even if stored in settings.');

$options['ks_telegram']['channel_silent_publish'] = true;
$options['ks_telegram']['silent_notifications'] = false;
$posts[23] = new WP_Post(23, 'ks_case');
$publisher->deliver(23, '@publicchannel', 0);
check(5 === count($client->sent), 'A newly published case is sent to the channel.');
check(true === $client->sent[4]['args']['disable_notification'], 'Channel silence is independent of administrator notification settings.');

$formats = $settings->sanitize([
    'channel_post_formats' => [
        'post' => ['_present' => '1', 'description' => '1', 'permalink' => '1'],
        'internal_type' => ['_present' => '1', 'image' => '1'],
    ],
]);
check(false === $formats['channel_post_formats']['post']['title'] && true === $formats['channel_post_formats']['post']['description'], 'Per-type format checkboxes are sanitized independently.');
check(! isset($formats['channel_post_formats']['internal_type']), 'Non-public post type formats cannot be saved.');
check(true === $settings->channelFormat('ks_case')['linked_title'], 'Unconfigured types retain the linked-title format.');

$options['ks_telegram']['channel_post_formats']['news'] = [
    'image' => true,
    'title' => true,
    'linked_title' => false,
    'description' => true,
    'permalink' => true,
    'disable_preview' => false,
];
$posts[24] = new WP_Post(24, 'news', 'publish', '', 'News <b>headline</b>');
$excerpts[24] = str_repeat('Ж', 170);
$photos[24] = 'https://example.test/photo.jpg';
$publisher->deliver(24, '@publicchannel', 0);
check(1 === count($client->photos), 'A configured featured image is sent as a photo.');
check('https://example.test/photo.jpg' === $client->photos[0]['photo'], 'The photo uses the post thumbnail URL.');
check(str_contains($client->photos[0]['caption'], 'News headline' . "\n\n"), 'The title can be plain text.');
check(str_contains($client->photos[0]['caption'], str_repeat('Ж', 159) . '…'), 'The short description is limited to 160 characters including the ellipsis.');
check(str_contains($client->photos[0]['caption'], '<a href="https://example.test/?p=24">https://example.test/?p=24</a>'), 'A separate post link can be included.');
check(true === $client->photos[0]['args']['disable_notification'], 'Photo posts respect channel silence.');

$posts[25] = new WP_Post(25, 'news', 'publish', '', 'Text-only fallback');
$excerpts[25] = 'A short summary';
$publisher->deliver(25, '@publicchannel', 0);
check(6 === count($client->sent) && 1 === count($client->photos), 'Posts without a featured image use a text message.');
check(false === $client->sent[5]['args']['disable_web_page_preview'], 'A per-type setting can enable link previews.');
check(! isset($client->sent[5]['args']['link_preview_options']), 'Enabled previews do not carry a disabling option.');

$options['ks_telegram']['channel_post_formats']['news'] = [
    'image' => true,
    'title' => false,
    'linked_title' => false,
    'description' => false,
    'permalink' => false,
    'disable_preview' => true,
];
$posts[26] = new WP_Post(26, 'news');
$publisher->deliver(26, '@publicchannel', 0);
check(7 === count($client->sent) && str_contains($client->sent[6]['text'], 'Test title'), 'Image-only posts without a photo fall back to a linked title.');

$admin_page = new \KonstantinSorokin\Telegram\Admin\SettingsPage(
    $settings,
    $sender,
    $client,
    new \KonstantinSorokin\Telegram\Bot\ChatRegistry(),
    new \KonstantinSorokin\Telegram\Support\DeliveryLog($settings)
);
check('-1001234567890' === $admin_page->channelNumericId(), 'The channel username resolves to a numeric ID.');
check('-1001234567890' === $admin_page->channelNumericId() && 1 === $client->chatRequests, 'The numeric ID is cached for repeat settings views.');

$options['ks_telegram']['channel_chat_id'] = '-100999';
check('-100999' === $admin_page->channelNumericId() && 1 === $client->chatRequests, 'A numeric channel ID needs no Telegram lookup.');

$options['ks_telegram']['channel_chat_id'] = '@anotherchannel';
$client->chatResult = ['ok' => true, 'result' => ['id' => -100222, 'type' => 'supergroup']];
check('' === $admin_page->channelNumericId(), 'A non-channel result is not displayed as the channel ID.');
check(2 === $client->chatRequests && '' === $admin_page->channelNumericId() && 2 === $client->chatRequests, 'A failed lookup is briefly cached.');

$options['ks_telegram']['channel_chat_id'] = '@publicchannel';
$options['ks_telegram']['bot_token'] = 'another-token';
$client->chatResult = ['ok' => true, 'result' => ['id' => -100444, 'type' => 'channel']];
check('-100444' === $admin_page->channelNumericId() && 3 === $client->chatRequests, 'Changing the bot token invalidates the cached ID.');

echo "Channel publishing smoke tests passed.\n";
