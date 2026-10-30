<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToSchool;

class HomeworkAssignment extends Model
{
    use BelongsToSchool;

    protected $fillable = [
        'academic_session_id', 'school_class_id', 'section_id', 'subject_id', 'employee_id',
        'title', 'description', 'assigned_on', 'due_on', 'status', 'created_by',
    ];

    protected function casts(): array
    {
        return ['assigned_on' => 'date', 'due_on' => 'date'];
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

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
