<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Employee;
use App\Models\ExamSchedule;
use App\Models\Guardian;
use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\TeacherAssignment;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParentStudentPortalApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        $admin = User::factory()->create();
        $admin->roles()->sync([Role::query()->where('name', 'super-admin')->value('id')]);
        $this->actingAs($admin);
    }

    public function test_parent_activation_links_children_and_exposes_only_portal_data(): void
    {
        [$session, $schoolClass, $section, $subject] = $this->academicTarget();
        $student = Student::query()->forceCreate([
            'admission_number' => 'ADM-PARENT-1',
            'first_name' => 'Avery',
            'last_name' => 'Lee',
            'date_of_birth' => '2012-03-05',
            'admission_date' => '2026-04-01',
            'email' => 'family@example.test',
            'status' => 'active',
        ]);
        $enrollment = StudentEnrollment::query()->create([
            'student_id' => $student->id,
            'academic_session_id' => $session->id,
            'school_class_id' => $schoolClass->id,
            'section_id' => $section->id,
            'roll_number' => '7',
            'enrolled_on' => '2026-04-01',
            'status' => 'active',
        ]);
        $guardian = Guardian::query()->create([
            'first_name' => 'Taylor',
            'last_name' => 'Lee',
            'phone' => '555-0101',
            'email' => 'family@example.test',
        ]);
        $student->guardians()->attach($guardian, ['relationship' => 'Parent', 'is_primary' => true]);
        $exam = ExamSchedule::query()->create([
            'academic_session_id' => $session->id,
            'school_class_id' => $schoolClass->id,
            'title' => 'Term 1',
            'starts_on' => '2026-10-12',
            'ends_on' => '2026-10-12',
            'status' => 'published',
            'results_status' => 'draft',
        ]);
        $examSubject = $exam->subjects()->create([
            'subject_id' => $subject->id,
            'exam_date' => '2026-10-12',
            'starts_at' => '09:00',
            'duration_minutes' => 60,
            'maximum_marks' => 100,
            'passing_marks' => 35,
        ]);
        $examSubject->marks()->create([
            'student_enrollment_id' => $enrollment->id,
            'marks_obtained' => 84,
        ]);
        $this->putJson('/api/attendance', [
            'academic_session_id' => $session->id,
            'school_class_id' => $schoolClass->id,
            'section_id' => $section->id,
            'attendance_date' => '2026-10-30',
            'entries' => [['student_enrollment_id' => $enrollment->id, 'status' => 'present']],
        ])->assertOk()->assertJsonPath('saved_count', 1);
        $this->getJson('/api/attendance?academic_session_id='.$session->id.'&school_class_id='.$schoolClass->id.'&section_id='.$section->id.'&attendance_date=2026-10-30')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'present');
        $this->postJson('/api/fees/charges', [
            'student_id' => $student->id,
            'description' => 'Term fee',
            'amount' => 1000,
            'due_on' => '2026-11-01',
        ])->assertCreated();
        $this->postJson('/api/fees/payments', [
            'student_id' => $student->id,
            'amount' => 250,
            'paid_at' => '2026-10-30',
            'method' => 'bank_transfer',
            'reference' => 'TXN-25',
        ])->assertCreated();
        $this->postJson('/api/notices', [
            'title' => 'School holiday',
            'body' => 'The school is closed on Friday.',
            'audience' => 'parents',
        ])->assertCreated();

        $code = $this->postJson('/api/portal/activation-codes', [
            'target_type' => 'guardian',
            'target_id' => $guardian->id,
        ])->assertCreated()->json('data.code');
        $this->postJson('/api/portal/register', [
            'code' => $code,
            'email' => 'family@example.test',
            'password' => 'correct-horse-battery-1',
            'password_confirmation' => 'correct-horse-battery-1',
        ])->assertCreated();

        $parent = User::query()->where('email', 'family@example.test')->firstOrFail();
        $this->actingAs($parent)
            ->getJson('/api/portal/children')
            ->assertOk()
            ->assertJsonPath('data.0.admission_number', 'ADM-PARENT-1');
        $this->getJson("/api/portal/students/{$student->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data.report_cards');
        $exam->update(['results_status' => 'published']);
        $this->getJson("/api/portal/students/{$student->id}")
            ->assertOk()
            ->assertJsonPath('data.student.first_name', 'Avery')
            ->assertJsonPath('data.attendance.0.status', 'present')
            ->assertJsonPath('data.fees.balance', 750)
            ->assertJsonPath('data.report_cards.0.grade', 'A')
            ->assertJsonPath('data.report_cards.0.result', 'PASS')
            ->assertJsonPath('data.announcements.0.title', 'School holiday')
            ->assertJsonMissingPath('data.student.email');
        $this->getJson('/api/fees')->assertForbidden();
        $this->postJson('/api/portal/activation-codes', ['target_type' => 'student', 'target_id' => $student->id])->assertForbidden();

        $otherStudent = Student::query()->forceCreate([
            'admission_number' => 'ADM-OTHER',
            'first_name' => 'Other',
            'admission_date' => '2026-04-01',
        ]);
        $this->getJson("/api/portal/students/{$otherStudent->id}")->assertNotFound();
    }

    public function test_activation_codes_are_one_time_and_student_registration_is_scoped(): void
    {
        [$session, $schoolClass, $section] = $this->academicTarget();
        $student = Student::query()->forceCreate([
            'admission_number' => 'ADM-STUDENT-1',
            'first_name' => 'Morgan',
            'admission_date' => '2026-04-01',
            'email' => 'student@example.test',
            'status' => 'active',
        ]);
        StudentEnrollment::query()->create([
            'student_id' => $student->id,
            'academic_session_id' => $session->id,
            'school_class_id' => $schoolClass->id,
            'section_id' => $section->id,
            'enrolled_on' => '2026-04-01',
            'status' => 'active',
        ]);
        $code = $this->postJson('/api/portal/activation-codes', [
            'target_type' => 'student',
            'target_id' => $student->id,
        ])->assertCreated()->json('data.code');
        $payload = [
            'code' => $code,
            'email' => 'student@example.test',
            'password' => 'correct-horse-battery-1',
            'password_confirmation' => 'correct-horse-battery-1',
        ];
        $this->postJson('/api/portal/register', $payload)->assertCreated();
        $this->postJson('/api/portal/register', [...$payload, 'email' => 'second@example.test'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);

        $studentUser = User::query()->where('email', 'student@example.test')->firstOrFail();
        $this->actingAs($studentUser)
            ->getJson('/api/portal/children')
            ->assertOk()
            ->assertJsonPath('data.0.id', $student->id);
    }

    public function test_fee_payment_cannot_exceed_outstanding_balance(): void
    {
        $student = Student::query()->forceCreate([
            'admission_number' => 'ADM-FEE-1',
            'first_name' => 'Sam',
            'admission_date' => '2026-04-01',
            'status' => 'active',
        ]);
        $this->postJson('/api/fees/charges', [
            'student_id' => $student->id,
            'description' => 'Annual fee',
            'amount' => 500,
            'due_on' => '2026-11-01',
        ])->assertCreated();
        $this->getJson('/api/fees')->assertOk()->assertJsonPath('students.0.id', $student->id);
        $this->postJson('/api/fees/payments', [
            'student_id' => $student->id,
            'amount' => 501,
            'paid_at' => '2026-10-30',
            'method' => 'cash',
        ])->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        $this->assertDatabaseCount('fee_payments', 0);
    }

    public function test_teacher_attendance_is_limited_to_assigned_sections(): void
    {
        [$session, $schoolClass, $section] = $this->academicTarget();
        $otherSection = Section::query()->create(['school_class_id' => $schoolClass->id, 'name' => 'B']);
        $teacher = User::factory()->create();
        $teacher->roles()->sync([Role::query()->where('name', 'teacher')->value('id')]);
        $employee = Employee::query()->create([
            'user_id' => $teacher->id,
            'first_name' => 'Teacher',
            'employment_type' => 'full_time',
            'is_teaching_staff' => true,
            'joining_date' => '2026-01-01',
        ]);
        TeacherAssignment::query()->create([
            'employee_id' => $employee->id,
            'academic_session_id' => $session->id,
            'school_class_id' => $schoolClass->id,
            'section_id' => $section->id,
        ]);

        $this->actingAs($teacher)
            ->getJson('/api/attendance?academic_session_id='.$session->id.'&school_class_id='.$schoolClass->id.'&section_id='.$otherSection->id.'&attendance_date=2026-10-30')
            ->assertForbidden();
    }

    private function academicTarget(): array
    {
        $session = AcademicSession::query()->create([
            'name' => '2026-2027',
            'starts_on' => '2026-04-01',
            'ends_on' => '2027-03-31',
            'is_active' => true,
        ]);
        $schoolClass = SchoolClass::query()->create(['academic_session_id' => $session->id, 'name' => 'Class 5']);
        $section = Section::query()->create(['school_class_id' => $schoolClass->id, 'name' => 'A']);
        $subject = Subject::query()->create([
            'academic_session_id' => $session->id,
            'name' => 'Mathematics',
            'code' => 'MATH',
            'type' => 'compulsory',
        ]);
        $schoolClass->subjects()->attach($subject);

        return [$session, $schoolClass, $section, $subject];
    }
}
