<?php

namespace App\Services\Email;

use App\Support\Settings;
use Illuminate\Support\Facades\Mail;

class EmailService
{
    /** @var array<string, array<string, array<string, string>>> */
    private array $catalogs;

    public function __construct()
    {
        $decoded = json_decode((string) file_get_contents(resource_path('email/catalogs.json')), true);
        $this->catalogs = is_array($decoded) ? $decoded : [];
    }

    public function sendVerificationEmail(string $email, string $displayName, string $token, ?string $locale = null): void
    {
        if (! Settings::bool('email_enabled')) {
            return;
        }
        $strings = $this->strings('_VERIFY_I18N', $locale);
        $html = $this->renderTemplate('verify_email.html', [
            'greeting' => str_replace('{name}', $displayName, $strings['greeting']),
            'body' => new SafeHtml($strings['body']),
            'button' => $strings['button'],
            'link_fallback' => $strings['link_fallback'],
            'footer' => $strings['footer'],
            'url' => Settings::string('app_base_url').'/verify-email?token='.$token,
            'base_url' => Settings::string('app_base_url'),
        ], $locale);
        $this->dispatch($strings['subject'], [$email], $html);
    }

    public function sendWelcomeEmail(string $email, string $displayName, ?string $locale = null): void
    {
        if (! Settings::bool('email_enabled')) {
            return;
        }
        $strings = $this->strings('_WELCOME_I18N', $locale);
        $html = $this->renderTemplate('welcome.html', [
            'greeting' => str_replace('{name}', $displayName, $strings['greeting']),
            'body' => new SafeHtml($strings['body']),
            'step1' => new SafeHtml($strings['step1']),
            'step2' => new SafeHtml($strings['step2']),
            'step3' => $strings['step3'],
            'button' => $strings['button'],
            'footer' => $strings['footer'],
            'url' => Settings::string('app_base_url').'/dashboard',
            'base_url' => Settings::string('app_base_url'),
        ], $locale);
        $this->dispatch($strings['subject'], [$email], $html);
    }

    public function sendAccountDeletedEmail(?string $email, string $displayName, ?string $locale = null): void
    {
        if (! Settings::bool('email_enabled') || ! $email) {
            return;
        }
        $strings = $this->strings('_DELETION_I18N', $locale);
        $html = $this->renderTemplate('account_deleted.html', [
            'greeting' => str_replace('{name}', $displayName, $strings['greeting']),
            'body' => new SafeHtml($strings['body']),
            'footer' => $strings['footer'],
            'base_url' => Settings::string('app_base_url'),
        ], $locale);
        $this->dispatch($strings['subject'], [$email], $html);
    }

    public function sendResetPasswordEmail(string $email, string $displayName, string $token, ?string $locale = null): void
    {
        if (! Settings::bool('email_enabled')) {
            return;
        }
        $strings = $this->strings('_RESET_I18N', $locale);
        $html = $this->renderTemplate('reset_password.html', [
            'greeting' => str_replace('{name}', $displayName, $strings['greeting']),
            'body' => new SafeHtml($strings['body']),
            'button' => $strings['button'],
            'link_fallback' => $strings['link_fallback'],
            'footer' => $strings['footer'],
            'url' => Settings::string('app_base_url').'/reset-password?token='.$token,
            'base_url' => Settings::string('app_base_url'),
        ], $locale);
        $this->dispatch($strings['subject'], [$email], $html);
    }

    public function sendContactEmail(string $senderEmail, string $subject, string $description, string $locale = 'en'): void
    {
        if (! Settings::bool('email_enabled') || Settings::string('contact_email') === '') {
            return;
        }
        $strings = $this->strings('_CONTACT_I18N', $locale);
        $html = $this->renderTemplate('contact.html', [
            'email_title' => $strings['email_title'],
            'logo' => $strings['logo'],
            'from_label' => $strings['from_label'],
            'subject_label' => $strings['subject_label'],
            'message_label' => $strings['message_label'],
            'sender_email' => $senderEmail,
            'subject' => $subject,
            'description' => $description,
            'footer' => $strings['footer'],
            'base_url' => Settings::string('app_base_url'),
        ], $locale);
        $this->dispatch($strings['subject_prefix'].' '.$subject, [Settings::string('contact_email')], $html, $senderEmail, true);
    }

