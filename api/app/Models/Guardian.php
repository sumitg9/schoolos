<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use App\Tenancy\SchoolContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Guardian extends Model
{
    use BelongsToSchool;

    protected $fillable = ['first_name', 'last_name', 'phone', 'email', 'occupation', 'address'];

    public function students(): BelongsToMany
    {
        $relation = $this->belongsToMany(Student::class, 'student_guardian')
            ->withPivot(['relationship', 'is_primary'])
            ->withTimestamps();
        $schoolId = SchoolContext::id() ?? $this->school_id;
        if ($schoolId) {
            $relation->withPivotValue('school_id', $schoolId);
        }

        return $relation;
    }

    public function portalUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'portal_user_id');
    }
}
