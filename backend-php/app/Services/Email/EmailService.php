<?php

namespace App\Services\Email;

use App\Support\Settings;

class EmailService
{
    public function sendVerificationEmail(string $email, string $displayName, string $token, ?string $locale = null): void
    {
        if (! Settings::bool('email_enabled')) {
            return;
        }
    }

    public function sendWelcomeEmail(string $email, string $displayName, ?string $locale = null): void
    {
        if (! Settings::bool('email_enabled')) {
            return;
        }
    }

    public function sendAccountDeletedEmail(?string $email, string $displayName, ?string $locale = null): void
    {
        if (! Settings::bool('email_enabled') || ! $email) {
            return;
        }
    }

    public function sendResetPasswordEmail(string $email, string $displayName, string $token, ?string $locale = null): void
    {
        if (! Settings::bool('email_enabled')) {
            return;
        }
    }

    public function sendContactEmail(string $senderEmail, string $subject, string $description, string $locale = 'en'): void
    {
        if (! Settings::bool('email_enabled') || Settings::string('contact_email') === '') {
            return;
        }
        $body = '<p>'.e($senderEmail).'</p><p>'.e($subject).'</p><p>'.e($description).'</p>';
        \Illuminate\Support\Facades\Mail::html($body, function ($message) use ($senderEmail, $subject): void {
            $message->to(Settings::string('contact_email'))
                ->subject($subject)
                ->replyTo($senderEmail);
        });
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
            $body = '<p>'.e($entryType).'</p><p>'.e($authorUsername).'</p><p>'.e($title).'</p><p>'.e($description).'</p>';
            \Illuminate\Support\Facades\Mail::html($body, function ($message) use ($title): void {
                $message->to(Settings::string('contact_email'))->subject($title);
            });
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
            $body = '<p>'.e($userDisplayName).'</p><p>'.e((string) $rating).'</p><p>'.e((string) $comment).'</p>';
            \Illuminate\Support\Facades\Mail::html($body, function ($message) use ($userDisplayName, $rating): void {
                $message->to(Settings::string('contact_email'))->subject($rating.'/5 — '.$userDisplayName);
            });
        } catch (\Throwable) {
        }
    }
}
