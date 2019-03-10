<?php

namespace Modules\SmartCARS\Entities;

use Illuminate\Database\Eloquent\Model;

class SCSession extends Model
{
    protected $table = 'sc_sessions';
    protected $fillable = [
        'timestamp',
        'user_id',
        'session_key'
    ];
}
