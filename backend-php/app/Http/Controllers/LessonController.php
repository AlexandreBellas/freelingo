<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Models\Exercise;
use App\Models\Lesson;
use App\Models\StudyPlan;
use App\Services\Learning\FreemiumGate;
use App\Services\Learning\LearningCatalog;
use App\Services\Learning\LessonGenerator;
use App\Services\Learning\Presenters;
use App\Services\Learning\ProgressRewards;
use App\Services\Learning\ProgressService;
use App\Services\Learning\RequestChecks;
use App\Services\Llm\LlmException;
use App\Services\Prompts\LessonPrompts;
use App\Services\Llm\StructuredOutput;
use App\Services\Llm\LlmClient;
use App\Support\Clock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LessonController extends Controller
{
    private StructuredOutput $structured;

    public function __construct(
        private readonly LessonGenerator $generator,
        private readonly ProgressService $progress,
        private readonly ProgressRewards $rewards,
        private readonly FreemiumGate $freemium,
        LlmClient $llm,
    ) {
        $this->structured = new StructuredOutput($llm);
    }

    public function show(Request $request, int $lessonId): JsonResponse
    {
        $user = Presenters::user($request->attributes->get('user'));
        $lesson = $this->ownedLesson($lessonId, $user->id);
        $exercises = Exercise::query()->where('lesson_id', $lessonId)->orderBy('id')->get();
        $contentExercises = is_array($lesson->content) && is_array($lesson->content['exercises'] ?? null)
            ? $lesson->content['exercises']
            : [];
        $fixed = [];
        foreach ($exercises as $index => $exercise) {
            $normalized = $this->generator->normalizedText($exercise->exercise_type, $exercise->question, $exercise->explanation);
            $nativeExplanation = null;
            $nativeHint = null;
            $content = $contentExercises[$index] ?? null;
            if (is_array($content)) {
                $nativeExplanation = is_string($content['native_explanation'] ?? null) ? $content['native_explanation'] : null;
                $nativeHint = is_string($content['native_hint'] ?? null) ? $content['native_hint'] : null;
            }
            $fixed[] = Presenters::exercise($exercise, $normalized['question'], $normalized['explanation'], $nativeExplanation, $nativeHint);
        }
        $plan = StudyPlan::query()->find($lesson->study_plan_id);

        return response()->json([
            'lesson' => Presenters::lesson($lesson),
            'exercises' => $fixed,
            'target_language' => $plan?->target_language,
        ]);
    }

    public function start(Request $request, int $lessonId): JsonResponse
    {
        $lesson = $this->ownedLesson($lessonId, Presenters::user($request->attributes->get('user'))->id);

        return response()->json(Presenters::lesson($lesson));
    }

    public function complete(Request $request, int $lessonId): JsonResponse
    {
        $user = Presenters::user($request->attributes->get('user'));
        $lesson = $this->ownedLesson($lessonId, $user->id);
        $already = (bool) $lesson->is_completed;
        if (! $already) {
            $this->freemium->assertLessons($user);
        }
        $completedAt = Clock::now();
        $activityDate = $completedAt->format('Y-m-d');
        DB::transaction(function () use ($lesson, $already, $user, $completedAt, $activityDate) {
            if (! $already) {
                $lesson->is_completed = true;
                $lesson->completed_at = $completedAt;
                $lesson->save();
                $this->progress->updateDaily(
                    $user->id,
                    $lesson->study_plan_id,
                    lessonCompleted: true,
                    activityDate: $activityDate,
                );
                if ($lesson->unit_id) {
                    $plan = StudyPlan::query()->find($lesson->study_plan_id);
                    if ($plan) {
                        foreach (LearningCatalog::units($plan->cefr_level, $plan->target_language) as $unit) {
                            if ($unit['id'] === $lesson->unit_id) {
                                $scores = Exercise::query()->where('lesson_id', $lesson->id)->whereNotNull('score')->pluck('score');
                                $lessonScore = $scores->isEmpty() ? 0.5 : $scores->avg();
                                $this->progress->upsertUnitCompetency(
                                    $user->id,
                                    $lesson->unit_id,
                                    $unit['competency_checklist'] ?? [],
                                    (float) $lessonScore,
                                    $lesson->study_plan_id,
                                );
                                break;
                            }
                        }
                    }
                }
            }
            if ($lesson->unit_id) {
                $this->rewards->rewardPlanCompletion($user->id, $lesson->study_plan_id, $lesson->unit_id, $activityDate);
            }
            $this->rewards->rewardPlanCompletion($user->id, $lesson->study_plan_id, null, $activityDate);
        });
        if (! $already) {
            $this->freemium->recordLesson($user->fresh() ?? $user);
        }

        return response()->json(Presenters::lesson($lesson->refresh()));
    }

    public function answer(Request $request, int $exerciseId): JsonResponse
    {
        $user = Presenters::user($request->attributes->get('user'));
        $data = RequestChecks::body($request->json()->all());
        $answer = RequestChecks::requireString($data, 'answer');
        $exercise = Exercise::query()->find($exerciseId);
        if (! $exercise) {
            throw new ApiException('Exercise not found', 404);
        }
        $lesson = $this->ownedLesson($exercise->lesson_id, $user->id);
        $plan = StudyPlan::query()->find($lesson->study_plan_id);
        if (! $plan) {
            throw new ApiException('Study plan not found for lesson', 500);
        }
        if ($exercise->answered_at !== null) {
            throw new ApiException('Exercise already answered', 409);
        }
        $corrections = null;
        if ($exercise->exercise_type === 'free_write') {
            $criteria = array_values(array_filter($exercise->options ?? [], fn ($opt) => is_string($opt) && trim($opt) !== ''));
            if ($criteria === []) {
                $criteria = ['grammar', 'spelling', 'coherence'];
            }
            try {
                $result = $this->generator->evaluateFreeWrite(
                    $lesson->cefr_level,
                    $exercise->question,
                    $criteria,
                    $answer,
                    $plan->target_language,
                    $user->native_language,
                );
                $exercise->score = $result['score'];
                $exercise->feedback = $result['feedback'];
                $corrections = $result['corrections'] !== [] ? $result['corrections'] : null;
                $exercise->corrections = $corrections;
            } catch (LlmException) {
                $exercise->score = 0.5;
                $exercise->feedback = $this->feedback($user->native_language, 'free_write_unavailable');
            }
        } elseif ($exercise->exercise_type === 'fill_blank') {
            try {
                $result = $this->generator->evaluateFillBlank(
                    $lesson->cefr_level,
                    $exercise->question,
                    $exercise->correct_answer,
                    $answer,
                    $plan->target_language,
                    $user->native_language,
                );
                $exercise->score = $result['score'];
                $exercise->feedback = $result['feedback'];
            } catch (LlmException) {
                $ua = rtrim(strtolower(trim($answer)), '.,!?');
                $ca = rtrim(strtolower(trim($exercise->correct_answer)), '.,!?');
                $alternatives = array_map(fn ($part) => strtolower(trim($part)), explode('/', $ca));
                $correct = $ua === $ca || in_array($ua, $alternatives, true);
                $exercise->score = $correct ? 1.0 : 0.0;
                $exercise->feedback = $correct
                    ? $this->feedback($user->native_language, 'correct')
                    : $this->feedback($user->native_language, 'correct_answer', $exercise->correct_answer);
            }
        } elseif ($exercise->exercise_type === 'pronunciation') {
            try {
                $result = $this->generator->evaluatePronunciation(
                    $lesson->cefr_level,
                    $exercise->correct_answer,
                    $answer,
                    $plan->target_language,
                    $user->native_language,
                );
                $exercise->score = $result['score'];
                $exercise->feedback = $result['feedback'];
            } catch (LlmException) {
                $normTarget = strtolower(trim((string) preg_replace('/[^\w\s]/u', '', $exercise->correct_answer)));
                $normAnswer = strtolower(trim((string) preg_replace('/[^\w\s]/u', '', $answer)));
                $close = $normTarget === $normAnswer || str_contains($normAnswer, $normTarget) || str_contains($normTarget, $normAnswer);
                $exercise->score = $close ? 1.0 : 0.0;
                $exercise->feedback = $close
                    ? $this->feedback($user->native_language, 'good_pronunciation')
                    : $this->feedback($user->native_language, 'target_phrase', $exercise->correct_answer);
            }
        } else {
            $userAns = strtolower(trim($answer));
            $correctAns = strtolower(trim($exercise->correct_answer));
            $stripped = (string) preg_replace('/^[a-z]\. */', '', $userAns);
            $correct = $userAns === $correctAns
                || $stripped === $correctAns
                || $userAns === (string) preg_replace('/^[a-z]\. */', '', $correctAns);
            $exercise->score = $correct ? 1.0 : 0.0;
            $exercise->feedback = $correct
                ? $this->feedback($user->native_language, 'correct')
                : $this->feedback($user->native_language, 'correct_answer', $exercise->correct_answer);
        }
        $exercise->user_answer = $answer;
        $exercise->answered_at = Clock::now();
        $exercise->save();
        $this->progress->updateDaily(
            $user->id,
            $lesson->study_plan_id,
            exerciseCorrect: $exercise->score >= 0.5,
            skill: $lesson->lesson_type,
            skillScore: (float) $exercise->score,
        );

        return response()->json([
            'id' => $exercise->id,
            'score' => (float) $exercise->score,
            'feedback' => $exercise->feedback,
            'correct_answer' => $exercise->correct_answer,
            'corrections' => $exercise->corrections,
        ]);
    }

    public function regenerate(Request $request, int $exerciseId): JsonResponse
    {
        $user = Presenters::user($request->attributes->get('user'));
        $exercise = Exercise::query()->find($exerciseId);
        if (! $exercise) {
            throw new ApiException('Exercise not found', 404);
        }
        $lesson = $this->ownedLesson($exercise->lesson_id, $user->id);
        if ($lesson->is_completed) {
            throw new ApiException('Completed lesson exercises cannot be regenerated', 409);
        }
        if ($exercise->answered_at !== null || $exercise->score !== null) {
            throw new ApiException('Answered exercises cannot be regenerated', 409);
        }
        if (! $this->technicalError($exercise)) {
            throw new ApiException('Exercise does not need regeneration', 400);
        }
        $plan = StudyPlan::query()->find($lesson->study_plan_id);
        if (! $plan) {
            throw new ApiException('Study plan not found for lesson', 500);
        }
        [$content, $contentExercises, $index] = $this->contentEntry($exercise, $lesson);
        try {
            $regenerated = $this->generator->regenerate(
                $lesson->cefr_level,
                $lesson->lesson_type,
                $lesson->title,
                $exercise->exercise_type,
                is_array($content['explanation'] ?? null) ? $content['explanation'] : [],
                is_array($content['vocabulary'] ?? null) ? $content['vocabulary'] : [],
                [
                    'type' => $exercise->exercise_type,
                    'question' => $exercise->question,
                    'options' => $exercise->options,
                    'correct' => $exercise->correct_answer,
                    'explanation' => $exercise->explanation,
                ],
                $plan->target_language,
                $user->native_language,
            );
        } catch (\Throwable) {
            throw new ApiException('Could not regenerate exercise at this time', 503);
        }
        $exercise->question = $regenerated['question'];
        $exercise->options = $regenerated['options'] ?? null;
        $exercise->correct_answer = $regenerated['correct'];
        $exercise->explanation = $regenerated['explanation'] ?? null;
        $exercise->feedback = null;
        $exercise->user_answer = null;
        $exercise->score = null;
        $exercise->answered_at = null;
        $exercise->save();
        $contentExercises[$index] = $regenerated;
        $content['exercises'] = $contentExercises;
        $lesson->content = $content;
        $lesson->save();

        return response()->json(Presenters::exercise(
            $exercise->refresh(),
            nativeExplanation: is_string($regenerated['native_explanation'] ?? null) ? $regenerated['native_explanation'] : null,
            nativeHint: is_string($regenerated['native_hint'] ?? null) ? $regenerated['native_hint'] : null,
        ));
    }

    public function exerciseNativeExplanation(Request $request, int $exerciseId): JsonResponse
    {
        $user = Presenters::user($request->attributes->get('user'));
        $exercise = Exercise::query()->find($exerciseId);
        if (! $exercise) {
            throw new ApiException('Exercise not found', 404);
        }
        $lesson = $this->ownedLesson($exercise->lesson_id, $user->id);
        [$content, $contentExercises, , $entry] = $this->contentEntry($exercise, $lesson);
        $cached = $entry['native_explanation'] ?? null;
        if (is_string($cached) && trim($cached) !== '') {
            return response()->json(['native_explanation' => $cached]);
        }
        if (! $exercise->explanation) {
            throw new ApiException('Exercise has no explanation to translate', 400);
        }
        $plan = StudyPlan::query()->find($lesson->study_plan_id);
        $target = $plan?->target_language ?: 'en-GB';
        $prompt = LessonPrompts::nativeExerciseExplanation([
            'target_language_name' => LearningCatalog::languageName($target),
            'native_language_name' => LearningCatalog::nativeLanguageName($user->native_language),
            'exercise_type' => $exercise->exercise_type,
            'question' => $exercise->question,
            'correct_answer' => $exercise->correct_answer,
            'explanation' => $exercise->explanation,
        ]);
        try {
            $result = $this->structured->decode(
                [['role' => 'user', 'content' => $prompt]],
                function (mixed $data) {
                    if (! is_array($data) || ! is_string($data['native_explanation'] ?? null)) {
                        throw new \InvalidArgumentException('native explanation missing');
                    }

                    return $data['native_explanation'];
                },
            );
        } catch (LlmException) {
            throw new ApiException('Could not generate native exercise explanation at this time', 503);
        }
        $index = $this->exerciseIndex($exercise, $lesson);
        $contentExercises[$index]['native_explanation'] = $result;
        $content['exercises'] = $contentExercises;
        $lesson->content = $content;
        $lesson->save();

        return response()->json(['native_explanation' => $result]);
    }

    public function exerciseNativeHint(Request $request, int $exerciseId): JsonResponse
    {
        $user = Presenters::user($request->attributes->get('user'));
        $exercise = Exercise::query()->find($exerciseId);
        if (! $exercise) {
            throw new ApiException('Exercise not found', 404);
        }
        $lesson = $this->ownedLesson($exercise->lesson_id, $user->id);
        [$content, $contentExercises, , $entry] = $this->contentEntry($exercise, $lesson);
        $cached = $entry['native_hint'] ?? null;
        if (is_string($cached) && trim($cached) !== '') {
            return response()->json(['native_hint' => $cached]);
        }
        $plan = StudyPlan::query()->find($lesson->study_plan_id);
        $target = $plan?->target_language ?: 'en-GB';
        $prompt = LessonPrompts::nativeExerciseHint([
            'target_language_name' => LearningCatalog::languageName($target),
            'native_language_name' => LearningCatalog::nativeLanguageName($user->native_language),
            'exercise_type' => $exercise->exercise_type,
            'question' => $exercise->question,
            'options' => json_encode($exercise->options ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'correct_answer' => $exercise->correct_answer,
            'explanation' => $exercise->explanation ?? '',
        ]);
        try {
            $hint = $this->structured->decode(
                [['role' => 'user', 'content' => $prompt]],
                function (mixed $data) use ($exercise) {
                    if (! is_array($data) || ! is_string($data['native_hint'] ?? null)) {
                        throw new \InvalidArgumentException('native hint missing');
                    }
                    if ($this->generator->hintRevealsAnswer($data['native_hint'], $exercise->correct_answer)) {
                        throw new LlmException('Generated hint revealed the answer');
                    }

                    return $data['native_hint'];
                },
            );
        } catch (LlmException) {
            throw new ApiException('Could not generate native exercise hint at this time', 503);
        }
        $index = $this->exerciseIndex($exercise, $lesson);
        $contentExercises[$index]['native_hint'] = $hint;
        $content['exercises'] = $contentExercises;
        $lesson->content = $content;
        $lesson->save();

        return response()->json(['native_hint' => $hint]);
    }

    public function nativeExplanation(Request $request, int $lessonId): JsonResponse
    {
        $user = Presenters::user($request->attributes->get('user'));
        $lesson = $this->ownedLesson($lessonId, $user->id);
        $content = is_array($lesson->content) ? $lesson->content : [];
        if (! empty($content['native_explanation'])) {
            return response()->json(['native_explanation' => $content['native_explanation']]);
        }
        $explanation = $content['explanation'] ?? null;
        if (! is_array($explanation) || $explanation === []) {
            throw new ApiException('Lesson has no explanation to translate', 400);
        }
        $plan = StudyPlan::query()->find($lesson->study_plan_id);
        $target = $plan?->target_language ?: 'en-GB';
        $prompt = LessonPrompts::nativeExplanation([
            'target_language_name' => LearningCatalog::languageName($target),
            'native_language_name' => LearningCatalog::nativeLanguageName($user->native_language),
            'source_explanation' => json_encode($explanation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        try {
            $native = $this->structured->decode(
                [['role' => 'user', 'content' => $prompt]],
                function (mixed $data) {
                    if (! is_array($data) || ! isset($data['text'], $data['key_points'], $data['examples'])) {
                        throw new \InvalidArgumentException('native explanation is incomplete');
                    }

                    return $data;
                },
            );
        } catch (LlmException) {
            throw new ApiException('Could not generate native explanation at this time', 503);
        }
        $content['native_explanation'] = $native;
        $lesson->content = $content;
        $lesson->save();

        return response()->json(['native_explanation' => $native]);
    }

    private function ownedLesson(int $lessonId, int $userId): Lesson
    {
        $lesson = Lesson::query()
            ->select('lessons.*')
            ->join('study_plans', 'lessons.study_plan_id', '=', 'study_plans.id')
            ->join('user_languages', 'study_plans.user_language_id', '=', 'user_languages.id')
            ->where('lessons.id', $lessonId)
            ->where('user_languages.user_id', $userId)
            ->first();
        if (! $lesson) {
            throw new ApiException('Lesson not found', 404);
        }

        return $lesson;
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>, 2: int, 3: array<string, mixed>}
     */
    private function contentEntry(Exercise $exercise, Lesson $lesson): array
    {
        $index = $this->exerciseIndex($exercise, $lesson);
        $content = is_array($lesson->content) ? $lesson->content : [];
        $exercises = is_array($content['exercises'] ?? null) && array_is_list($content['exercises']) ? $content['exercises'] : [];
        while (count($exercises) <= $index) {
            $exercises[] = [];
        }
        if (! is_array($exercises[$index])) {
            $exercises[$index] = [];
        }

        return [$content, $exercises, $index, $exercises[$index]];
    }

    private function exerciseIndex(Exercise $exercise, Lesson $lesson): int
    {
        $ids = Exercise::query()->where('lesson_id', $lesson->id)->orderBy('id')->pluck('id')->all();
        $index = array_search($exercise->id, $ids, true);
        if ($index === false) {
            throw new ApiException('Exercise not found', 404);
        }

        return (int) $index;
    }

    private function technicalError(Exercise $exercise): bool
    {
        if (trim($exercise->question) === '' || trim($exercise->correct_answer) === '') {
            return true;
        }
        if ($exercise->exercise_type === 'multiple_choice') {
            $options = array_values(array_filter($exercise->options ?? [], fn ($opt) => is_string($opt) && trim($opt) !== ''));

            return count($options) < 2 || ! in_array($exercise->correct_answer, $options, true);
        }
        if ($exercise->exercise_type === 'fill_blank') {
            return ! str_contains($exercise->question, '___');
        }

        return false;
    }

    private function feedback(string $native, string $key, string $answer = ''): string
    {
        $messages = [
            'en' => ['correct' => 'Correct!', 'correct_answer' => 'The correct answer is: {answer}', 'free_write_unavailable' => 'Could not evaluate free-write answer at this time.', 'good_pronunciation' => 'Good pronunciation!', 'target_phrase' => 'The target phrase was: {answer}'],
            'es' => ['correct' => 'Correcto!', 'correct_answer' => 'La respuesta correcta es: {answer}', 'free_write_unavailable' => 'No se pudo evaluar la respuesta escrita en este momento.', 'good_pronunciation' => 'Buena pronunciacion!', 'target_phrase' => 'La frase objetivo era: {answer}'],
            'de' => ['correct' => 'Richtig!', 'correct_answer' => 'Die richtige Antwort ist: {answer}', 'free_write_unavailable' => 'Die schriftliche Antwort konnte momentan nicht bewertet werden.', 'good_pronunciation' => 'Gute Aussprache!', 'target_phrase' => 'Der Zielsatz war: {answer}'],
            'fr' => ['correct' => 'Correct !', 'correct_answer' => 'La bonne reponse est : {answer}', 'free_write_unavailable' => "Impossible d'evaluer la reponse ecrite pour le moment.", 'good_pronunciation' => 'Bonne prononciation !', 'target_phrase' => 'La phrase cible etait : {answer}'],
            'it' => ['correct' => 'Corretto!', 'correct_answer' => 'La risposta corretta e: {answer}', 'free_write_unavailable' => 'Non e possibile valutare la risposta scritta in questo momento.', 'good_pronunciation' => 'Buona pronuncia!', 'target_phrase' => 'La frase obiettivo era: {answer}'],
            'pt' => ['correct' => 'Correto!', 'correct_answer' => 'A resposta correta e: {answer}', 'free_write_unavailable' => 'Nao foi possivel avaliar a resposta escrita neste momento.', 'good_pronunciation' => 'Boa pronuncia!', 'target_phrase' => 'A frase-alvo era: {answer}'],
            'ru' => ['correct' => 'Правильно!', 'correct_answer' => 'Правильный ответ: {answer}', 'free_write_unavailable' => 'Сейчас не удалось оценить письменный ответ.', 'good_pronunciation' => 'Хорошее произношение!', 'target_phrase' => 'Целевая фраза была: {answer}'],
            'nl' => ['correct' => 'Correct!', 'correct_answer' => 'Het juiste antwoord is: {answer}', 'free_write_unavailable' => 'Het geschreven antwoord kan momenteel niet worden beoordeeld.', 'good_pronunciation' => 'Goede uitspraak!', 'target_phrase' => 'De doelzin was: {answer}'],
            'pl' => ['correct' => 'Poprawnie!', 'correct_answer' => 'Prawidlowa odpowiedz to: {answer}', 'free_write_unavailable' => 'Nie mozna teraz ocenic odpowiedzi pisemnej.', 'good_pronunciation' => 'Dobra wymowa!', 'target_phrase' => 'Fraza docelowa to: {answer}'],
            'ro' => ['correct' => 'Corect!', 'correct_answer' => 'Raspunsul corect este: {answer}', 'free_write_unavailable' => 'Nu s-a putut evalua raspunsul scris momentan.', 'good_pronunciation' => 'Pronuntie buna!', 'target_phrase' => 'Fraza tinta a fost: {answer}'],
            'tr' => ['correct' => 'Doğru!', 'correct_answer' => 'Doğru cevap: {answer}', 'free_write_unavailable' => 'Yazılı cevap şu anda değerlendirilemedi.', 'good_pronunciation' => 'Telaffuzunuz iyi!', 'target_phrase' => 'Hedef ifade şuydu: {answer}'],
            'sv' => ['correct' => 'Rätt!', 'correct_answer' => 'Rätt svar: {answer}', 'free_write_unavailable' => 'Det gick inte att bedöma det skriftliga svaret just nu.', 'good_pronunciation' => 'Bra uttal!', 'target_phrase' => 'Målfrasen var: {answer}'],
            'da' => ['correct' => 'Rigtigt!', 'correct_answer' => 'Det rigtige svar er: {answer}', 'free_write_unavailable' => 'Det skriftlige svar kunne ikke vurderes lige nu.', 'good_pronunciation' => 'God udtale!', 'target_phrase' => 'Målfrasen var: {answer}'],
            'fi' => ['correct' => 'Oikein!', 'correct_answer' => 'Oikea vastaus: {answer}', 'free_write_unavailable' => 'Kirjoitettua vastausta ei voitu arvioida juuri nyt.', 'good_pronunciation' => 'Hyvä ääntäminen!', 'target_phrase' => 'Tavoiteilmaus oli: {answer}'],
            'hr' => ['correct' => 'Točno!', 'correct_answer' => 'Točan odgovor: {answer}', 'free_write_unavailable' => 'Pisani odgovor trenutačno nije bilo moguće ocijeniti.', 'good_pronunciation' => 'Dobar izgovor!', 'target_phrase' => 'Ciljani izraz bio je: {answer}'],
        ];
        $pack = $messages[$native] ?? $messages['en'];

        return str_replace('{answer}', $answer, $pack[$key]);
    }
}
