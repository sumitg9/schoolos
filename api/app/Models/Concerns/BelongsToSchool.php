<?php

namespace App\Models\Concerns;

use App\Tenancy\SchoolContext;
use Illuminate\Database\Eloquent\Builder;

trait BelongsToSchool
{
    protected static function bootBelongsToSchool(): void
    {
        static::addGlobalScope('school', function (Builder $builder) {
            $schoolId = SchoolContext::id();
            if ($schoolId !== null) {
                $builder->where($builder->getModel()->qualifyColumn('school_id'), $schoolId);
            }
        });

        static::creating(function ($model) {
            if (! $model->getAttribute('school_id') && SchoolContext::id() !== null) {
                $model->setAttribute('school_id', SchoolContext::id());
            }
        });
    }
}
