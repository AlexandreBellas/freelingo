<?php

use App\Http\Controllers\CurriculumController;
use App\Http\Controllers\GrammarController;
use App\Http\Controllers\PhrasebookController;
use App\Http\Controllers\VocabularyController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.api')->group(function () {
    Route::get('/api/vocabulary', [VocabularyController::class, 'index']);
    Route::get('/api/vocabulary/level/{level}', [VocabularyController::class, 'byLevel']);
    Route::get('/api/vocabulary/{setId}', [VocabularyController::class, 'show']);

    Route::get('/api/grammar', [GrammarController::class, 'index']);
    Route::get('/api/grammar/{slug}', [GrammarController::class, 'show']);

    Route::get('/api/phrasebook', [PhrasebookController::class, 'index']);
    Route::get('/api/phrasebook/level/{level}', [PhrasebookController::class, 'byLevel']);
    Route::get('/api/phrasebook/audio/{categoryId}/{phraseIndex}', [PhrasebookController::class, 'audio'])->whereNumber('phraseIndex');
    Route::get('/api/phrasebook/{categoryId}', [PhrasebookController::class, 'show']);

    Route::get('/api/curriculum', [CurriculumController::class, 'index']);
    Route::get('/api/curriculum/{level}', [CurriculumController::class, 'show']);
});
