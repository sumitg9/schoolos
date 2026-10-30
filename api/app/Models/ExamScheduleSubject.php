<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Concerns\BelongsToSchool;

class ExamScheduleSubject extends Model
{
    use BelongsToSchool;

    protected $fillable = [
        'exam_schedule_id',
        'subject_id',
        'exam_date',
        'starts_at',
        'duration_minutes',
        'maximum_marks',
        'passing_marks',
    ];

    protected function casts(): array
    {
        return ['exam_date' => 'date', 'maximum_marks' => 'decimal:2', 'passing_marks' => 'decimal:2'];
    }

    public function examSchedule(): BelongsTo
    {
        return $this->belongsTo(ExamSchedule::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function marks(): HasMany
    {
        return $this->hasMany(StudentExamMark::class);
    }
}
