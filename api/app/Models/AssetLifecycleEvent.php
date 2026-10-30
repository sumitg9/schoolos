<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetLifecycleEvent extends Model
{
    use BelongsToSchool;

    protected $fillable = [
        'school_asset_id',
        'event_type',
        'occurred_on',
        'from_status',
        'to_status',
        'from_condition',
        'to_condition',
        'from_custodian_employee_id',
        'to_custodian_employee_id',
        'location',
        'notes',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return ['occurred_on' => 'date'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(SchoolAsset::class, 'school_asset_id');
    }
}
