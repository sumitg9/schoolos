<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToSchool;

class SchoolSetting extends Model
{
    use BelongsToSchool;

    protected $fillable = [
        'singleton_key',
        'name',
        'address',
        'phone',
        'email',
        'currency',
        'timezone',
        'date_format',
    ];
}
