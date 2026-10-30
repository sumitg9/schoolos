<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_class_subject', function (Blueprint $table) {
            $table->foreignId('school_class_id')->constrained('school_classes')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->primary(['school_class_id', 'subject_id']);
            $table->index('subject_id');
        });

        DB::table('school_classes')
            ->join('subjects', function ($join) {
                $join->on('subjects.academic_session_id', '=', 'school_classes.academic_session_id')
                    ->on('subjects.school_id', '=', 'school_classes.school_id');
            })
            ->select('school_classes.id as school_class_id', 'subjects.id as subject_id')
            ->orderBy('school_classes.id')
            ->chunk(500, function ($rows) {
                DB::table('school_class_subject')->insertOrIgnore(
                    $rows->map(fn ($row) => [
                        'school_class_id' => $row->school_class_id,
                        'subject_id' => $row->subject_id,
                    ])->all(),
                );
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_class_subject');
    }
};
