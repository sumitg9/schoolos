<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private array $tenantTables = [
        'users',
        'school_settings',
        'academic_sessions',
        'school_classes',
        'sections',
        'subjects',
        'audit_logs',
        'students',
        'guardians',
        'student_guardian',
        'student_enrollments',
        'student_enrollment_history',
        'student_medical_records',
        'employees',
        'teacher_assignments',
        'student_documents',
        'employee_documents',
        'exam_schedules',
        'exam_schedule_subjects',
        'student_exam_marks',
        'homework_assignments',
        'timetable_slots',
        'school_notices',
        'portal_activation_codes',
        'student_attendance',
        'fee_charges',
        'fee_payments',
    ];

    public function up(): void
    {
        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->string('name', 180);
            $table->string('slug', 180)->unique();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        $existingName = DB::table('school_settings')->orderBy('id')->value('name') ?? 'School';
        $schoolId = DB::table('schools')->insertGetId([
            'name' => $existingName,
            'slug' => Str::slug($existingName).'-default',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::table('school_settings', function (Blueprint $table) {
            $table->dropUnique('school_settings_singleton_key_unique');
        });

        foreach ($this->tenantTables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('school_id')->nullable()->constrained('schools')->restrictOnDelete();
            });
            DB::table($tableName)->whereNull('school_id')->update(['school_id' => $schoolId]);
        }

        Schema::table('school_settings', function (Blueprint $table) {
            $table->unique(['school_id', 'singleton_key']);
        });
    }

    public function down(): void
    {
        Schema::table('school_settings', function (Blueprint $table) {
            $table->dropUnique(['school_id', 'singleton_key']);
        });

        foreach (array_reverse($this->tenantTables) as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('school_id');
            });
        }

        Schema::table('school_settings', function (Blueprint $table) {
            $table->unique('singleton_key');
        });

        Schema::dropIfExists('schools');
    }
};
