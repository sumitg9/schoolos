<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use App\Tenancy\SchoolContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Student extends Model
{
    use BelongsToSchool;

    protected $fillable = [
        'first_name',
        'last_name',
        'date_of_birth',
        'gender',
        'admission_date',
        'phone',
        'email',
        'address',
        'previous_school',
        'transfer_certificate_number',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'admission_date' => 'date',
        ];
    }

    public function guardians(): BelongsToMany
    {
        $relation = $this->belongsToMany(Guardian::class, 'student_guardian')
            ->withPivot(['relationship', 'is_primary'])
            ->withTimestamps();
        $schoolId = SchoolContext::id() ?? $this->school_id;
        if ($schoolId) {
            $relation->withPivotValue('school_id', $schoolId);
        }

        return $relation;
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(StudentEnrollment::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(StudentDocument::class);
    }

    public function enrollmentHistory(): HasMany
    {
        return $this->hasMany(StudentEnrollmentHistory::class)->latest();
    }

    public function medicalRecord(): HasMany
    {
        return $this->hasMany(StudentMedicalRecord::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function portalUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'portal_user_id');
    }

    public function attendance(): HasMany
    {
        return $this->hasManyThrough(AttendanceRecord::class, StudentEnrollment::class, 'student_id', 'student_enrollment_id');
    }

    public function feeCharges(): HasMany
    {
        return $this->hasMany(FeeCharge::class);
    }

    public function feePayments(): HasMany
    {
        return $this->hasMany(FeePayment::class);
    }

    public function feeInvoices(): HasMany
    {
        return $this->hasMany(FeeInvoice::class);
    }
}
