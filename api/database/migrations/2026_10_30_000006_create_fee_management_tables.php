<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('school_class_id')->constrained()->restrictOnDelete();
            $table->string('name', 180);
            $table->decimal('late_fee_amount', 10, 2)->default(0);
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['school_id', 'academic_session_id', 'school_class_id', 'name']);
            $table->index(['school_id', 'academic_session_id', 'school_class_id', 'status']);
        });

        Schema::create('fee_structure_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->foreignId('fee_structure_id')->constrained()->cascadeOnDelete();
            $table->string('description', 180);
            $table->decimal('amount', 10, 2);
            $table->timestamps();
        });

        Schema::create('fee_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->string('invoice_number', 40)->unique();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_enrollment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('fee_structure_id')->nullable()->constrained()->nullOnDelete();
            $table->date('due_on');
            $table->decimal('concession_amount', 10, 2)->default(0);
            $table->string('concession_reason', 180)->nullable();
            $table->decimal('late_fee_amount', 10, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['fee_structure_id', 'student_id']);
            $table->index(['school_id', 'student_id', 'due_on']);
        });

        Schema::table('fee_charges', function (Blueprint $table) {
            $table->foreignId('fee_invoice_id')->nullable()->after('student_id')->constrained('fee_invoices')->nullOnDelete();
        });

        Schema::table('fee_payments', function (Blueprint $table) {
            $table->foreignId('fee_invoice_id')->nullable()->after('student_id')->constrained('fee_invoices')->nullOnDelete();
            $table->string('receipt_number', 50)->nullable()->unique();
        });

        Schema::table('fee_payment_orders', function (Blueprint $table) {
            $table->foreignId('fee_invoice_id')->nullable()->after('student_id')->constrained('fee_invoices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fee_payment_orders', fn (Blueprint $table) => $table->dropConstrainedForeignId('fee_invoice_id'));
        Schema::table('fee_payments', function (Blueprint $table) {
            $table->dropUnique(['receipt_number']);
            $table->dropConstrainedForeignId('fee_invoice_id');
        });
        Schema::table('fee_charges', fn (Blueprint $table) => $table->dropConstrainedForeignId('fee_invoice_id'));
        Schema::dropIfExists('fee_invoices');
        Schema::dropIfExists('fee_structure_items');
        Schema::dropIfExists('fee_structures');
    }
};
