<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Email\EmailService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ContactController extends Controller
{
    public function __construct(private readonly EmailService $email) {}

    public function store(Request $request): Response
    {
        $data = $request->json()->all();
        $email = is_array($data) ? ($data['email'] ?? null) : null;
        $subject = is_array($data) ? ($data['subject'] ?? null) : null;
        $description = is_array($data) ? ($data['description'] ?? null) : null;
        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ApiException('Validation error', 422);
        }
        if (! is_string($subject) || trim($subject) === '' || mb_strlen($subject) > 200) {
            throw new ApiException('Validation error', 422);
        }
        if (! is_string($description) || trim($description) === '' || mb_strlen($description) > 5000) {
            throw new ApiException('Validation error', 422);
        }
        $locale = User::query()->where('role', 'admin')->orderBy('id')->value('native_language') ?: 'en';
        try {
            $this->email->sendContactEmail($email, $subject, $description, $locale);
        } catch (\Throwable) {
            throw new ApiException('Failed to send message. Please try again later.', 502);
        }

        return response()->noContent();
    }
}
