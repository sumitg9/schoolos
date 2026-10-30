<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransportVehicle extends Model
{
    use BelongsToSchool;

    protected $fillable = ['registration_number', 'description', 'capacity', 'driver_employee_id', 'status'];

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'driver_employee_id');
    }

    public function routes(): HasMany
    {
        return $this->hasMany(TransportRoute::class, 'vehicle_id');
    }
}
