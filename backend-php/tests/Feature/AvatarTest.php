<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AvatarTest extends TestCase
{
    private const JPEG = "\xFF\xD8\xFF\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xFF\xD9";

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/freelingo-avatars-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
        config(['freelingo.avatars_path' => $this->directory]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
        parent::tearDown();
    }

    public function test_upload_jpeg_returns_200_and_stores_the_file(): void
    {
        [, $headers] = $this->makeUser();
        $response = $this->upload($headers, 'avatar.jpg', self::JPEG, 'image/jpeg');

        $response->assertOk();
        $avatar = $response->json('avatar');
        $this->assertStringStartsWith('/api/avatars/', $avatar);
        $this->assertStringEndsWith('.jpg', explode('?', $avatar)[0]);
        $this->assertStringContainsString('?v=', $avatar);
        $filename = basename(explode('?', $avatar)[0]);
        $this->assertSame(self::JPEG, file_get_contents($this->directory.'/'.$filename));
    }

    public function test_upload_png_stores_a_png_url(): void
    {
        [, $headers] = $this->makeUser();
        $png = "\x89PNG\r\n\x1A\n"."\x00\x00\x00\x0DIHDR".pack('N', 1).pack('N', 1)."\x08\x06\x00\x00\x00";
        $response = $this->upload($headers, 'avatar.png', $png, 'image/png');

        $response->assertOk();
        $avatar = $response->json('avatar');
        $this->assertStringStartsWith('/api/avatars/', $avatar);
        $this->assertStringEndsWith('.png', explode('?', $avatar)[0]);
        $this->assertFileExists($this->directory.'/'.basename(explode('?', $avatar)[0]));
    }

    public function test_upload_rejects_the_wrong_type_invalid_bytes_and_oversized_files(): void
    {
        [, $headers] = $this->makeUser();

        $gif = $this->upload($headers, 'avatar.gif', 'GIF89a', 'image/gif');
        $gif->assertStatus(400);
        $this->assertStringContainsString('JPEG', (string) $gif->json('detail'));

        $invalid = $this->upload($headers, 'avatar.jpg', 'not an image', 'image/jpeg');
        $invalid->assertStatus(400);
        $this->assertSame('Invalid image file', $invalid->json('detail'));

        $tooLarge = $this->upload($headers, 'big.jpg', str_repeat('x', 2 * 1024 * 1024 + 1), 'image/jpeg');
        $tooLarge->assertStatus(400);
        $this->assertStringContainsString('2 MB', (string) $tooLarge->json('detail'));
    }

    public function test_upload_requires_auth(): void
    {
        $this->upload([], 'avatar.jpg', self::JPEG, 'image/jpeg')->assertUnauthorized();
    }

    public function test_reupload_replaces_the_previous_file(): void
    {
        [, $headers] = $this->makeUser();
        $first = "\xFF\xD8\xFF".str_repeat("\xAA", 16)."\xFF\xD9";
        $second = "\xFF\xD8\xFF".str_repeat("\xBB", 16)."\xFF\xD9";
        $old = $this->upload($headers, 'avatar.jpg', $first, 'image/jpeg')->json('avatar');
        $oldPath = $this->directory.'/'.basename(explode('?', $old)[0]);
        $this->assertFileExists($oldPath);

        $png = "\x89PNG\r\n\x1A\n"."\x00\x00\x00\x0DIHDR".pack('N', 1).pack('N', 1)."\x08\x06\x00\x00\x00";
        $replaced = $this->upload($headers, 'avatar.png', $png, 'image/png');
        $replaced->assertOk();
        $this->assertFileDoesNotExist($oldPath);
        $this->assertStringEndsWith('.png', explode('?', $replaced->json('avatar'))[0]);

        $again = $this->upload($headers, 'avatar.jpg', $second, 'image/jpeg');
        $filename = basename(explode('?', $again->json('avatar'))[0]);
        $this->assertSame($second, file_get_contents($this->directory.'/'.$filename));
    }

    public function test_upload_over_a_legacy_data_uri_does_not_crash(): void
    {
        [$user, $headers] = $this->makeUser();
        $user->avatar = 'data:image/jpeg;base64,/9j/fakebase64==';
        $user->save();

        $response = $this->upload($headers, 'avatar.jpg', self::JPEG, 'image/jpeg');
        $response->assertOk();
        $this->assertStringStartsWith('/api/avatars/', $response->json('avatar'));
        $this->assertStringEndsWith('.jpg', explode('?', $response->json('avatar'))[0]);
    }

    public function test_delete_clears_the_field_and_the_file(): void
    {
        [, $headers] = $this->makeUser();
        $uploaded = $this->upload($headers, 'avatar.jpg', self::JPEG, 'image/jpeg');
        $path = $this->directory.'/'.basename(explode('?', $uploaded->json('avatar'))[0]);

        $deleted = $this->api('DELETE', '/api/auth/me/avatar', [], $headers);
        $deleted->assertOk();
        $this->assertNull($deleted->json('avatar'));
        $this->assertFileDoesNotExist($path);

        $me = $this->api('GET', '/api/auth/me', [], $headers);
        $this->assertNull($me->json('avatar'));
    }

    public function test_delete_without_an_avatar_or_with_a_legacy_uri_is_ok(): void
    {
        [$user, $headers] = $this->makeUser();
        $empty = $this->api('DELETE', '/api/auth/me/avatar', [], $headers);
        $empty->assertOk();
        $this->assertNull($empty->json('avatar'));

        $user->avatar = 'data:image/jpeg;base64,/9j/fakebase64==';
        $user->save();
        $legacy = $this->api('DELETE', '/api/auth/me/avatar', [], $headers);
        $legacy->assertOk();
        $this->assertNull($legacy->json('avatar'));
    }

    public function test_delete_requires_auth(): void
    {
        $this->api('DELETE', '/api/auth/me/avatar')->assertUnauthorized();
    }

    public function test_profile_reflects_the_upload_and_the_file_is_not_public(): void
    {
        [, $headers] = $this->makeUser();
        $avatar = $this->upload($headers, 'avatar.jpg', self::JPEG, 'image/jpeg')->json('avatar');
        $me = $this->api('GET', '/api/auth/me', [], $headers);
        $this->assertStringStartsWith('/api/avatars/', $me->json('avatar'));
        $this->assertStringEndsWith('.jpg', explode('?', $me->json('avatar'))[0]);

        $this->get(explode('?', $avatar)[0])->assertNotFound();
    }

    public function test_avatar_file_serves_disk_and_legacy_payloads(): void
    {
        [$user, $headers] = $this->makeUser();
        $this->upload($headers, 'avatar.jpg', self::JPEG, 'image/jpeg');
        $file = $this->withHeaders($headers)->get('/api/auth/me/avatar-file');
        $file->assertOk();
        $this->assertSame('image/jpeg', $file->headers->get('Content-Type'));
        $cache = (string) $file->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cache);
        $this->assertStringContainsString('no-store', $cache);
        $bytes = file_get_contents($file->baseResponse->getFile()->getPathname());
        $this->assertSame(hash('sha256', self::JPEG), hash('sha256', (string) $bytes));

        $user->refresh();
        $user->avatar = 'data:image/png;base64,'.base64_encode('png-bytes');
        $user->save();
        $legacy = $this->withHeaders($headers)->get('/api/auth/me/avatar-file');
        $legacy->assertOk();
        $this->assertSame('image/png', $legacy->headers->get('Content-Type'));
        $this->assertSame('png-bytes', $legacy->getContent());

        User::query()->whereKey($user->id)->update(['avatar' => null]);
        $this->withHeaders($headers)->get('/api/auth/me/avatar-file')->assertNotFound();
    }

    public function test_deleting_the_account_removes_the_avatar_file(): void
    {
        [, $headers] = $this->makeUser();
        $avatar = $this->upload($headers, 'avatar.jpg', self::JPEG, 'image/jpeg')->json('avatar');
        $path = $this->directory.'/'.basename(explode('?', $avatar)[0]);
        $this->api('DELETE', '/api/auth/me', [], $headers)->assertNoContent();
        $this->assertFileDoesNotExist($path);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function upload(array $headers, string $name, string $contents, string $mime): \Illuminate\Testing\TestResponse
    {
        $previous = $this->defaultHeaders;
        $this->defaultHeaders = [];
        $response = $this->withHeaders($headers)->post('/api/auth/me/avatar', [
            'file' => UploadedFile::fake()->createWithContent($name, $contents)->mimeType($mime),
        ]);
        $this->defaultHeaders = $previous;

        return $response;
    }
}
