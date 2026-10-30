<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SchoolAsset extends Model
{
    use BelongsToSchool;

    protected $fillable = [
        'asset_tag',
        'name',
        'category',
        'serial_number',
        'acquired_on',
        'acquisition_cost',
        'location',
        'condition',
        'status',
        'warranty_ends_on',
        'custodian_employee_id',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'acquired_on' => 'date',
            'warranty_ends_on' => 'date',
            'acquisition_cost' => 'decimal:2',
        ];
    }

    public function custodian(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'custodian_employee_id');
    }

    public function lifecycleEvents(): HasMany
    {
        return $this->hasMany(AssetLifecycleEvent::class, 'school_asset_id');
    }
}
