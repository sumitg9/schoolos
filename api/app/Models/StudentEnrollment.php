<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Concerns\BelongsToSchool;

class StudentEnrollment extends Model
{
    use BelongsToSchool;

    protected $fillable = [
        'student_id',
        'academic_session_id',
        'school_class_id',
        'section_id',
        'roll_number',
        'enrolled_on',
        'status',
    ];

    protected function casts(): array
    {
        return ['enrolled_on' => 'date'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function academicSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(StudentEnrollmentHistory::class, 'from_enrollment_id');
    }
}
