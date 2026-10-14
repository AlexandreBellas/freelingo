<?php

namespace App\Models;

class UserCompetency extends BaseModel
{
    protected $table = 'user_competencies';

    protected $fillable = [
        'user_id',
        'unit_id',
        'competency_text',
        'score',
        'mastered',
        'updated_at',
        'study_plan_id',
    ];

    protected $casts = [
        'score' => 'float',
        'mastered' => 'boolean',
        'updated_at' => 'datetime',
    ];
}
