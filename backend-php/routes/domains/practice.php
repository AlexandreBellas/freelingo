<?php

use App\Http\Controllers\ChatController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\CurriculumController;
use App\Http\Controllers\GamesController;
use App\Http\Controllers\GrammarController;
use App\Http\Controllers\ListeningController;
use App\Http\Controllers\PhrasebookController;
use App\Http\Controllers\ReadingController;
use App\Http\Controllers\SpeechController;
use App\Http\Controllers\VocabularyController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.api')->group(function () {
    Route::get('/api/vocabulary', [VocabularyController::class, 'index']);
    Route::get('/api/vocabulary/level/{level}', [VocabularyController::class, 'byLevel']);
    Route::get('/api/vocabulary/{setId}', [VocabularyController::class, 'show']);
    Route::post('/api/vocabulary/{setId}/native-help', [VocabularyController::class, 'nativeHelp']);

    Route::get('/api/grammar', [GrammarController::class, 'index']);
    Route::get('/api/grammar/{slug}', [GrammarController::class, 'show']);
    Route::post('/api/grammar/{slug}/native-help', [GrammarController::class, 'nativeHelp']);

    Route::get('/api/phrasebook', [PhrasebookController::class, 'index']);
    Route::get('/api/phrasebook/level/{level}', [PhrasebookController::class, 'byLevel']);
    Route::get('/api/phrasebook/audio/{categoryId}/{phraseIndex}', [PhrasebookController::class, 'audio'])->whereNumber('phraseIndex');
    Route::get('/api/phrasebook/{categoryId}', [PhrasebookController::class, 'show']);
    Route::post('/api/phrasebook/{categoryId}/native-help', [PhrasebookController::class, 'nativeHelp']);

    Route::get('/api/curriculum', [CurriculumController::class, 'index']);
    Route::get('/api/curriculum/{level}', [CurriculumController::class, 'show']);

    Route::get('/api/reading/next', [ReadingController::class, 'next']);
    Route::post('/api/reading/generate', [ReadingController::class, 'generate']);
    Route::post('/api/reading/started', [ReadingController::class, 'started']);
    Route::post('/api/reading/attempt', [ReadingController::class, 'attempt']);
    Route::get('/api/reading/history', [ReadingController::class, 'history']);

    Route::get('/api/listening/next', [ListeningController::class, 'next']);
    Route::post('/api/listening/generate', [ListeningController::class, 'generate']);
    Route::get('/api/listening/audio/{exerciseId}', [ListeningController::class, 'audio'])->whereNumber('exerciseId');
    Route::post('/api/listening/started', [ListeningController::class, 'started']);
    Route::post('/api/listening/attempt', [ListeningController::class, 'attempt']);
    Route::get('/api/listening/history', [ListeningController::class, 'history']);

    Route::get('/api/games/sessions/{sessionId}', [GamesController::class, 'show']);
    Route::post('/api/games/sessions/{sessionId}/answer', [GamesController::class, 'answer']);
    Route::post('/api/games/sessions/{sessionId}/abandon', [GamesController::class, 'abandon']);
    Route::get('/api/games/{gameType}', [GamesController::class, 'catalog']);
    Route::post('/api/games/{gameType}', [GamesController::class, 'start']);

    Route::get('/api/chat/conversations', [ChatController::class, 'conversations']);
    Route::post('/api/chat/conversations', [ChatController::class, 'createConversation']);
    Route::delete('/api/chat/conversations/{conversationId}', [ChatController::class, 'deleteConversation'])->whereNumber('conversationId');
    Route::get('/api/chat/conversations/{conversationId}/messages', [ChatController::class, 'messages'])->whereNumber('conversationId');
    Route::post('/api/chat', [ChatController::class, 'chat']);
    Route::get('/api/chat/history', [ChatController::class, 'history']);

    Route::post('/api/tts/tour/{locale}/{step}', [SpeechController::class, 'tour']);
    Route::post('/api/tts', [SpeechController::class, 'tts']);
    Route::get('/api/tts/preview/{voice}', [SpeechController::class, 'preview']);
    Route::post('/api/stt', [SpeechController::class, 'stt']);

    Route::post('/api/conversation/warmup', [ConversationController::class, 'warmup']);
});
