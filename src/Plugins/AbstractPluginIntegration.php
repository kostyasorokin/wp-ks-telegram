<?php
/**
 * Base plugin integration.
 *
 * This file contains shared dependencies and helpers for optional third-party
 * plugin notification integrations.
 *
 * @author  Konstantin Sorokin
 * @link    https://konstantinsorokin.com
 * @package KS_Telegram
 */

declare(strict_types=1);

namespace KonstantinSorokin\Telegram\Plugins;

use KonstantinSorokin\Telegram\Bot\MessageSender;
use KonstantinSorokin\Telegram\Notifications\MessageFactory;
use KonstantinSorokin\Telegram\Settings\SettingsRepository;

defined('ABSPATH') || exit;

/**
 * Provides shared services to concrete plugin integrations.
 */
abstract readonly class AbstractPluginIntegration implements PluginIntegration {

    public function __construct(
        protected SettingsRepository $settings,
        protected MessageSender $sender,
        protected MessageFactory $messages
    ) {}

    /**
     * Check whether the integration can register its hooks.
     *
     * @return bool
     */
    public function isAvailable(): bool {
        return true;
    }

    /**
     * Send a message to the configured default chats.
     *
     * @param string              $message Telegram-ready message text.
     * @param array<string,mixed> $args    Telegram sendMessage arguments.
     *
     * @return bool
     */
    protected function send(string $message, array $args = []): bool {
        return $this->sender->send(null, $message, $args);
    }
}
