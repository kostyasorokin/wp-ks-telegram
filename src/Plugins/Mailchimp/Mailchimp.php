<?php
/**
 * Mailchimp for WordPress integration.
 *
 * This file registers MC4WP subscription notifications and extracts the
 * submitted subscriber email without storing unnecessary personal data.
 *
 * @author  Konstantin Sorokin
 * @link    https://konstantinsorokin.com
 * @package KS_Telegram
 */

declare(strict_types=1);

namespace KonstantinSorokin\Telegram\Plugins\Mailchimp;

use KonstantinSorokin\Telegram\Plugins\AbstractPluginIntegration;
use KonstantinSorokin\Telegram\Plugins\Attributes\Hook;

defined('ABSPATH') || exit;

/**
 * Sends Telegram notifications for Mailchimp form events.
 */
final readonly class Mailchimp extends AbstractPluginIntegration {

    /**
     * Send a subscription notification.
     *
     * @param mixed $form Mailchimp form object.
     *
     * @return void
     */
    #[Hook('mc4wp_form_subscribed', 'notify_mailchimp_subscribe')]
    public function subscribed(mixed $form): void {
        $this->sendMessage($form, 'subscribe');
    }

    /**
     * Send an unsubscribe notification.
     *
     * @param mixed $form Mailchimp form object.
     *
     * @return void
     */
    #[Hook('mc4wp_form_unsubscribed', 'notify_mailchimp_unsubscribe')]
    public function unsubscribed(mixed $form): void {
        $this->sendMessage($form, 'unsubscribe');
    }

    /**
     * Send a Mailchimp notification from a form object.
     *
     * @param mixed  $form Form object.
     * @param string $type Event type.
     *
     * @return void
     */
    private function sendMessage(mixed $form, string $type): void {
        $email = $this->email($form);

        if ('' === $email) {
            return;
        }

        $this->send(
            $this->messages->event(
                'subscribe' === $type ? 'mailchimp_subscribe' : 'mailchimp_unsubscribe',
                'subscribe' === $type
                    ? __('Mailchimp subscription', 'ks-telegram')
                    : __('Mailchimp unsubscribe', 'ks-telegram'),
                [__('Email', 'ks-telegram') => $email]
            )
        );
    }

    /**
     * Extract a subscriber email from the Mailchimp form object.
     *
     * @param mixed $form Mailchimp form object.
     *
     * @return string
     */
    private function email(mixed $form): string {
        $data = is_object($form) && method_exists($form, 'get_data') ? $form->get_data() : [];
        $data = is_array($data) ? $data : [];

        return sanitize_email((string) ($data['EMAIL'] ?? $data['email'] ?? ''));
    }
}
