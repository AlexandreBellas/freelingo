<?php

namespace App\Models;

class GameSession extends BaseModel
{
    protected $table = 'game_sessions';

    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = [
        'id',
        'user_id',
        'study_plan_id',
        'mode',
        'game_type',
        'target_language',
        'native_language',
        'level',
        'status',
        'context',
        'challenges',
        'answers',
        'created_at',
        'deadline',
        'completed_at',
        'xp_earned',
        'error',
    ];

    protected $casts = [
        'context' => 'array',
        'challenges' => 'array',
        'answers' => 'array',
        'created_at' => 'datetime',
        'deadline' => 'datetime',
        'completed_at' => 'datetime',
    ];
}
