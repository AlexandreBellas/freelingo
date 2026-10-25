<?php

namespace App\Models;

class ListeningAttempt extends BaseModel
{
    protected $table = 'listening_attempts';

    protected $fillable = [
        'user_id',
        'exercise_id',
        'study_plan_id',
        'answers',
        'score',
        'xp_earned',
        'completed_at',
    ];

    protected $casts = [
        'answers' => 'array',
        'completed_at' => 'datetime',
    ];
}
