<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToSchool;

class EmployeeDocument extends Model
{
    use BelongsToSchool;

    protected $fillable = [
        'employee_id',
        'category',
        'original_name',
        'stored_path',
        'mime_type',
        'size_bytes',
        'sha256',
        'uploaded_by',
    ];

    protected $hidden = ['stored_path', 'sha256'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
