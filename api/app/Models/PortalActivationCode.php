<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToSchool;

class PortalActivationCode extends Model
{
    use BelongsToSchool;

    protected $fillable = ['target_type', 'target_id', 'code_hash', 'created_by', 'expires_at', 'redeemed_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'redeemed_at' => 'datetime'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
