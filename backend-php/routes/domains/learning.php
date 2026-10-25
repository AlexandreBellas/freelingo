<?php

use App\Http\Controllers\AssessmentController;
use App\Http\Controllers\FlashcardController;
use App\Http\Controllers\LessonController;
use App\Http\Controllers\ProgressController;
use App\Http\Controllers\StudyPlanController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.api')->group(function () {
    Route::get('/api/study-plan/current', [StudyPlanController::class, 'current']);
    Route::post('/api/study-plan/generate', [StudyPlanController::class, 'generate']);
    Route::get('/api/study-plan/today', [StudyPlanController::class, 'today']);
    Route::post('/api/study-plan/skip-day', [StudyPlanController::class, 'skip']);
    Route::get('/api/study-plan/pending-lessons', [StudyPlanController::class, 'pending']);
    Route::get('/api/study-plan/lessons', [StudyPlanController::class, 'lessons']);

    Route::post('/api/lessons/exercises/{exerciseId}/answer', [LessonController::class, 'answer'])->whereNumber('exerciseId');
    Route::post('/api/lessons/exercises/{exerciseId}/regenerate', [LessonController::class, 'regenerate'])->whereNumber('exerciseId');
    Route::post('/api/lessons/exercises/{exerciseId}/native-explanation', [LessonController::class, 'exerciseNativeExplanation'])->whereNumber('exerciseId');
    Route::post('/api/lessons/exercises/{exerciseId}/native-hint', [LessonController::class, 'exerciseNativeHint'])->whereNumber('exerciseId');
    Route::get('/api/lessons/{lessonId}', [LessonController::class, 'show'])->whereNumber('lessonId');
    Route::post('/api/lessons/{lessonId}/start', [LessonController::class, 'start'])->whereNumber('lessonId');
    Route::post('/api/lessons/{lessonId}/complete', [LessonController::class, 'complete'])->whereNumber('lessonId');
    Route::post('/api/lessons/{lessonId}/native-explanation', [LessonController::class, 'nativeExplanation'])->whereNumber('lessonId');

    Route::get('/api/progress/summary', [ProgressController::class, 'summary']);
    Route::get('/api/progress/history', [ProgressController::class, 'history']);
    Route::get('/api/progress/competencies', [ProgressController::class, 'competencies']);

    Route::get('/api/assessment/start', [AssessmentController::class, 'start']);
    Route::get('/api/assessment/bank', [AssessmentController::class, 'bank']);
    Route::post('/api/assessment/submit', [AssessmentController::class, 'submit']);
    Route::post('/api/assessment/started', [AssessmentController::class, 'started']);
    Route::post('/api/assessment/evaluate', [AssessmentController::class, 'evaluate']);
    Route::post('/api/assessment/free-write', [AssessmentController::class, 'freeWrite']);
    Route::post('/api/assessment/complete', [AssessmentController::class, 'complete']);
    Route::post('/api/assessment/voice-trial', [AssessmentController::class, 'voiceTrial']);
    Route::get('/api/assessment/level-test/questions/{planId}', [AssessmentController::class, 'levelQuestions'])->whereNumber('planId');
    Route::post('/api/assessment/level-test/submit', [AssessmentController::class, 'levelSubmit']);
    Route::get('/api/assessment/level-test/result/{planId}', [AssessmentController::class, 'levelResult'])->whereNumber('planId');

    Route::get('/api/flashcards/due', [FlashcardController::class, 'due']);
    Route::get('/api/flashcards/all', [FlashcardController::class, 'all']);
    Route::post('/api/flashcards/bulk', [FlashcardController::class, 'bulk']);
    Route::post('/api/flashcards/generate', [FlashcardController::class, 'generate']);
    Route::post('/api/flashcards/from-word', [FlashcardController::class, 'fromWord']);
    Route::get('/api/flashcards/vocabulary', [FlashcardController::class, 'vocabulary']);
    Route::get('/api/flashcards/library', [FlashcardController::class, 'library']);
    Route::get('/api/flashcards/preferences', [FlashcardController::class, 'preferences']);
    Route::put('/api/flashcards/preferences', [FlashcardController::class, 'updatePreferences']);
    Route::post('/api/flashcards', [FlashcardController::class, 'store']);
    Route::post('/api/flashcards/{cardId}/review', [FlashcardController::class, 'review'])->whereNumber('cardId');
    Route::put('/api/flashcards/{cardId}/suspension', [FlashcardController::class, 'suspension'])->whereNumber('cardId');
    Route::put('/api/flashcards/{cardId}', [FlashcardController::class, 'update'])->whereNumber('cardId');
    Route::delete('/api/flashcards/{cardId}', [FlashcardController::class, 'destroy'])->whereNumber('cardId');
});
