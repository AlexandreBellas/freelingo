<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class AvatarService
{
    private const MAX_BYTES = 2 * 1024 * 1024;

    /** @var list<string> */
    private const ALLOWED_TYPES = ['image/jpeg', 'image/png'];

    public function upload(User $user, ?UploadedFile $file): User
    {
        if ($file === null) {
            throw new ApiException('Invalid image file', 400);
        }
        $data = (string) file_get_contents($file->getRealPath());
        if (strlen($data) > self::MAX_BYTES) {
            throw new ApiException('Image too large (max 2 MB)', 400);
        }
        $extension = $this->validate($file->getClientMimeType() ?: $file->getMimeType(), $data);

        $oldPath = $this->pathFromReference($user->avatar);
        if ($oldPath !== null && is_file($oldPath)) {
            unlink($oldPath);
        }

        $directory = $this->directory();
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        $filename = str_replace('-', '', (string) \Illuminate\Support\Str::uuid()).'.'.$extension;
        file_put_contents($directory.DIRECTORY_SEPARATOR.$filename, $data);

        $stamp = (int) (Carbon::now('UTC')->getTimestamp() * 1000);
        $user->avatar = '/api/avatars/'.$filename.'?v='.$stamp;
        $user->save();

        return $user->refresh();
    }

    public function file(User $user): Response
    {
        $avatar = $user->avatar;
        if (! $avatar) {
            throw new ApiException('Avatar not found', 404);
        }
        if (str_starts_with($avatar, 'data:image/')) {
            [$header, $payload] = array_pad(explode(',', $avatar, 2), 2, '');
            $media = explode(';', substr($header, strlen('data:')))[0];
            $bytes = base64_decode($payload, true);
            if ($bytes === false || $media === '') {
                throw new ApiException('Avatar not found', 404);
            }

            return response($bytes, 200, [
                'Content-Type' => $media,
                'Cache-Control' => 'private, no-store',
            ]);
        }
        $path = $this->pathFromReference($avatar);
        if ($path === null || ! is_file($path)) {
            throw new ApiException('Avatar not found', 404);
        }
        $filename = basename($path);
        $media = str_ends_with(strtolower($filename), '.jpg') ? 'image/jpeg' : 'image/png';

        $response = new BinaryFileResponse($path, 200, ['Content-Type' => $media], false);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function delete(User $user): User
    {
        $this->deleteStoredFile($user);
        $user->avatar = null;
        $user->save();

        return $user->refresh();
    }

    public function deleteStoredFile(User $user): void
    {
        $path = $this->pathFromReference($user->avatar);
        if ($path !== null && is_file($path)) {
            unlink($path);
        }
    }

    private function validate(?string $contentType, string $data): string
    {
        if (! in_array($contentType, self::ALLOWED_TYPES, true)) {
            throw new ApiException('Only JPEG and PNG images are allowed', 400);
        }
        if ($contentType === 'image/jpeg' && str_starts_with($data, "\xFF\xD8\xFF")) {
            if (strlen($data) < 4 || ! str_ends_with($data, "\xFF\xD9")) {
                throw new ApiException('Invalid image file', 400);
            }

            return 'jpg';
        }
        if ($contentType === 'image/png' && str_starts_with($data, "\x89PNG\r\n\x1A\n")) {
            if (strlen($data) < 24 || substr($data, 12, 4) !== 'IHDR') {
                throw new ApiException('Invalid image file', 400);
            }
            $width = unpack('N', substr($data, 16, 4))[1];
            $height = unpack('N', substr($data, 20, 4))[1];
            if ($width <= 0 || $height <= 0) {
                throw new ApiException('Invalid image file', 400);
            }

            return 'png';
        }

        throw new ApiException('Invalid image file', 400);
    }

    private function pathFromReference(?string $avatar): ?string
    {
        if ($avatar === null || ! str_starts_with($avatar, '/api/avatars/')) {
            return null;
        }
        $filename = basename(explode('?', $avatar)[0]);
        if ($filename === '' || $filename === '.' || $filename === '..') {
            return null;
        }

        return $this->directory().DIRECTORY_SEPARATOR.$filename;
    }

    private function directory(): string
    {
        return Settings::string('avatars_path', storage_path('app/avatars'));
    }
}
