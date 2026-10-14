<?php

namespace App\Models;

class ReadingExercise extends BaseModel
{
    protected $table = 'reading_exercises';

    protected $fillable = [
        'level',
        'target_language',
        'exercise_type',
        'topic',
        'text',
        'questions',
        'view_count',
        'created_at',
    ];

    protected $casts = [
        'questions' => 'array',
        'created_at' => 'datetime',
    ];
}
