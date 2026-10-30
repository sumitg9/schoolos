<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LibraryLoan extends Model
{
    use BelongsToSchool;

    protected $fillable = [
        'book_id',
        'student_id',
        'employee_id',
        'issued_on',
        'due_on',
        'returned_on',
        'status',
        'issued_by',
    ];

    protected function casts(): array
    {
        return ['issued_on' => 'date', 'due_on' => 'date', 'returned_on' => 'date'];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(LibraryBook::class, 'book_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
