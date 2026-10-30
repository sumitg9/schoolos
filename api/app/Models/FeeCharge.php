<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeeCharge extends Model
{
    use BelongsToSchool;

    protected $fillable = ['student_id', 'fee_invoice_id', 'description', 'amount', 'due_on', 'status', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'due_on' => 'date'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function feeInvoice(): BelongsTo
    {
        return $this->belongsTo(FeeInvoice::class);
    }
}
