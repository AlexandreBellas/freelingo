<?php

namespace App\Models;

class ResourceNativeHelp extends BaseModel
{
    protected $table = 'resource_native_helps';

    protected $fillable = [
        'resource_type',
        'resource_key',
        'target_language',
        'native_language',
        'source_hash',
        'content',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'content' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
