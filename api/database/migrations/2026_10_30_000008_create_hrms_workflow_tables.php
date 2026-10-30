<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_records', function (Blueprint $table) {
            $table->json('allowance_items')->nullable();
            $table->json('deduction_items')->nullable();
            $table->string('slip_number', 40)->nullable()->unique();
            $table->foreignId('created_by')->nullable()->after('processed_by')->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->after('processed_by')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
        });
        DB::table('payroll_records')->whereNull('created_by')->update(['created_by' => DB::raw('processed_by')]);

        Schema::create('employee_salary_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->date('effective_from');
            $table->decimal('base_salary', 10, 2);
            $table->json('allowances')->nullable();
            $table->json('deductions')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['school_id', 'employee_id', 'effective_from']);
        });

        Schema::create('employee_leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('leave_type', 40);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'status', 'starts_on']);
            $table->index(['school_id', 'employee_id', 'starts_on', 'ends_on']);
        });

        Schema::table('payroll_records', function (Blueprint $table) {
            $table->index(['school_id', 'pay_year', 'pay_month', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('payroll_records', function (Blueprint $table) {
            $table->dropIndex(['school_id', 'pay_year', 'pay_month', 'status']);
            $table->dropForeign(['approved_by']);
            $table->dropForeign(['created_by']);
            $table->dropUnique(['slip_number']);
            $table->dropColumn(['allowance_items', 'deduction_items', 'slip_number', 'created_by', 'approved_by', 'approved_at']);
        });

        Schema::dropIfExists('employee_leave_requests');
        Schema::dropIfExists('employee_salary_structures');
    }
};
