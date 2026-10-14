<?php

namespace App\Models;

class ProgressReward extends BaseModel
{
    protected $table = 'progress_rewards';

    protected $fillable = [
        'user_id',
        'study_plan_id',
        'kind',
        'source_key',
        'date',
        'xp',
    ];

    protected $casts = [
        'date' => 'date',
    ];
}
