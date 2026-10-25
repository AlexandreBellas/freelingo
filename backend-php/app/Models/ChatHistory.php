<?php

namespace App\Models;

class ChatHistory extends BaseModel
{
    protected $table = 'chat_history';

    protected $fillable = [
        'user_id',
        'conversation_id',
        'role',
        'content',
        'created_at',
        'study_plan_id',
        'target_language',
        'modality',
        'reply_to_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
