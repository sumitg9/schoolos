<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

class NotificationDelivery extends Model
{
    use BelongsToSchool;

    protected $fillable = [
        'channel',
        'recipient',
        'subject',
        'message',
        'status',
        'provider_reference',
        'failure_reason',
        'sent_by',
        'sent_at',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }
}
