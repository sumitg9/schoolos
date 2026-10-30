<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LibraryBook extends Model
{
    use BelongsToSchool;

    protected $fillable = ['title', 'author', 'isbn', 'category', 'total_copies', 'available_copies'];

    public function loans(): HasMany
    {
        return $this->hasMany(LibraryLoan::class, 'book_id');
    }
}
