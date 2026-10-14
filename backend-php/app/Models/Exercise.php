<?php

namespace App\Models;

class Exercise extends BaseModel
{
    protected $table = 'exercises';

    protected $fillable = [
        'lesson_id',
        'exercise_type',
        'question',
        'options',
        'correct_answer',
        'user_answer',
        'score',
        'feedback',
        'corrections',
        'explanation',
        'answered_at',
    ];

    protected $casts = [
        'options' => 'array',
        'corrections' => 'array',
        'score' => 'float',
        'answered_at' => 'datetime',
    ];
}
