<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToSchool;

class SchoolNotice extends Model
{
    use BelongsToSchool;

    protected $fillable = [
        'title', 'body', 'audience', 'status', 'publish_at', 'expires_at', 'created_by',
    ];

    protected function casts(): array
    {
        return ['publish_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
