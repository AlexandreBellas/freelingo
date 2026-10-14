<?php

namespace App\Models;

class ListeningExercise extends BaseModel
{
    protected $table = 'listening_exercises';

    protected $fillable = [
        'level',
        'target_language',
        'exercise_type',
        'topic',
        'text',
        'audio_path',
        'duration_seconds',
        'questions',
        'play_count',
        'created_at',
    ];

    protected $casts = [
        'questions' => 'array',
        'created_at' => 'datetime',
    ];
}
