<?php

namespace App\Models;

class FeedbackComment extends BaseModel
{
    protected $table = 'feedback_comments';

    protected $fillable = [
        'entry_id',
        'author_id',
        'body',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
