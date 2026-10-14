<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\ResolvesApiUser;
use App\Services\Practice\Catalog;
use App\Services\Practice\NativeHelp;
use App\Services\Practice\Prompts;
use App\Services\Practice\SpeechGateway;
use App\Services\Speech\OpenAiTtsService;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PhrasebookController extends Controller
{
    use ResolvesApiUser;

    /** @var list<string> */
    private const LEVELS = ['A1', 'A2', 'B1', 'B2', 'C1', 'C2'];

    public function index(Request $request): JsonResponse
    {
        $language = (string) $request->query('language', 'en-GB');

        return response()->json(['categories' => Catalog::phrasebookCategories($language)]);
    }

    public function byLevel(Request $request, string $level): JsonResponse
    {
        $upper = strtoupper($level);
        if (! in_array($upper, self::LEVELS, true)) {
            throw new ApiException('Invalid CEFR level. Must be one of: '.implode(', ', self::LEVELS), 400);
        }
        $language = (string) $request->query('language', 'en-GB');

        return response()->json(['categories' => Catalog::phrasebookByLevel($upper, $language)]);
    }

    public function show(Request $request, string $categoryId): JsonResponse
    {
        $language = (string) $request->query('language', 'en-GB');
        $category = Catalog::phrasebookCategory($categoryId, $language);
        if ($category === null) {
            throw new ApiException('Phrasebook category not found', 404);
        }

        return response()->json(['category' => $category]);
    }

    public function nativeHelp(Request $request, string $categoryId, NativeHelp $help): JsonResponse
    {
        $language = (string) $request->query('language', 'en-GB');
        $category = Catalog::phrasebookCategory($categoryId, $language);
        if ($category === null) {
            throw new ApiException('Phrasebook category not found', 404);
        }
        $source = [
            'id' => $category['id'],
            'level' => $category['level'],
            'situation' => $category['situation'],
            'phrases' => array_map(fn (array $phrase) => [
                'text' => $phrase['text'],
                'context' => $phrase['context'] ?? null,
                'register' => $phrase['register'] ?? null,
                'romanization' => $phrase['romanization'] ?? null,
            ], $category['phrases'] ?? []),
        ];
        $content = $help->resolve(
            $this->apiUser($request),
            'phrasebook',
            $category['id'],
            $language,
            $source,
            fn (string $target, string $native, string $json) => Prompts::phrasebookNativeHelp($target, $native, $json),
        );

        return response()->json(['native_help' => $content]);
    }

    public function audio(Request $request, string $categoryId, int $phraseIndex, SpeechGateway $speech): BinaryFileResponse
    {
        $language = (string) $request->query('language', 'en-GB');
        $category = Catalog::phrasebookCategory($categoryId, $language);
        if ($category === null) {
            throw new ApiException('Phrasebook category not found', 404);
        }
        $phrases = $category['phrases'] ?? [];
        if ($phraseIndex < 0 || $phraseIndex >= count($phrases)) {
            throw new ApiException('Phrase not found', 404);
        }
        $phrase = $phrases[$phraseIndex];
        $iso = explode('-', $language)[0];
        $cacheKey = substr(hash('sha256', "{$language}:{$categoryId}:{$phraseIndex}:{$phrase['text']}"), 0, 16);
        $tts = $speech->tts;
        if ($tts instanceof OpenAiTtsService && $tts->getInstructions($language)) {
            $cacheKey = $tts->getCacheKey($phrase['text'], language: $language);
        }
        $directory = rtrim(Settings::string('audio_storage_path', storage_path('app/audio')), '/').'/phrasebook/'.$iso;
        $path = $directory.'/'.$cacheKey.'.mp3';
        if (! is_file($path)) {
            if ($tts === null) {
                throw new ApiException('TTS service is not enabled', 503);
            }
            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            $audio = $tts->synthesize($phrase['text'], null, $language);
            $temporary = $path.'.tmp';
            file_put_contents($temporary, $audio);
            rename($temporary, $path);
        }

        $response = new BinaryFileResponse($path, 200, [
            'Content-Type' => 'audio/mpeg',
            'Cache-Control' => 'no-store',
        ], false, null, false, false);
        $property = new \ReflectionProperty($response->headers, 'headers');
        $headers = $property->getValue($response->headers);
        $headers['cache-control'] = ['no-store'];
        $property->setValue($response->headers, $headers);

        return $response;
    }
}
