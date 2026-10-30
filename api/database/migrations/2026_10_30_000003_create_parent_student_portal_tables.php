<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->foreignId('portal_user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
        });

        Schema::table('guardians', function (Blueprint $table) {
            $table->foreignId('portal_user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
        });

        Schema::create('portal_activation_codes', function (Blueprint $table) {
            $table->id();
            $table->string('target_type', 20);
            $table->unsignedBigInteger('target_id');
            $table->string('code_hash', 64)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamps();
            $table->index(['target_type', 'target_id', 'redeemed_at']);
        });

        Schema::create('student_attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_enrollment_id')->constrained()->restrictOnDelete();
            $table->date('attendance_date');
            $table->string('status', 20);
            $table->string('remarks', 500)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['student_enrollment_id', 'attendance_date']);
            $table->index(['attendance_date', 'status']);
        });

        Schema::create('fee_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->string('description', 180);
            $table->decimal('amount', 10, 2);
            $table->date('due_on');
            $table->string('status', 20)->default('posted');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['student_id', 'due_on', 'status']);
        });

        Schema::create('fee_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 10, 2);
            $table->timestamp('paid_at');
            $table->string('method', 30);
            $table->string('reference', 100)->nullable();
            $table->string('remarks', 500)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['student_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_payments');
        Schema::dropIfExists('fee_charges');
        Schema::dropIfExists('student_attendance');
        Schema::dropIfExists('portal_activation_codes');

        Schema::table('guardians', fn (Blueprint $table) => $table->dropConstrainedForeignId('portal_user_id'));
        Schema::table('students', fn (Blueprint $table) => $table->dropConstrainedForeignId('portal_user_id'));
    }
};
