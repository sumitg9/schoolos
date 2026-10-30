<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_schedules', function (Blueprint $table) {
            $table->string('results_status', 20)->default('draft');
            $table->json('grading_scale')->nullable();
            $table->foreignId('results_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('results_approved_at')->nullable();
            $table->foreignId('results_published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('results_published_at')->nullable();
            $table->index(['school_id', 'status', 'results_status']);
        });

        DB::table('exam_schedules')
            ->where('status', 'published')
            ->update(['results_status' => 'published']);
    }

    public function down(): void
    {
        Schema::table('exam_schedules', function (Blueprint $table) {
            $table->dropIndex(['school_id', 'status', 'results_status']);
            $table->dropConstrainedForeignId('results_published_by');
            $table->dropColumn(['results_published_at', 'results_approved_at']);
            $table->dropConstrainedForeignId('results_approved_by');
            $table->dropColumn(['results_status', 'grading_scale']);
        });
    }
};
