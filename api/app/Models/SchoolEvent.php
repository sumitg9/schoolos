<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SchoolEvent extends Model
{
    use BelongsToSchool;

    protected $fillable = [
        'name',
        'category',
        'description',
        'venue',
        'starts_on',
        'ends_on',
        'budget',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'budget' => 'decimal:2',
        ];
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(OperationalExpense::class, 'event_id');
    }
}
