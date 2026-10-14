<?php

namespace App\Models;

class FeedbackVote extends BaseModel
{
    protected $table = 'feedback_votes';

    protected $fillable = [
        'entry_id',
        'user_id',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
