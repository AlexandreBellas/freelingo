<?php

namespace App\Models;

class FeedbackEntry extends BaseModel
{
    protected $table = 'feedback_entries';

    protected $fillable = [
        'type',
        'title',
        'description',
        'status',
        'author_id',
        'vote_count',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
