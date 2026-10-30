<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToSchool;

class StudentEnrollmentHistory extends Model
{
    use BelongsToSchool;

    protected $table = 'student_enrollment_history';

    protected $fillable = [
        'student_id',
        'from_enrollment_id',
        'to_enrollment_id',
        'from_class_id',
        'from_section_id',
        'from_roll_number',
        'to_class_id',
        'to_section_id',
        'to_roll_number',
        'action',
        'reason',
        'performed_by',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
