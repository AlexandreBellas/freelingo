<?php

namespace Tests\Unit;

use App\Services\Email\EmailService;
use App\Services\Email\SafeHtml;
use Illuminate\Mail\Transport\ArrayTransport;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class EmailServiceTest extends TestCase
{
    private EmailService $email;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'freelingo.email_enabled' => true,
            'freelingo.contact_email' => 'admin@example.com',
            'freelingo.app_base_url' => 'https://freelingo.example',
        ]);
        $this->email = new EmailService;
    }

    public function test_render_template_escapes_user_controlled_values(): void
    {
        $html = $this->email->renderTemplate('contact.html', [
            'email_title' => 'Contact',
            'logo' => 'FreeLingo',
            'from_label' => 'From',
            'subject_label' => 'Subject',
            'message_label' => 'Message',
            'sender_email' => 'attacker@example.com"><img src=x onerror=alert(1)>',
            'subject' => '<a href="https://phishing.example">Click</a>',
            'description' => '<strong>urgent</strong> & dangerous',
            'footer' => 'Footer',
            'base_url' => 'https://freelingo.example',
        ]);

        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        $this->assertStringNotContainsString('<a href="https://phishing.example">Click</a>', $html);
        $this->assertStringNotContainsString('<strong>urgent</strong>', $html);
        $this->assertStringContainsString('<html lang="en">', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringContainsString('&lt;a href=&quot;https://phishing.example&quot;&gt;Click&lt;/a&gt;', $html);
        $this->assertStringContainsString('&lt;strong&gt;urgent&lt;/strong&gt; &amp; dangerous', $html);
    }

    public function test_render_template_preserves_explicitly_trusted_html(): void
    {
        $html = $this->email->renderTemplate('verify_email.html', [
            'greeting' => 'Hi Student,',
            'body' => new SafeHtml('Welcome.<br /><strong>Verify now</strong>'),
            'button' => 'Verify',
            'link_fallback' => 'Copy this link:',
            'footer' => 'Footer',
            'url' => 'https://freelingo.example/verify-email?token=abc123',
            'base_url' => 'https://freelingo.example',
        ]);

        $this->assertStringContainsString('Welcome.<br /><strong>Verify now</strong>', $html);
        $this->assertStringNotContainsString('Welcome.&lt;br /&gt;&lt;strong&gt;Verify now&lt;/strong&gt;', $html);
    }

    public function test_review_template_escapes_review_fields(): void
    {
        $html = $this->email->renderTemplate('review_submitted.html', [
            'email_title' => 'Review',
            'logo' => 'FreeLingo',
            'author_label' => 'Submitted by',
            'user_display_name' => '<img src=x onerror=alert(1)>',
            'rating_label' => 'Rating',
            'rating' => '5/5',
            'language_label' => 'Learning language',
            'target_language' => 'en-US',
            'comment_label' => 'Comment',
            'comment' => '<a href="https://phishing.example">Click</a>',
            'cta' => 'View',
            'admin_url' => 'https://freelingo.example/admin/reviews',
            'footer' => 'Footer',
            'base_url' => 'https://freelingo.example',
        ]);

        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        $this->assertStringNotContainsString('<a href="https://phishing.example">Click</a>', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringContainsString('&lt;a href=&quot;https://phishing.example&quot;&gt;Click&lt;/a&gt;', $html);
    }

    public function test_each_message_declares_the_language_of_its_rendered_content(): void
    {
        $catalogs = json_decode((string) file_get_contents(resource_path('email/catalogs.json')), true);
        $locales = array_keys($catalogs['_VERIFY_I18N']);
        sort($locales);
        $locales[] = 'unsupported';
        $senders = [
            ['_VERIFY_I18N', fn (string $locale) => $this->email->sendVerificationEmail('student@example.com', 'Student', 'test-token', $locale)],
            ['_RESET_I18N', fn (string $locale) => $this->email->sendResetPasswordEmail('student@example.com', 'Student', 'test-token', $locale)],
            ['_WELCOME_I18N', fn (string $locale) => $this->email->sendWelcomeEmail('student@example.com', 'Student', $locale)],
            ['_DELETION_I18N', fn (string $locale) => $this->email->sendAccountDeletedEmail('student@example.com', 'Student', $locale)],
            ['_CONTACT_I18N', fn (string $locale) => $this->email->sendContactEmail('student@example.com', 'Subject', 'Description', $locale)],
            ['_FEEDBACK_I18N', fn (string $locale) => $this->email->sendFeedbackNotification('feature', 'Title', 'Description', 'student', 1, $locale)],
            ['_REVIEW_I18N', fn (string $locale) => $this->email->sendReviewNotification('Student', 5, null, 'en-GB', 1, $locale)],
        ];

        foreach ($locales as $locale) {
            foreach ($senders as [$catalog, $send]) {
                $this->clearMessages();
                $send($locale);
                $html = $this->lastHtml();
                $expected = isset($catalogs['_VERIFY_I18N'][$locale]) ? $locale : 'en';
                $this->assertStringContainsString('<html lang="'.$expected.'">', $html);
                $this->assertStringContainsString($this->escape($catalogs[$catalog][$expected]['footer']), $html);
                $this->assertStringNotContainsString('{{', $html);
            }
        }
    }

    private function clearMessages(): void
    {
        $this->transport()->messages()->splice(0);
    }

    private function lastHtml(): string
    {
        $sent = $this->transport()->messages()->last();
        $message = $sent->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $message);

        return (string) $message->getHtmlBody();
    }

    private function transport(): ArrayTransport
    {
        $transport = app('mail.manager')->mailer()->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);

        return $transport;
    }

    private function escape(string $value): string
    {
        return str_replace("'", '&#x27;', htmlspecialchars($value, ENT_COMPAT | ENT_SUBSTITUTE, 'UTF-8'));
    }
}