    public function sendFeedbackNotification(
        string $entryType,
        string $title,
        string $description,
        string $authorUsername,
        int $entryId,
        string $locale = 'en',
    ): void {
        if (! Settings::bool('email_enabled') || Settings::string('contact_email') === '') {
            return;
        }
        try {
            $strings = $this->strings('_FEEDBACK_I18N', $locale);
            $html = $this->renderTemplate('feedback_submitted.html', [
                'email_title' => $strings['email_title'],
                'logo' => $strings['logo'],
                'type' => $entryType,
                'type_label' => $entryType === 'feature' ? $strings['feature_label'] : $strings['bug_label'],
                'author_label' => $strings['author_label'],
                'author_username' => $authorUsername,
                'title_label' => $strings['title_label'],
                'title' => $title,
                'description_label' => $strings['description_label'],
                'description' => $description,
                'cta' => $strings['cta'],
                'footer' => $strings['footer'],
                'admin_url' => Settings::string('app_base_url').'/admin/feedback',
                'base_url' => Settings::string('app_base_url'),
            ], $locale);
            $prefix = $entryType === 'feature' ? $strings['feature_subject_prefix'] : $strings['bug_subject_prefix'];
            $this->dispatch($prefix.' '.$title, [Settings::string('contact_email')], $html);
        } catch (\Throwable) {
        }
    }

    public function sendReviewNotification(
        string $userDisplayName,
        int $rating,
        ?string $comment,
        string $targetLanguage,
        int $reviewId,
        string $locale = 'en',
    ): void {
        if (! Settings::bool('email_enabled') || Settings::string('contact_email') === '') {
            return;
        }
        try {
            $strings = $this->strings('_REVIEW_I18N', $locale);
            $html = $this->renderTemplate('review_submitted.html', [
                'email_title' => $strings['email_title'],
                'logo' => $strings['logo'],
                'author_label' => $strings['author_label'],
                'user_display_name' => $userDisplayName,
                'rating_label' => $strings['rating_label'],
                'rating' => $rating.'/5',
                'language_label' => $strings['language_label'],
                'target_language' => $targetLanguage,
                'comment_label' => $strings['comment_label'],
                'comment' => $comment !== null && $comment !== '' ? $comment : $strings['empty_comment'],
                'cta' => $strings['cta'],
                'admin_url' => Settings::string('app_base_url').'/admin/reviews',
                'footer' => $strings['footer'],
                'base_url' => Settings::string('app_base_url'),
            ], $locale);
            $this->dispatch(
                $strings['subject_prefix'].' '.$rating.'/5 — '.$userDisplayName,
                [Settings::string('contact_email')],
                $html,
            );
        } catch (\Throwable) {
        }
    }

    /**
     * @param  array<string, string|SafeHtml>  $context
     */
    public function renderTemplate(string $name, array $context, ?string $locale = 'en'): string
    {
        $html = (string) file_get_contents(resource_path('email/'.$name));
        $resolved = $this->resolvedLocale($locale);
        $context['locale'] = $resolved;
        foreach ($context as $key => $value) {
            $rendered = $value instanceof SafeHtml ? $value->value : $this->escape((string) $value);
            $html = str_replace('{{'.$key.'}}', $rendered, $html);
        }

        return $html;
    }

    /**
     * @return array<string, string>
     */
    private function strings(string $catalog, ?string $locale): array
    {
        $table = $this->catalogs[$catalog] ?? [];
        $resolved = $this->resolvedLocale($locale);

        return $table[$resolved] ?? $table['en'];
    }

    private function resolvedLocale(?string $locale): string
    {
        $supported = $this->catalogs['_VERIFY_I18N'] ?? [];

        return ($locale !== null && isset($supported[$locale])) ? $locale : 'en';
    }

    private function escape(string $value): string
    {
        return str_replace("'", '&#x27;', htmlspecialchars($value, ENT_COMPAT | ENT_SUBSTITUTE, 'UTF-8'));
    }

    /**
     * @param  list<string>  $recipients
     */
    private function dispatch(string $subject, array $recipients, string $html, ?string $replyTo = null, bool $rethrow = false): void
    {
        try {
            Mail::html($html, function ($message) use ($subject, $recipients, $replyTo): void {
                $message->to($recipients)->subject($subject);
                if ($replyTo !== null) {
                    $message->replyTo($replyTo);
                }
            });
        } catch (\Throwable $e) {
            if ($rethrow) {
                throw $e;
            }
        }
    }
}
