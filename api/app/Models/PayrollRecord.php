<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollRecord extends Model
{
    use BelongsToSchool;

    protected $fillable = [
        'employee_id',
        'pay_year',
        'pay_month',
        'base_salary',
        'allowances',
        'deductions',
        'net_amount',
        'allowance_items',
        'deduction_items',
        'status',
        'paid_at',
        'reference',
        'processed_by',
        'created_by',
        'approved_by',
        'approved_at',
        'slip_number',
    ];

    protected function casts(): array
    {
        return [
            'base_salary' => 'decimal:2',
            'allowances' => 'decimal:2',
            'deductions' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'allowance_items' => 'array',
            'deduction_items' => 'array',
            'paid_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
