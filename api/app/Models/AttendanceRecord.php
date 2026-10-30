<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToSchool;

class AttendanceRecord extends Model
{
    use BelongsToSchool;

    protected $table = 'student_attendance';

    protected $fillable = [
        'student_enrollment_id',
        'attendance_date',
        'status',
        'remarks',
        'recorded_by',
    ];

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(StudentEnrollment::class, 'student_enrollment_id');
    }
}
