<?php

namespace App\Models;

class Progress extends BaseModel
{
    protected $table = 'progress';

    protected $fillable = [
        'user_id',
        'date',
        'xp_earned',
        'lessons_completed',
        'exercises_correct',
        'exercises_total',
        'streak_day',
        'skills',
        'skill_updates',
        'study_plan_id',
    ];

    protected $casts = [
        'date' => 'date',
        'skills' => 'array',
        'skill_updates' => 'array',
    ];
}
