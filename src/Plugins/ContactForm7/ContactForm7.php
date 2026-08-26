<?php
/**
 * Contact Form 7 integration.
 *
 * This file registers Contact Form 7 submission notifications and converts
 * posted form data into safe Telegram message lines.
 *
 * @author  Konstantin Sorokin
 * @link    https://konstantinsorokin.com
 * @package KS_Telegram
 */

declare(strict_types=1);

namespace KonstantinSorokin\Telegram\Plugins\ContactForm7;

use KonstantinSorokin\Telegram\Plugins\AbstractPluginIntegration;
use KonstantinSorokin\Telegram\Plugins\BootablePluginIntegration;
use KonstantinSorokin\Telegram\Plugins\Attributes\Hook;

defined('ABSPATH') || exit;

/**
 * Sends Telegram notifications for Contact Form 7 submissions.
 *
 * Hooked on `wpcf7_submit`, not `wpcf7_before_send_mail`: CF7 skips the
 * whole mail block when a submission is classified as spam, so a rejected
 * enquiry produced no message, no log and no error. `wpcf7_submit` fires
 * for every outcome and carries the result.
 */
final readonly class ContactForm7 extends AbstractPluginIntegration implements BootablePluginIntegration {

    /**
     * Counts consecutive spam rejections. Not autoloaded — it is written on
     * submission and read by Site Health, never on an ordinary page view.
     */
    private const string SPAM_STREAK_OPTION = 'ks_telegram_cf7_spam_streak';

    /** When the streak was last updated, so a stale count can be ignored. */
    private const string SPAM_STREAK_SEEN_OPTION = 'ks_telegram_cf7_spam_streak_seen';

    /**
     * Outcomes worth telling the owner about when the optional rejected-
     * submission notice is on. `validation_failed` and `acceptance_missing`
     * are deliberately absent: those are the visitor mistyping their own email
     * or forgetting a checkbox, they are already shown the problem on screen,
     * and forwarding them would turn the channel into noise.
     */
    private const array REJECTED_STATUSES = ['spam', 'mail_failed', 'aborted'];

    /** Outcomes that prove the spam check ran and let the submission through. */
    private const array PASSED_STATUSES = ['mail_sent', 'mail_failed', 'aborted'];

    /**
     * Check whether Contact Form 7 is actually present.
     *
     * @return bool
     */
    public function isAvailable(): bool {
        return defined('WPCF7_VERSION') || class_exists('\WPCF7_ContactForm');
    }

    /**
     * Register what must run whatever the notification settings say.
     *
     * The streak counter is diagnostics, not a notification: Site Health reads
     * it to answer "is the spam check working". Left on the #[Hook] attribute
     * it was gated on `notify_contact_form_7`, so on a default install nothing
     * counted and the health test still reported good — measuring nothing and
     * saying so cheerfully.
     *
     * @return void
     */
    public function boot(): void {
        add_action('wpcf7_submit', [$this, 'trackSubmission'], 10, 2);
    }

    /**
     * Feed the streak counter, on every submission.
     *
     * @param mixed $contact_form Contact Form 7 form object. Unused.
     * @param mixed $result       Submission result.
     *
     * @return void
     */
    public function trackSubmission(mixed $contact_form, mixed $result = null): void {
        unset($contact_form);

        $this->trackSpamStreak(is_array($result) ? (string) ($result['status'] ?? '') : '');
    }

    /**
     * Handle a finished Contact Form 7 submission, whatever its outcome.
     *
     * @param mixed $contact_form Contact Form 7 form object.
     * @param mixed $result       Submission result: status, message, and more.
     *
     * @return void
     */
    #[Hook('wpcf7_submit', 'notify_contact_form_7', 10, 2)]
    public function submitted(mixed $contact_form, mixed $result = null): void {
        $status = is_array($result) ? (string) ($result['status'] ?? '') : '';

        if ('mail_sent' !== $status && ! in_array($status, self::REJECTED_STATUSES, true)) {
            return;
        }

        if ('mail_sent' !== $status && ! $this->settings->bool('notify_contact_form_7_rejected')) {
            return;
        }

        if (! class_exists('\WPCF7_Submission')) {
            return;
        }

        $submission = \WPCF7_Submission::get_instance();
        $data       = $submission ? $submission->get_posted_data() : [];
        $lines      = $this->messageLines(is_array($data) ? $data : []);

        /*
         * The rejection reason goes first, before the visitor's own fields —
         * the point of a rejected notice is that the enquiry may be real and
         * the gate wrong, and the reader has to know which they are looking at
         * before they read the message.
         */
        if ('mail_sent' !== $status) {
            $lines = [__('Rejected', 'ks-telegram') => $this->statusLabel($status)] + $lines;
        }

        $this->send($this->messages->event('contact_form_7', $this->title($contact_form), $lines));
    }

    /**
     * Stop Contact Form 7 sending email when Telegram is the delivery channel.
     *
     * CF7 reports a failure whenever wp_mail() returns false, whatever broke.
     * Gated on Telegram being configured — skipping unconditionally would
     * accept enquiries and deliver them nowhere.
     *
     * NOTE: this is a filter, registered through add_action(), which is a
     * direct alias of add_filter() — the return value is used.
     *
     * @param mixed $skip         Whether CF7 already means to skip.
     * @param mixed $contact_form Unused: the decision is the site's.
     *
     * @return bool
     */
    #[Hook('wpcf7_skip_mail', 'cf7_skip_mail', 10, 2)]
    public function skipMail(mixed $skip, mixed $contact_form = null): bool {
        unset($contact_form);

        if ((bool) $skip) {
            return true;
        }

        return $this->canDeliver();
    }

    /**
     * Count consecutive spam rejections and warn when they stop looking random.
     *
     * Spam arrives in bursts; a broken gate refuses everything, so an unbroken
     * run is evidence about the gate. Alerts once per streak.
     *
     * @param string $status Submission status.
     *
     * @return void
     */
    private function trackSpamStreak(string $status): void {
        if ('' === $status || 'init' === $status) {
            return;
        }

        /*
         * Reset only on an outcome that proves the spam check ran and passed.
         * Resetting on validation_failed too meant one visitor mistyping their
         * email cleared the count — and a gate that refuses everything then
         * never reached the threshold it exists to report.
         */
        if ('spam' !== $status) {
            if (! in_array($status, self::PASSED_STATUSES, true)) {
                return;
            }

            if (0 !== (int) get_option(self::SPAM_STREAK_OPTION, 0)) {
                update_option(self::SPAM_STREAK_OPTION, 0, false);
            }

            return;
        }

        $streak    = (int) get_option(self::SPAM_STREAK_OPTION, 0) + 1;
        $threshold = $this->settings->int('cf7_spam_streak_threshold', 5);

        update_option(self::SPAM_STREAK_OPTION, $streak, false);
        update_option(self::SPAM_STREAK_SEEN_OPTION, time(), false);

        // Counting is diagnostics and always runs; sending is a notification.
        if (0 === $threshold || $streak !== $threshold || ! $this->settings->bool('notifications_enabled', true)) {
            return;
        }

        $this->send(
            $this->messages->event(
                'contact_form_7',
                __('Every recent form submission was rejected as spam', 'ks-telegram'),
                [
                    /* translators: %d: number of consecutive rejected submissions. */
                    __('Rejected in a row', 'ks-telegram') => sprintf(_n('%d submission', '%d submissions', $streak, 'ks-telegram'), $streak),
                    __('Likely cause', 'ks-telegram')      => __('A spam check that has stopped working — expired or unmigrated reCAPTCHA keys, or an anti-spam service refusing every request. Real spam arrives in bursts; a gate that refuses everything does not.', 'ks-telegram'),
                ]
            )
        );
    }

    /**
     * Check whether Telegram is configured well enough to be the only channel.
     *
     * @return bool
     */
    private function canDeliver(): bool {
        /*
         * Not just "is Telegram configured" — is this form's notification
         * actually switched on. Without this, unchecking "Contact Form 7
         * submissions" while skip-mail stayed on accepted the enquiry, sent no
         * mail and sent no Telegram message: exactly the silent loss the guard
         * was written to prevent.
         */
        if (! $this->settings->bool('notifications_enabled', true) || ! $this->settings->bool('notify_contact_form_7')) {
            return false;
        }

        if ('' === trim($this->settings->string('bot_token'))) {
            return false;
        }

        return [] !== $this->settings->parseChatIds(null);
    }

    /**
     * Get a readable label for a rejected submission status.
     *
     * @param string $status Submission status.
     *
     * @return string
     */
    private function statusLabel(string $status): string {
        return match ($status) {
            'spam'        => __('Classified as spam by a spam check', 'ks-telegram'),
            'mail_failed' => __('Accepted, but the email could not be sent', 'ks-telegram'),
            'aborted'     => __('Aborted before sending', 'ks-telegram'),
            default       => sanitize_text_field($status),
        };
    }

    /**
     * Get the readable Contact Form 7 form title.
     *
     * @param mixed $contact_form Contact Form 7 form object.
     *
     * @return string
     */
    private function title(mixed $contact_form): string {
        if (! is_object($contact_form) || ! method_exists($contact_form, 'title')) {
            return __('Contact Form 7', 'ks-telegram');
        }

        $title = sanitize_text_field((string) $contact_form->title());

        return '' !== $title ? $title : __('Contact Form 7', 'ks-telegram');
    }

    /**
     * Convert submitted fields to message lines.
     *
     * @param array<string,mixed> $data Submitted form data.
     *
     * @return array<string,string>
     */
    private function messageLines(array $data): array {
        $lines = [];

        foreach ($data as $key => $value) {
            $key = (string) $key;

            if ($this->shouldSkipField($key)) {
                continue;
            }

            $value = $this->fieldValue($value);
            if ('' !== $value) {
                $lines[$this->humanLabel($key)] = sanitize_textarea_field($value);
            }
        }

        return $lines;
    }

    /**
     * Check whether an internal technical field should be hidden.
     *
     * @param string $key Field key.
     *
     * @return bool
     */
    private function shouldSkipField(string $key): bool {
        $key = strtolower($key);

        return str_starts_with($key, '_wpcf7') || str_contains($key, 'recaptcha');
    }

    /**
     * Convert a raw field value to readable text.
     *
     * @param mixed $value Raw field value.
     *
     * @return string
     */
    private function fieldValue(mixed $value): string {
        if (is_array($value)) {
            $items = [];

            foreach ($value as $item) {
                if (is_scalar($item)) {
                    $items[] = sanitize_text_field((string) $item);
                }
            }

            return implode(', ', $items);
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Convert common form field keys to readable labels.
     *
     * @param string $key Form field key.
     *
     * @return string
     */
    private function humanLabel(string $key): string {
        return match ($this->normalizeFieldKey($key)) {
            'address', 'adres', 'adresa', 'адрес', 'адреса' => __('Address', 'ks-telegram'),
            'budget', 'byudzhet', 'cost', 'price', 'tsena', 'tsina', 'vartist', 'вартість', 'бюджет', 'цена', 'ціна' => __('Budget', 'ks-telegram'),
            'callback', 'contact', 'contacts', 'feedback', 'obratnayasvyaz', 'obratnyjzvonok', 'obratnyyzvonok', 'svyaz', 'zviazok', 'zvorotniizviazok', 'zvorotniyzvyazok', 'zvyazok', 'звязок', 'зворотнийзвязок', 'обратнаясвязь', 'обратныйзвонок' => __('Feedback', 'ks-telegram'),
            'city', 'gorod', 'misto', 'town', 'город', 'місто' => __('City', 'ks-telegram'),
            'company', 'firma', 'firm', 'kompaniya', 'kompaniia', 'organization', 'organisation', 'org', 'yourcompany', 'компания', 'компанія', 'организация', 'організація', 'фирма', 'фірма' => __('Company', 'ks-telegram'),
            'date', 'data', 'дата' => __('Date', 'ks-telegram'),
            'email', 'emailaddress', 'mail', 'pochta', 'youremail', 'електроннапошта', 'почта', 'пошта' => __('Email', 'ks-telegram'),
            'message', 'comment', 'komentar', 'question', 'text', 'yourmessage', 'vopros', 'zapytannya', 'zayavka', 'заявка', 'запитання', 'коментар', 'комментарий', 'повідомлення', 'сообщение', 'текст', 'вопрос' => __('Message', 'ks-telegram'),
            'name', 'fio', 'fullname', 'imya', 'pib', 'yourname', 'имя', 'імя', 'піб', 'фио' => __('Name', 'ks-telegram'),
            'office', 'branch', 'filial', 'otdel', 'viddil', 'кабинет', 'відділ', 'офис', 'офіс', 'отдел', 'филиал', 'філія' => __('Office', 'ks-telegram'),
            'phone', 'cellphone', 'mobile', 'telefon', 'telephone', 'tel', 'yourphone', 'мобильный', 'мобільний', 'телефон' => __('Phone', 'ks-telegram'),
            'position', 'dolzhnost', 'jobtitle', 'posada', 'role', 'должность', 'посада' => __('Position', 'ks-telegram'),
            'project', 'proekt', 'proiect', 'yourproject', 'проект', 'проєкт' => __('Project', 'ks-telegram'),
            'service', 'napravlenie', 'product', 'requesttype', 'servicecategory', 'usluga', 'категория', 'направление', 'напрямок', 'послуга', 'услуга' => __('Service', 'ks-telegram'),
            'subject', 'tema', 'yoursubject', 'тема' => __('Subject', 'ks-telegram'),
            'time', 'vremya', 'час', 'время' => __('Time', 'ks-telegram'),
            'url', 'site', 'sait', 'website', 'websait', 'weburl', 'вебсайт', 'сайт' => __('Website', 'ks-telegram'),
            default => sanitize_text_field(ucwords(str_replace(['-', '_'], ' ', $key))),
        };
    }

    /**
     * Normalize form field keys for multilingual alias matching.
     *
     * @param string $key Raw field key.
     *
     * @return string
     */
    private function normalizeFieldKey(string $key): string {
        $key = wp_strip_all_tags(trim($key));
        $key = function_exists('mb_strtolower') ? mb_strtolower($key, 'UTF-8') : strtolower($key);

        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $key);
    }
}
