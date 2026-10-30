<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeeInvoice extends Model
{
    use BelongsToSchool;

    protected $fillable = [
        'invoice_number',
        'student_id',
        'student_enrollment_id',
        'fee_structure_id',
        'due_on',
        'concession_amount',
        'concession_reason',
        'late_fee_amount',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'concession_amount' => 'decimal:2',
            'late_fee_amount' => 'decimal:2',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(StudentEnrollment::class, 'student_enrollment_id');
    }

    public function feeStructure(): BelongsTo
    {
        return $this->belongsTo(FeeStructure::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(FeeCharge::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(FeePayment::class);
    }

    public function lateFeeIsDue(): bool
    {
        if (! $this->due_on->isBefore(today()) || (float) $this->late_fee_amount <= 0) {
            return false;
        }

        $chargesCents = (int) round((float) $this->charges()->where('status', 'posted')->sum('amount') * 100);
        $paymentsCents = (int) round((float) $this->payments()->sum('amount') * 100);
        $concessionCents = (int) round((float) $this->concession_amount * 100);

        return $chargesCents - $paymentsCents - $concessionCents > 0
            || $this->payments()->whereDate('paid_at', '>', $this->due_on)->exists();
    }
}
