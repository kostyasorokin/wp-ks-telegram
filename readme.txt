=== KS Telegram ===
Contributors: konstantinsorokin
Tags: telegram, bot, notifications, woocommerce, login
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.4
Stable tag: 1.4.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Modern Telegram notifications, authorization, and bot integration toolkit for WordPress and WooCommerce.

== Description ==

KS Telegram provides reusable Telegram Bot API services powered by telegram-bot/api, WordPress notifications, Telegram Login, REST webhook handling, and WooCommerce-compatible notification hooks.

The plugin is built as a standalone OOP application with namespaces, Composer PSR-4 autoloading, PHP 8.4 features, and a public API that other plugins can use to send Telegram messages or access the configured bot client.

= Main features =

* Telegram Bot API client based on telegram-bot/api.
* Telegram Login through OpenID Connect Authorization Code Flow with PKCE.
* Telegram Login button on wp-login.php and [ks_telegram_login] shortcode.
* Optional Telegram phone permission request and bot write access request.
* Email completion flow for Telegram users when WordPress needs a real email.
* Optional Telegram profile avatar import into the WordPress media library.
* REST webhook endpoint for bot updates and custom bot handlers.
* Chat ID helper for private chats, groups, supergroups, and channels.
* Silent notification and web preview controls.
* Separate silent publishing option for Telegram channel posts.
* Per-post-type channel formats for featured image, title, short description, and post link.
* Message header builder for date, time, message type hashtag, and site hashtag.
* Built-in WordPress notifications for content, comments, users, security, media, updates, Site Health, and mail failures.
* WooCommerce notifications for new orders, order status changes, add-to-cart events, and low stock alerts.
* WooCommerce Telegram checkout field and Telegram phone copy to billing phone.
* Contact Form 7 and Mailchimp for WordPress notification hooks.
* Auto-discovered plugin integrations from src/Plugins with PHP attributes and generated cache.
* Russian and Ukrainian translation files.

== Installation ==

1. Upload the plugin to /wp-content/plugins/ks-telegram.
2. Run composer install in the plugin directory.
3. Activate KS Telegram in WordPress.
4. Configure the plugin in Settings > KS Telegram.

== Settings ==

The settings page is split into native WordPress tabs:

* Settings: bot token, default chat IDs, parse mode, Telegram Login, webhook, silent notifications, and general message controls.
* Notifications: built-in WordPress notification events and per-type public channel publishing.
* Messages: message content and WooCommerce message field controls.
* Plugins: third-party plugin integrations such as Contact Form 7, Mailchimp, and WooCommerce.
* Tests: test message sender and Chat ID helper.

== Telegram Bot Setup ==

Create a bot through @BotFather, copy the bot token, and paste it into the plugin settings.

For group or channel notifications, add the bot to the target chat first. To find numeric chat IDs, use the Chat ID helper in the Tests tab:

1. Send a message to the bot, mention it in a group, or post into a channel where the bot is an administrator.
2. Click Fetch updates in the Chat ID helper.
3. Copy the discovered ID into Default chat IDs.

Telegram Bot API accepts numeric chat IDs and public channel usernames such as @channelusername. Negative IDs for groups, supergroups, and channels are normal.

== Telegram Login ==

To get the Login Client ID and Client Secret, open @BotFather, select your bot, and go to Bot Settings > Web Login. Add the allowed website origin and the callback URL shown in the plugin settings. BotFather will then show the Client ID and Client Secret.

Official guide: https://core.telegram.org/bots/telegram-login#registering-your-allowed-urls

Telegram can return a verified phone number only when the phone scope is requested and the user approves it. Telegram Login does not currently provide user email addresses, so the plugin can ask new Telegram users for an email address after login.

The plugin can also download the largest available Telegram profile avatar through Bot API and use it as the WordPress user avatar.

== Developer API ==

Use ks_telegram_send_message() for simple notifications.

Use ks_telegram_login_button() to place the Telegram Login button in a theme template instead of using the [ks_telegram_login] shortcode. It takes an optional CSS class and an optional redirect target, enqueues the button stylesheet itself, and returns an empty string when Telegram Login is disabled, unconfigured, or the visitor is already signed in — so it is safe to echo unconditionally. The return value is already escaped and must be printed as it is.

    echo ks_telegram_login_button();
    echo ks_telegram_login_button( 'btn btn-sm' );
    echo ks_telegram_login_button( '', home_url( '/account/' ) );

The shortcode takes the same two arguments: [ks_telegram_login class="btn btn-sm" redirect_to="/account/"].

Use ks_telegram_bot_api() to access the configured TelegramBot\Api\BotApi instance.

Use ks_telegram_bot_request() to call arbitrary Telegram Bot API methods with WP_Error handling.

Use the ks_telegram_register_bot_handlers action to register TelegramBot\Api\Client command and update handlers for webhook requests.

Use the ks_telegram_message_text and ks_telegram_send_args filters to customize outgoing messages.

Third-party integrations live in src/Plugins. The integration manager scans that directory, validates integration classes, and stores a generated cache in cache/plugin-integrations.php. When WP_DEBUG is enabled, the cache is regenerated on every request.

== External services ==

This plugin exists to send your site's events to Telegram, so it contacts services outside your site. Everything it sends is listed here.

