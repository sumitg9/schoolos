<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToSchool;

class StudentMedicalRecord extends Model
{
    use BelongsToSchool;

    protected $fillable = ['student_id', 'medical_notes', 'allergies', 'emergency_notes', 'updated_by'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
