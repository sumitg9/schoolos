<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('admission_number')->nullable()->unique();
            $table->string('first_name', 100);
            $table->string('last_name', 100)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 30)->nullable();
            $table->date('admission_date');
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('previous_school')->nullable();
            $table->string('transfer_certificate_number')->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'first_name', 'last_name']);
        });

        Schema::create('guardians', function (Blueprint $table) {
            $table->id();
            $table->string('first_name', 100);
            $table->string('last_name', 100)->nullable();
            $table->string('phone', 40);
            $table->string('email')->nullable();
            $table->string('occupation')->nullable();
            $table->text('address')->nullable();
            $table->timestamps();
            $table->index('phone');
        });

        Schema::create('student_guardian', function (Blueprint $table) {
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guardian_id')->constrained()->cascadeOnDelete();
            $table->string('relationship', 50);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->primary(['student_id', 'guardian_id']);
            $table->index(['guardian_id', 'is_primary']);
        });

        Schema::create('student_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('school_class_id')->constrained('school_classes')->restrictOnDelete();
            $table->foreignId('section_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('roll_number', 32)->nullable();
            $table->date('enrolled_on');
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->index(['academic_session_id', 'school_class_id', 'section_id', 'status']);
        });

        DB::statement("CREATE UNIQUE INDEX student_enrollments_one_active_per_session ON student_enrollments (student_id, academic_session_id) WHERE status = 'active'");
        DB::statement("CREATE UNIQUE INDEX student_enrollments_unique_active_roll ON student_enrollments (section_id, roll_number) WHERE status = 'active' AND section_id IS NOT NULL AND roll_number IS NOT NULL");

        Schema::create('student_enrollment_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('from_enrollment_id')->nullable()->constrained('student_enrollments')->nullOnDelete();
            $table->foreignId('to_enrollment_id')->nullable()->constrained('student_enrollments')->nullOnDelete();
            $table->foreignId('from_class_id')->nullable()->constrained('school_classes')->nullOnDelete();
            $table->foreignId('from_section_id')->nullable()->constrained('sections')->nullOnDelete();
            $table->string('from_roll_number', 32)->nullable();
            $table->foreignId('to_class_id')->nullable()->constrained('school_classes')->nullOnDelete();
            $table->foreignId('to_section_id')->nullable()->constrained('sections')->nullOnDelete();
            $table->string('to_roll_number', 32)->nullable();
            $table->string('action', 30);
            $table->text('reason')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['student_id', 'created_at']);
        });

        Schema::create('student_medical_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('medical_notes')->nullable();
            $table->text('allergies')->nullable();
            $table->text('emergency_notes')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id')->nullable()->unique();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('first_name', 100);
            $table->string('last_name', 100)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 30)->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('phone', 40)->nullable();
            $table->text('address')->nullable();
            $table->string('department')->nullable();
            $table->string('designation')->nullable();
            $table->string('employment_type', 30)->default('full_time');
            $table->boolean('is_teaching_staff')->default(false);
            $table->date('joining_date');
            $table->string('status', 20)->default('active');
            $table->string('qualification')->nullable();
            $table->unsignedSmallInteger('experience_years')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone', 40)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'first_name', 'last_name']);
        });

        Schema::create('teacher_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('school_class_id')->constrained('school_classes')->restrictOnDelete();
            $table->foreignId('section_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('is_class_teacher')->default(false);
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->index(['academic_session_id', 'school_class_id', 'section_id']);
        });

        DB::statement("CREATE UNIQUE INDEX teacher_assignment_scope_unique ON teacher_assignments (employee_id, academic_session_id, school_class_id, COALESCE(section_id, 0), COALESCE(subject_id, 0)) WHERE status = 'active'");
        DB::statement("CREATE UNIQUE INDEX teacher_assignment_class_teacher_unique ON teacher_assignments (academic_session_id, school_class_id, COALESCE(section_id, 0)) WHERE status = 'active' AND is_class_teacher = true");

        foreach (['student_documents' => 'student_id', 'employee_documents' => 'employee_id'] as $tableName => $ownerColumn) {
            Schema::create($tableName, function (Blueprint $table) use ($ownerColumn) {
                $table->id();
                $table->foreignId($ownerColumn)->constrained()->cascadeOnDelete();
                $table->string('category', 50);
                $table->string('original_name');
                $table->string('stored_path');
                $table->string('mime_type', 100);
                $table->unsignedBigInteger('size_bytes');
                $table->char('sha256', 64);
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index([$ownerColumn, 'category']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_documents');
        Schema::dropIfExists('student_documents');
        DB::statement('DROP INDEX IF EXISTS teacher_assignment_class_teacher_unique');
        DB::statement('DROP INDEX IF EXISTS teacher_assignment_scope_unique');
        Schema::dropIfExists('teacher_assignments');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('student_medical_records');
        Schema::dropIfExists('student_enrollment_history');
        DB::statement('DROP INDEX IF EXISTS student_enrollments_unique_active_roll');
        DB::statement('DROP INDEX IF EXISTS student_enrollments_one_active_per_session');
        Schema::dropIfExists('student_enrollments');
        Schema::dropIfExists('student_guardian');
        Schema::dropIfExists('guardians');
        Schema::dropIfExists('students');
    }
};