= Telegram Bot API (api.telegram.org) =

Used whenever a notification is sent, when you press Test message, when a bot token is verified, when the Chat ID helper fetches updates, when a saved channel username is resolved to its numeric ID, when a webhook is registered or removed, and when a Telegram profile avatar is imported.

What is transmitted: your bot token (it forms part of every Bot API request URL), the target chat IDs, and the message text. Public channel publishing can also send the featured image's URL to Telegram, which then fetches that image from your site. Depending on which notifications you switch on, message text can contain personal data belonging to your visitors and users — WordPress usernames and email addresses, comment author names, email addresses and comment text, complete Contact Form 7 submissions, WooCommerce billing and shipping names, addresses, phone numbers, email addresses and customer notes, Mailchimp subscriber addresses, and the visitor's IP address, user agent and referring URL. The request-context block carrying IP, user agent and referrer is enabled by default and can be switched off in the settings.

**Passwords.** The setting "Include plain passwords in authorization alerts" is off by default. If you enable it, the password submitted at the login form is sent to Telegram in plain text, including for failed login attempts — which means a password typed by a real person, sometimes one belonging to an account on a different site. Leave it off unless you fully understand that.

Registering a webhook additionally sends Telegram your site's REST endpoint URL and the webhook secret.

Telegram terms of service: https://telegram.org/tos — Bot developer terms: https://telegram.org/tos/bot-developers — Privacy policy: https://telegram.org/privacy

= Telegram Login (oauth.telegram.org) =

Used only when Telegram Login is enabled and a visitor presses the login button. The visitor's browser is sent to Telegram to authorize; the site then exchanges the returned code for an identity token.

What is transmitted: your Login client ID and client secret, the authorization code, and the site's callback URL. Telegram returns the account's Telegram ID, name, username and — only if the phone permission was requested and the visitor approved it — the phone number.

Same terms and privacy policy as above.

= Google reCAPTCHA verification (www.google.com) — off by default =

Not used unless you switch on "Let Site Health test the reCAPTCHA key against Google" in the plugin settings. It is off by default and nothing contacts Google while it stays off.

When enabled, the Site Health screen and WordPress's weekly Site Health cron send Contact Form 7's reCAPTCHA secret key to Google's siteverify endpoint, together with a deliberately invalid token, in order to learn whether the key is still accepted. No visitor data is included. The result is cached for one hour.

Google terms of service: https://policies.google.com/terms — Privacy policy: https://policies.google.com/privacy

= What stays on your site =

The delivery log records failed deliveries only, and keeps the chat ID, the error Telegram returned, and the length of the message. It does not store message text. Nothing is sent anywhere for analytics, licensing or statistics; this plugin has no telemetry.

== Frequently Asked Questions ==

= Do I need a Telegram bot? =

Yes. Create one with @BotFather, copy the token into the plugin settings, and use the Chat ID helper in the Tests tab to find the chat to send to.

= Why do notifications stop arriving? =

Open Tools > Site Health. The plugin registers a test that checks the bot token against Telegram, checks that a chat is configured, and reports the last delivery outcome. The Tests tab in the plugin settings also lists recent failures with the exact message Telegram returned.

= Is Telegram Login safe to enable? =

It uses Telegram's OpenID Connect flow with PKCE. The sign-in is bound to the browser that started it, signing in and linking an existing account are separate operations, and one Telegram account can be linked to only one WordPress user. It is off by default; you need a Login client ID and secret from @BotFather before it does anything.

= Can I put the login button in my theme? =

Yes. Use the `[ks_telegram_login]` shortcode, or call `ks_telegram_login_button()` in a template. Both accept a CSS class and a redirect target.

= Does it work without WooCommerce or Contact Form 7? =

Yes. Those integrations register themselves only when the plugin in question is active.

= Does it send anything about my site anywhere else? =

No. See the External services section — Telegram, and Google only if you explicitly switch on the optional reCAPTCHA check.

= Where do I report a bug or ask for a feature? =

On GitHub: https://github.com/kostyasorokin/wp-ks-telegram/issues

Security issues should not go in a public issue — see SECURITY.md in the repository for how to report them privately.

== Development ==

Composer:

* composer install
* composer lint

Frontend and translations:

* npm install
* npm run build
* npm run build:assets
* npm run build:scss
* npm run build:js
* npm run i18n
* npm run i18n:pot
* npm run i18n:compile
* npm run lint

Source assets live in resources/js and resources/scss. Compiled assets are written to assets/js and assets/css.

The source repository is https://github.com/kostyasorokin/wp-ks-telegram — issues and pull requests are welcome there.

== Changelog ==

= 1.4.0 =
Add independent channel message format settings for posts, pages, and public custom post types.

= 1.3.1 =
Show the numeric channel ID below the saved channel username in settings.

= 1.3.0 =
Add an independent silent publishing option for channel posts.

= 1.2.0 =
Discover public custom post types automatically for channel publishing, including KS Cases, while preserving existing KS News choices.

= 1.1.1 =
Link channel message titles to their posts and disable link previews.

= 1.1.0 =
Add optional Telegram channel publishing for new posts, pages, and KS News items.

= 1.0.0 =
First public release.
