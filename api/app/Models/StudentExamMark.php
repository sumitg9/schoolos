<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToSchool;

class StudentExamMark extends Model
{
    use BelongsToSchool;

    protected $fillable = [
        'exam_schedule_subject_id',
        'student_enrollment_id',
        'marks_obtained',
        'remarks',
        'entered_by',
    ];

    protected function casts(): array
    {
        return ['marks_obtained' => 'decimal:2'];
    }

    public function examSubject(): BelongsTo
    {
        return $this->belongsTo(ExamScheduleSubject::class, 'exam_schedule_subject_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(StudentEnrollment::class, 'student_enrollment_id');
    }
}
