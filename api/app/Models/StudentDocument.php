<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToSchool;

class StudentDocument extends Model
{
    use BelongsToSchool;

    protected $fillable = [
        'student_id',
        'category',
        'original_name',
        'stored_path',
        'mime_type',
        'size_bytes',
        'sha256',
        'uploaded_by',
    ];

    protected $hidden = ['stored_path', 'sha256'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
