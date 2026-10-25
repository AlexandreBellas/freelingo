<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

abstract class BaseModel extends Model
{
    public $timestamps = false;

    protected function serializeDate(DateTimeInterface $date): string
    {
        $formatted = $date->format('Y-m-d\TH:i:s');
        $micro = $date->format('u');
        if ((int) $micro !== 0) {
            $formatted .= '.'.rtrim($micro, '0');
        }

        return $formatted;
    }
}
