<?php

namespace Tests\Feature;

use App\Models\DashboardBanner;
use App\Services\Llm\BannerTranslator;
use App\Support\TargetLanguages;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardBannerTest extends TestCase
{
    public function test_banner_revision_dismiss_and_translation(): void
    {
        [, $adminHeaders] = $this->makeAdmin();
        [$user, $userHeaders] = $this->makeUser();

        $this->api('GET', '/api/config')->assertOk()->assertJsonPath('dashboard_banner', null);
        $admin = $this->api('GET', '/api/admin/dashboard-banner', [], $adminHeaders);
        $admin->assertOk();
        $this->assertSame('null', $admin->getContent());

        $translations = $this->translations();
        $created = $this->api('PUT', '/api/admin/dashboard-banner', [
            'source_locale' => 'en',
            'is_active' => false,
            'translations' => $translations,
        ], $adminHeaders);
        $created->assertOk();
        $created->assertJsonPath('revision', 1);
        $this->api('GET', '/api/config')->assertJsonPath('dashboard_banner', null);

        $activated = $this->api('PUT', '/api/admin/dashboard-banner', [
            'source_locale' => 'en',
            'is_active' => true,
            'translations' => $translations,
        ], $adminHeaders);
        $activated->assertOk();
        $activated->assertJsonPath('revision', 1);
        $public = $this->api('GET', '/api/config')->json('dashboard_banner');
        $this->assertSame(1, $public['revision']);
        $this->assertArrayNotHasKey('source_locale', $public);
        $this->assertArrayNotHasKey('is_active', $public);

        $changed = $translations;
        $changed['fr']['description'] = 'Description modifiee';
        $this->api('PUT', '/api/admin/dashboard-banner', [
            'source_locale' => 'en',
            'is_active' => true,
            'translations' => $changed,
        ], $adminHeaders)->assertJsonPath('revision', 2);
        $this->api('PUT', '/api/admin/dashboard-banner', [
            'source_locale' => 'fr',
            'is_active' => true,
            'translations' => $changed,
        ], $adminHeaders)->assertJsonPath('revision', 3);

        $this->api('PUT', '/api/dashboard-banner/dismiss', ['revision' => 2], $userHeaders)->assertStatus(409);
        $this->api('PUT', '/api/admin/dashboard-banner', [
            'source_locale' => 'en',
            'is_active' => true,
            'translations' => $translations,
        ], $adminHeaders);
        $banner = DashboardBanner::query()->findOrFail(1);
        $this->api('PUT', '/api/dashboard-banner/dismiss', ['revision' => $banner->revision], $userHeaders)->assertNoContent();
        $this->api('PUT', '/api/dashboard-banner/dismiss', ['revision' => $banner->revision], $userHeaders)->assertNoContent();
        $this->assertSame($banner->revision, $user->refresh()->dismissed_dashboard_banner_revision);
        $this->api('GET', '/api/auth/me', [], $userHeaders)->assertJsonPath('dismissed_dashboard_banner_revision', $banner->revision);

        $this->api('PUT', '/api/admin/dashboard-banner', [
            'source_locale' => 'en',
            'is_active' => false,
            'translations' => $translations,
        ], $adminHeaders);
        $this->api('PUT', '/api/dashboard-banner/dismiss', ['revision' => $banner->revision], $userHeaders)->assertStatus(409);

        $incomplete = $translations;
        unset($incomplete['ro']);
        $incomplete['en']['title'] = '   ';
        $this->api('PUT', '/api/admin/dashboard-banner', [
            'source_locale' => 'en',
            'is_active' => true,
            'translations' => $incomplete,
        ], $adminHeaders)->assertStatus(422);

        $this->api('GET', '/api/admin/dashboard-banner', [], $userHeaders)->assertForbidden();
        $this->api('GET', '/api/admin/dashboard-banner')->assertUnauthorized();
    }

    public function test_legacy_banner_stays_readable_and_translation_preview_does_not_persist(): void
    {
        [, $headers] = $this->makeAdmin();
        $legacy = $this->translations();
        unset($legacy['tr'], $legacy['sv'], $legacy['da'], $legacy['fi'], $legacy['hr']);
        DashboardBanner::query()->create([
            'id' => 1,
            'source_locale' => 'en',
            'is_active' => true,
            'translations' => $legacy,
            'revision' => 4,
            'created_at' => Carbon::now('UTC'),
            'updated_at' => Carbon::now('UTC'),
        ]);
        $admin = $this->api('GET', '/api/admin/dashboard-banner', [], $headers);
        $admin->assertOk();
        $admin->assertJsonPath('revision', 4);
        $this->assertSame($legacy, $admin->json('translations'));
        $this->api('PUT', '/api/admin/dashboard-banner', [
            'source_locale' => 'en',
            'is_active' => true,
            'translations' => $legacy,
        ], $headers)->assertStatus(422);

        $this->app->instance(BannerTranslator::class, new class implements BannerTranslator
        {
            public function translate(string $prompt): array
            {
                $translations = [];
                foreach (TargetLanguages::BANNER_LOCALES as $locale) {
                    $translations[$locale] = [
                        'title' => 'Generated '.$locale,
                        'subtitle' => 'Subtitle '.$locale,
                        'description' => 'Description '.$locale,
                    ];
                }
                $translations['en'] = [
                    'title' => 'Wrong source',
                    'subtitle' => 'Wrong source',
                    'description' => 'Wrong source',
                ];

                return $translations;
            }
        });
        $translated = $this->api('POST', '/api/admin/dashboard-banner/translate', [
            'source_locale' => 'en',
            'title' => '  Planned maintenance  ',
            'subtitle' => '  Brief interruption  ',
            'description' => '  Service returns shortly.  ',
        ], $headers);
        $translated->assertOk();
        $this->assertEqualsCanonicalizing(TargetLanguages::BANNER_LOCALES, array_keys($translated->json('translations')));
        $this->assertSame([
            'title' => 'Planned maintenance',
            'subtitle' => 'Brief interruption',
            'description' => 'Service returns shortly.',
        ], $translated->json('translations.en'));

        $this->app->instance(BannerTranslator::class, new class implements BannerTranslator
        {
            public function translate(string $prompt): array
            {
                throw new \RuntimeException('provider secret internals');
            }
        });
        $failed = $this->api('POST', '/api/admin/dashboard-banner/translate', [
            'source_locale' => 'es',
            'title' => 'Aviso',
            'subtitle' => 'Interrupcion breve',
            'description' => 'Volveremos pronto',
        ], $headers);
        $failed->assertStatus(502);
        $failed->assertExactJson(['detail' => 'Could not translate dashboard banner']);
        $this->assertStringNotContainsString('secret', $failed->getContent());
    }

    /**
     * @return array<string, array{title: string, subtitle: string, description: string}>
     */
    private function translations(string $label = 'Notice'): array
    {
        $translations = [];
        foreach (TargetLanguages::BANNER_LOCALES as $locale) {
            $translations[$locale] = [
                'title' => $label.' '.$locale,
                'subtitle' => 'Subtitle '.$locale,
                'description' => 'Description '.$locale,
            ];
        }

        return $translations;
    }
}
