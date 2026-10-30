<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationalExpense extends Model
{
    use BelongsToSchool;

    protected $fillable = [
        'event_id',
        'description',
        'category',
        'vendor',
        'spent_on',
        'amount',
        'reference',
        'status',
        'review_note',
        'submitted_by',
        'approved_by',
        'approved_at',
        'paid_by',
        'paid_at',
        'payment_reference',
    ];

    protected function casts(): array
    {
        return [
            'spent_on' => 'date',
            'amount' => 'decimal:2',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(SchoolEvent::class, 'event_id');
    }
}
