<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('school_class_id')->constrained('school_classes')->restrictOnDelete();
            $table->string('title', 150);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['academic_session_id', 'school_class_id', 'status']);
        });

        Schema::create('exam_schedule_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_schedule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->date('exam_date');
            $table->time('starts_at');
            $table->unsignedSmallInteger('duration_minutes');
            $table->decimal('maximum_marks', 7, 2);
            $table->decimal('passing_marks', 7, 2);
            $table->timestamps();
            $table->unique(['exam_schedule_id', 'subject_id']);
            $table->index(['exam_date', 'starts_at']);
        });

        Schema::create('student_exam_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_schedule_subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_enrollment_id')->constrained()->restrictOnDelete();
            $table->decimal('marks_obtained', 7, 2);
            $table->string('remarks', 500)->nullable();
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['exam_schedule_subject_id', 'student_enrollment_id'], 'exam_marks_subject_enrollment_unique');
        });

        Schema::create('homework_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('school_class_id')->constrained('school_classes')->restrictOnDelete();
            $table->foreignId('section_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 180);
            $table->text('description');
            $table->date('assigned_on');
            $table->date('due_on');
            $table->string('status', 20)->default('published');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['academic_session_id', 'school_class_id', 'section_id', 'due_on']);
        });

        Schema::create('timetable_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('school_class_id')->constrained('school_classes')->restrictOnDelete();
            $table->foreignId('section_id')->constrained()->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->unsignedTinyInteger('period_number');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->string('room_number', 50)->nullable();
            $table->timestamps();
            $table->unique(['academic_session_id', 'section_id', 'weekday', 'period_number'], 'timetable_section_period_unique');
            $table->index(['academic_session_id', 'employee_id', 'weekday', 'starts_at']);
        });

        Schema::create('school_notices', function (Blueprint $table) {
            $table->id();
            $table->string('title', 180);
            $table->text('body');
            $table->string('audience', 20)->default('all');
            $table->string('status', 20)->default('published');
            $table->timestamp('publish_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'audience', 'publish_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_notices');
        Schema::dropIfExists('timetable_slots');
        Schema::dropIfExists('homework_assignments');
        Schema::dropIfExists('student_exam_marks');
        Schema::dropIfExists('exam_schedule_subjects');
        Schema::dropIfExists('exam_schedules');
    }
};
