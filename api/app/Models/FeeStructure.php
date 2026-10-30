<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeeStructure extends Model
{
    use BelongsToSchool;

    protected $fillable = [
        'academic_session_id',
        'school_class_id',
        'name',
        'late_fee_amount',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return ['late_fee_amount' => 'decimal:2'];
    }

    public function academicSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(FeeStructureItem::class);
    }
}
