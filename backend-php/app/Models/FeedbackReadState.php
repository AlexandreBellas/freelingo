<?php

namespace App\Models;

class FeedbackReadState extends BaseModel
{
    protected $table = 'feedback_read_states';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'entry_id',
        'user_id',
        'last_read_at',
    ];

    protected $casts = [
        'last_read_at' => 'datetime',
    ];
}
