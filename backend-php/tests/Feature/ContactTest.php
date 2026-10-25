<?php

namespace Tests\Feature;

use App\Services\Email\EmailService;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ContactTest extends TestCase
{
    public function test_contact_uses_first_admin_locale_and_handles_mail_failures(): void
    {
        [$admin] = $this->makeAdmin();
        $admin->native_language = 'it';
        $admin->save();

        $mock = \Mockery::mock(EmailService::class);
        $mock->shouldReceive('sendContactEmail')
            ->once()
            ->with('sender@example.com', 'Question', 'Can you help?', 'it');
        $this->app->instance(EmailService::class, $mock);

        $this->api('POST', '/api/contact', [
            'email' => 'sender@example.com',
            'subject' => 'Question',
            'description' => 'Can you help?',
        ])->assertNoContent();
    }

    public function test_contact_returns_502_when_email_send_fails(): void
    {
        $this->makeAdmin();
        config([
            'freelingo.email_enabled' => true,
            'freelingo.contact_email' => 'inbox@example.com',
        ]);
        Mail::shouldReceive('html')->once()->andThrow(new \RuntimeException('SMTP unavailable'));

        $response = $this->api('POST', '/api/contact', [
            'email' => 'sender@example.com',
            'subject' => 'Question',
            'description' => 'Can you help?',
        ]);
        $response->assertStatus(502);
        $response->assertJsonPath('detail', 'Failed to send message. Please try again later.');
    }

    public function test_contact_succeeds_when_email_is_disabled_or_unconfigured(): void
    {
        $this->makeAdmin();
        config(['freelingo.email_enabled' => false]);
        $this->api('POST', '/api/contact', [
            'email' => 'sender@example.com',
            'subject' => 'Question',
            'description' => 'Can you help?',
        ])->assertNoContent();

        config([
            'freelingo.email_enabled' => true,
            'freelingo.contact_email' => '',
        ]);
        Mail::fake();
        $this->api('POST', '/api/contact', [
            'email' => 'sender@example.com',
            'subject' => 'Question',
            'description' => 'Can you help?',
        ])->assertNoContent();
        Mail::assertNothingSent();

        $this->api('POST', '/api/contact', [
            'email' => 'not-an-email',
            'subject' => 'Question',
            'description' => 'Can you help?',
        ])->assertStatus(422);
    }
}
