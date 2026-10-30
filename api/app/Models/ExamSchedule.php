<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamSchedule extends Model
{
    use BelongsToSchool;

    protected $fillable = [
        'academic_session_id',
        'school_class_id',
        'title',
        'starts_on',
        'ends_on',
        'status',
        'results_status',
        'grading_scale',
        'results_approved_by',
        'results_approved_at',
        'results_published_by',
        'results_published_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'grading_scale' => 'array',
            'results_approved_at' => 'datetime',
            'results_published_at' => 'datetime',
        ];
    }

    public function academicSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    public function subjects(): HasMany
    {
        return $this->hasMany(ExamScheduleSubject::class);
    }
}
