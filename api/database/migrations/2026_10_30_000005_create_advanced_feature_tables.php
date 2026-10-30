<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_payment_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->string('provider', 30)->default('razorpay');
            $table->string('provider_order_id', 100)->unique();
            $table->string('provider_payment_id', 100)->nullable()->unique();
            $table->decimal('amount', 10, 2);
            $table->char('currency', 3)->default('INR');
            $table->string('status', 20)->default('created');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'student_id', 'status']);
        });

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->string('channel', 20);
            $table->string('recipient', 255);
            $table->string('subject', 180)->nullable();
            $table->text('message');
            $table->string('status', 20)->default('pending');
            $table->string('provider_reference', 120)->nullable();
            $table->text('failure_reason')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'status', 'created_at']);
        });

        Schema::create('library_books', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->string('title', 200);
            $table->string('author', 180)->nullable();
            $table->string('isbn', 32)->nullable();
            $table->string('category', 100)->nullable();
            $table->unsignedSmallInteger('total_copies')->default(1);
            $table->unsignedSmallInteger('available_copies')->default(1);
            $table->timestamps();
            $table->unique(['school_id', 'isbn']);
            $table->index(['school_id', 'title']);
        });

        Schema::create('library_loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->foreignId('book_id')->constrained('library_books')->restrictOnDelete();
            $table->foreignId('student_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('issued_on');
            $table->date('due_on');
            $table->date('returned_on')->nullable();
            $table->string('status', 20)->default('issued');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['school_id', 'status', 'due_on']);
        });

        Schema::create('transport_vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->string('registration_number', 40);
            $table->string('description', 150)->nullable();
            $table->unsignedSmallInteger('capacity');
            $table->foreignId('driver_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['school_id', 'registration_number']);
        });

        Schema::create('transport_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->text('stops');
            $table->decimal('monthly_fee', 10, 2)->default(0);
            $table->foreignId('vehicle_id')->nullable()->constrained('transport_vehicles')->nullOnDelete();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['school_id', 'name']);
        });

        Schema::create('transport_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->foreignId('transport_route_id')->constrained('transport_routes')->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->string('pickup_point', 180)->nullable();
            $table->string('status', 20)->default('active');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'student_id', 'status']);
        });

        Schema::create('payroll_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('pay_year');
            $table->unsignedTinyInteger('pay_month');
            $table->decimal('base_salary', 10, 2);
            $table->decimal('allowances', 10, 2)->default(0);
            $table->decimal('deductions', 10, 2)->default(0);
            $table->decimal('net_amount', 10, 2);
            $table->string('status', 20)->default('draft');
            $table->timestamp('paid_at')->nullable();
            $table->string('reference', 100)->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['school_id', 'employee_id', 'pay_year', 'pay_month']);
        });

        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->string('name', 180);
            $table->string('sku', 80)->nullable();
            $table->string('category', 100)->nullable();
            $table->string('unit', 30)->default('unit');
            $table->unsignedInteger('quantity')->default(0);
            $table->unsignedInteger('reorder_level')->default(0);
            $table->timestamps();
            $table->unique(['school_id', 'sku']);
        });

        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->unsignedInteger('quantity');
            $table->string('description', 180)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['school_id', 'inventory_item_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transactions');
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('payroll_records');
        Schema::dropIfExists('transport_assignments');
        Schema::dropIfExists('transport_routes');
        Schema::dropIfExists('transport_vehicles');
        Schema::dropIfExists('library_loans');
        Schema::dropIfExists('library_books');
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('fee_payment_orders');
    }
};
