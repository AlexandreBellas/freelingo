<?php

namespace App\Models;

class LlmUsage extends BaseModel
{
    protected $table = 'llm_usage';

    protected $fillable = [
        'user_id',
        'source',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'created_at',
        'study_plan_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
