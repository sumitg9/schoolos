<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->string('name', 180);
            $table->string('category', 80)->nullable();
            $table->text('description')->nullable();
            $table->string('venue', 180)->nullable();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->decimal('budget', 10, 2)->default(0);
            $table->string('status', 20)->default('planned');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['school_id', 'status', 'starts_on']);
        });

        Schema::create('operational_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->foreignId('event_id')->nullable()->constrained('school_events')->restrictOnDelete();
            $table->string('description', 180);
            $table->string('category', 80);
            $table->string('vendor', 180)->nullable();
            $table->date('spent_on');
            $table->decimal('amount', 10, 2);
            $table->string('reference', 100)->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('review_note')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_reference', 100)->nullable();
            $table->timestamps();
            $table->index(['school_id', 'status', 'spent_on']);
            $table->index(['school_id', 'event_id', 'status']);
        });

        Schema::create('school_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->string('asset_tag', 60);
            $table->string('name', 180);
            $table->string('category', 80);
            $table->string('serial_number', 100)->nullable();
            $table->date('acquired_on');
            $table->decimal('acquisition_cost', 10, 2)->default(0);
            $table->string('location', 180)->nullable();
            $table->string('condition', 40)->default('good');
            $table->string('status', 20)->default('active');
            $table->date('warranty_ends_on')->nullable();
            $table->foreignId('custodian_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['school_id', 'asset_tag']);
            $table->index(['school_id', 'status', 'category']);
        });

        Schema::create('asset_lifecycle_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->foreignId('school_asset_id')->constrained()->restrictOnDelete();
            $table->string('event_type', 40);
            $table->date('occurred_on');
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->string('from_condition', 40)->nullable();
            $table->string('to_condition', 40)->nullable();
            $table->foreignId('from_custodian_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('to_custodian_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('location', 180)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['school_id', 'school_asset_id', 'occurred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_lifecycle_events');
        Schema::dropIfExists('school_assets');
        Schema::dropIfExists('operational_expenses');
        Schema::dropIfExists('school_events');
    }
};
