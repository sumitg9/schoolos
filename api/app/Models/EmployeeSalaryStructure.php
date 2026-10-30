<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeSalaryStructure extends Model
{
    use BelongsToSchool;

    protected $fillable = [
        'employee_id',
        'effective_from',
        'base_salary',
        'allowances',
        'deductions',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'base_salary' => 'decimal:2',
            'allowances' => 'array',
            'deductions' => 'array',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
