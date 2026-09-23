<?php
/**
 * Main plugin container.
 *
 * This file wires the plugin services together and exposes the shared sender
 * instance used by the public API and internal integrations.
 *
 * @author  Konstantin Sorokin
 * @link    https://konstantinsorokin.com
 * @package KS_Telegram
 */

declare(strict_types=1);

namespace KonstantinSorokin\Telegram;

use KonstantinSorokin\Telegram\Admin\HealthCheck;
use KonstantinSorokin\Telegram\Admin\SettingsPage;
use KonstantinSorokin\Telegram\Admin\UserProfileFields;
use KonstantinSorokin\Telegram\Auth\LoginController;
use KonstantinSorokin\Telegram\Auth\ProfileCompletionController;
use KonstantinSorokin\Telegram\Auth\TelegramAvatarImporter;
use KonstantinSorokin\Telegram\Auth\UserAvatarController;
use KonstantinSorokin\Telegram\Bot\BotApiManager;
use KonstantinSorokin\Telegram\Bot\ChatRegistry;
use KonstantinSorokin\Telegram\Bot\MessageSender;
use KonstantinSorokin\Telegram\Bot\TelegramApiClient;
use KonstantinSorokin\Telegram\Bot\WebhookController;
use KonstantinSorokin\Telegram\Notifications\NotificationManager;
use KonstantinSorokin\Telegram\Plugins\IntegrationManager;
use KonstantinSorokin\Telegram\Plugins\WooCommerce\CustomerPhoneSync;
use KonstantinSorokin\Telegram\Publishing\ChannelPublisher;
use KonstantinSorokin\Telegram\Settings\SettingsRepository;
use KonstantinSorokin\Telegram\Support\DeliveryLog;

defined('ABSPATH') || exit;

/**
 * Coordinates all plugin services.
 */
final class Plugin {

    private static ?self $instance = null;

    private SettingsRepository $settings;

    private MessageSender $sender;

    private BotApiManager $bot_api;

    private TelegramApiClient $client;

    /** Retained: it produces markup a theme may place itself, unlike the other controllers. */
    private ?LoginController $login = null;

    private bool $booted = false;

    private function __construct() {
        $this->settings = new SettingsRepository();
        $this->bot_api  = new BotApiManager($this->settings);
        $this->client   = new TelegramApiClient($this->settings, $this->bot_api);
        $this->sender   = new MessageSender($this->settings, $this->client);
    }

    /**
     * Get the singleton instance.
     *
     * @return self
     */
    public static function instance(): self {
        return self::$instance ??= new self();
    }

    /**
     * Boot all integrations.
     *
     * @return void
     */
    public function boot(): void {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        $chat_registry      = new ChatRegistry();
        $phone_sync         = new CustomerPhoneSync();
        $avatar_importer    = new TelegramAvatarImporter($this->settings, $this->bot_api);
        $profile_completion = new ProfileCompletionController($this->settings, $phone_sync);

        /*
         * Boots before anything can send. Subscribes to the long-unused
         * `ks_telegram_message_sent`.
         */
        $delivery_log = new DeliveryLog($this->settings);
        $delivery_log->boot();

        (new HealthCheck($this->settings, $this->client, $delivery_log))->boot();
        (new SettingsPage($this->settings, $this->sender, $this->client, $chat_registry, $delivery_log))->boot();
        (new UserProfileFields($phone_sync))->boot();
        (new UserAvatarController($this->settings))->boot();
        $this->login = new LoginController($this->settings, $profile_completion, $phone_sync, $avatar_importer);
        $this->login->boot();

        $profile_completion->boot();
        (new WebhookController($this->settings, $this->bot_api, $chat_registry))->boot();
        (new NotificationManager($this->settings, $this->sender))->boot();
        (new ChannelPublisher($this->settings, $this->sender, $this->client))->boot();
        (new IntegrationManager($this->settings, $this->sender))->boot();

        /**
         * Fires when the KS Telegram plugin has registered services.
         *
         * @param Plugin $plugin Plugin instance.
         */
        do_action('ks_telegram_loaded', $this);
    }

    /**
     * Get the settings repository.
     *
     * @return SettingsRepository
     */
    public function settings(): SettingsRepository {
        return $this->settings;
    }

    /**
     * Get the shared message sender.
     *
     * @return MessageSender
     */
    public function sender(): MessageSender {
        return $this->sender;
    }

    /**
     * Get the shared TelegramBot/Api manager.
     *
     * @return BotApiManager
     */
    public function botApi(): BotApiManager {
        return $this->bot_api;
    }

    /**
     * Get the WordPress-friendly Telegram API adapter.
     *
     * @return TelegramApiClient
     */
    public function apiClient(): TelegramApiClient {
        return $this->client;
    }

    /**
     * Get the Telegram Login controller. Null before boot().
     *
     * @return LoginController|null
     */
    public function login(): ?LoginController {
        return $this->login;
    }
}
