# KS Telegram

[![Software License](https://img.shields.io/badge/license-GPL--3.0--or--later-brightgreen.svg?style=flat-square)](https://www.gnu.org/licenses/gpl-3.0.html)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.4-777bb4.svg?style=flat-square)](https://www.php.net/)
[![Telegram Bot API](https://img.shields.io/badge/telegram-bot--api-26a5e4.svg?style=flat-square)](https://core.telegram.org/bots/api)
[![Composer](https://img.shields.io/badge/composer-telegram--bot%2Fapi-885630.svg?style=flat-square)](https://github.com/TelegramBot/Api)
![Architecture](https://img.shields.io/badge/Architecture-OOP%20%7C%20PSR--4-orange.svg)

Modern Telegram notifications, authorization, and bot integration toolkit for WordPress and WooCommerce.

Issues and pull requests: https://github.com/kostyasorokin/wp-ks-telegram/issues

## Features

- Telegram Bot API client built on `telegram-bot/api`.
- Reusable public PHP API for sending Telegram messages from other plugins.
- Telegram Login through OpenID Connect Authorization Code Flow with PKCE.
- Telegram Login button on `wp-login.php` and `[ks_telegram_login]` shortcode.
- Optional Telegram phone permission request and bot write access request.
- Email completion flow for Telegram users when WordPress needs a real email.
- Optional Telegram profile avatar import into the WordPress media library.
- REST webhook endpoint for bot updates and custom bot handlers.
- Chat ID helper for private chats, groups, supergroups, and channels.
- Silent notification and web preview controls.
- Message header builder for date, time, message type hashtag, and site hashtag.
- WordPress notifications for content, comments, users, security, media, updates, Site Health, and mail failures.
- WooCommerce notifications for new orders, order status changes, add-to-cart events, and low stock alerts.
- WooCommerce Telegram checkout field and Telegram phone copy to billing phone.
- Contact Form 7 and Mailchimp for WordPress notification hooks.
- Auto-discovered plugin integrations from `src/Plugins` with PHP attributes and generated cache.
- Russian and Ukrainian translation files.

## Requirements

- PHP 8.4 or newer.
- WordPress 6.7 or newer.
- Composer.
- Node.js and npm only when rebuilding assets or translations.

## Installation

1. Upload the plugin to `/wp-content/plugins/ks-telegram`.
2. Activate **KS Telegram** in WordPress.
3. Configure the plugin in **Settings > KS Telegram**.

## Admin Settings

The settings page is split into native WordPress tabs:

- **Settings**: bot token, default chat IDs, parse mode, Telegram Login, webhook, silent notifications, and general message controls.
- **Notifications**: built-in WordPress notification events.
- **Messages**: message content and WooCommerce message field controls.
- **Plugins**: third-party plugin integrations such as Contact Form 7, Mailchimp, and WooCommerce.
- **Tests**: test message sender and Chat ID helper.

## Telegram Bot Setup

Create a bot through [@BotFather](https://t.me/BotFather), copy the bot token, and paste it into the plugin settings.

For group or channel notifications, add the bot to the target chat first. To find numeric chat IDs, use the Chat ID helper in the **Tests** tab:

1. Send a message to the bot, mention it in a group, or post into a channel where the bot is an administrator.
2. Click **Fetch updates** in the Chat ID helper.
3. Copy the discovered ID into **Default chat IDs**.

Telegram Bot API accepts numeric chat IDs and public channel usernames such as `@channelusername`. Negative IDs for groups, supergroups, and channels are normal.

## Telegram Login

To get the Login Client ID and Client Secret:

1. Open [@BotFather](https://t.me/BotFather).
2. Select your bot and go to **Bot Settings > Web Login**.
3. Add the allowed website origin and callback URL shown in **Settings > KS Telegram**.
4. Copy the **Client ID** and **Client Secret** from BotFather into the plugin settings.

Official Telegram guide: [Log In With Telegram](https://core.telegram.org/bots/telegram-login#registering-your-allowed-urls).

Then place `[ks_telegram_login]` where the login button should appear. The plugin also renders a Telegram Login button on `wp-login.php` when Telegram Login is enabled.

Telegram can return a verified phone number only when the `phone` scope is requested and the user approves it. Telegram Login does not currently provide user email addresses, so the plugin can ask new Telegram users for an email address after login.

## Public API

Send a message to the default chats:

```php
ks_telegram_send_message(null, 'Message to default chats');
```

Send a message to one chat:

```php
ks_telegram_send_message('123456789', 'Message to one chat');
```

Send a message to many chats:

```php
ks_telegram_send_message(['123', '-100456'], 'Message to many chats');
```

Use the `ks_telegram_message_text` and `ks_telegram_send_args` filters to customize outgoing messages.

### Telegram Login button in a theme template

`ks_telegram_login_button()` returns the same markup the
`[ks_telegram_login]` shortcode produces, for templates that want to place the
button themselves. It enqueues the button's stylesheet, which is the reason to
call it rather than to copy its markup.

```php
echo ks_telegram_login_button();                           // back to the current page
echo ks_telegram_login_button('btn btn-sm');               // extra CSS classes
echo ks_telegram_login_button('', home_url('/account/'));  // custom redirect
```

It returns an empty string — never a notice, never a fatal — when the plugin has
not booted yet, when Telegram Login is switched off, when the client ID is
missing, and when the visitor is already signed in. That makes it safe to echo
unconditionally.

**The return value is already escaped.** Print it as it is; `esc_html()` would
show the tags instead of the button.

The shortcode takes the same two arguments:

```
[ks_telegram_login class="btn btn-sm" redirect_to="/account/"]
```

For direct Bot API access:

```php
$bot = ks_telegram_bot_api();

if (! is_wp_error($bot)) {
    $bot->sendMessage('123456789', 'Hello from TelegramBot/Api');
}
```

For arbitrary Bot API methods with `WP_Error` handling:

```php
$result = ks_telegram_bot_request(
    'sendMessage',
    [
        'chat_id' => '123456789',
        'text'    => 'Hello through KS Telegram',
    ]
);
```

For webhook-driven bot commands:

```php
add_action(
    'ks_telegram_register_bot_handlers',
    static function (\TelegramBot\Api\Client $bot): void {
        $bot->command(
            'ping',
            static function (\TelegramBot\Api\Types\Message $message) use ($bot): void {
                $bot->sendMessage($message->getChat()->getId(), 'pong');
            }
        );
    }
);
```

The plugin also fires `ks_telegram_bot_update` with a parsed `TelegramBot\Api\Types\Update` object for lower-level update handling.

## Plugin Integrations

Third-party integrations live in `src/Plugins`. The integration manager scans that directory, validates integration classes, and stores a generated cache in `cache/plugin-integrations.php`. When `WP_DEBUG` is enabled, the cache is regenerated on every request.

Integration hooks can be declared with the `KonstantinSorokin\Telegram\Plugins\Attributes\Hook` attribute where it improves readability. Existing integrations include:

- `src/Plugins/ContactForm7/ContactForm7.php`
- `src/Plugins/Mailchimp/Mailchimp.php`
- `src/Plugins/WooCommerce/WooCommerceManager.php`

## Development

Install PHP dependencies:

```bash
composer install
```

Install frontend dependencies:

```bash
npm install
```

Useful commands:

```bash
composer lint
npm run build
npm run build:assets
npm run build:scss
npm run build:js
npm run i18n
npm run i18n:pot
npm run i18n:compile
npm run lint
```

Source assets live in `resources/js` and `resources/scss`. Compiled assets are written to `assets/js` and `assets/css`.

## License

GPL-3.0-or-later.
