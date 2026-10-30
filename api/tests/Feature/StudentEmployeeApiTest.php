<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Employee;
use App\Models\Guardian;
use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\StudentEnrollmentHistory;
use App\Models\TeacherAssignment;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StudentEmployeeApiTest extends TestCase
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

    public function test_admission_reuses_guardians_and_preserves_enrollment_history_through_transfer_promotion_and_withdrawal(): void
    {
        [$firstSession, $firstClass, $firstSection] = $this->createTarget('2026-2027', 'Class 1', 'A');
        [, $secondClass, $secondSection] = $this->createTarget('2026-2027', 'Class 2', 'B');
        [$nextSession, $nextClass, $nextSection] = $this->createTarget('2027-2028', 'Class 3', 'C');

        $studentId = $this->admitStudent($firstSession, $firstClass, $firstSection, 'Asha', '1');
        $this->admitStudent($firstSession, $firstClass, $firstSection, 'Ravi', '2');
        $this->assertSame(1, Guardian::query()->count());
        $this->assertDatabaseHas('students', ['id' => $studentId, 'admission_number' => 'ADM-000001']);

        $this->postJson("/api/students/{$studentId}/transfer", [
            'school_class_id' => $secondClass->id,
            'section_id' => $secondSection->id,
            'roll_number' => '1',
            'reason' => 'Rebalanced class placement',
        ])->assertOk();

        $this->postJson("/api/students/{$studentId}/promote", [
            'academic_session_id' => $nextSession->id,
            'school_class_id' => $nextClass->id,
            'section_id' => $nextSection->id,
            'roll_number' => '1',
            'enrolled_on' => '2027-04-01',
            'reason' => 'Promoted to next session',
        ])->assertOk();

        $this->deleteJson("/api/students/{$studentId}", ['reason' => 'Family relocation'])->assertOk();
        $student = Student::query()->with('enrollments')->findOrFail($studentId);
        $this->assertSame('withdrawn', $student->status);
        $this->assertSame(['completed', 'withdrawn'], $student->enrollments->pluck('status')->all());
        $this->assertSame(
            ['admitted', 'class_transferred', 'promoted', 'withdrawn'],
            StudentEnrollmentHistory::query()->where('student_id', $studentId)->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_student_admission_rejects_a_roll_number_already_active_in_the_section(): void
    {
        [$session, $schoolClass, $section] = $this->createTarget('2026-2027', 'Class 1', 'A');
        $this->admitStudent($session, $schoolClass, $section, 'Asha', '7');

        $this->postJson('/api/students', $this->admissionPayload($session, $schoolClass, $section, 'Ravi', '7'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['roll_number']);
    }

    public function test_student_admission_rejects_a_full_class(): void
    {
        [$session, $schoolClass, $section] = $this->createTarget('2026-2027', 'Class 1', 'A', 1);
        $this->admitStudent($session, $schoolClass, $section, 'Asha', '1');

        $this->postJson('/api/students', $this->admissionPayload($session, $schoolClass, $section, 'Ravi', '2'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['school_class_id']);
    }

    public function test_teacher_student_visibility_is_limited_to_active_assigned_sections(): void
    {
        [$session, $schoolClass, $section] = $this->createTarget('2026-2027', 'Class 1', 'A');
        $studentId = $this->admitStudent($session, $schoolClass, $section, 'Asha', '1');
        $otherSection = Section::query()->create(['school_class_id' => $schoolClass->id, 'name' => 'B']);
        $this->admitStudent($session, $schoolClass, $otherSection, 'Ravi', '2');

        $teacher = User::factory()->create();
        $teacher->roles()->sync([Role::query()->where('name', 'teacher')->value('id')]);
        $employee = Employee::query()->create([
            'user_id' => $teacher->id,
            'first_name' => 'Taylor',
            'employment_type' => 'full_time',
            'is_teaching_staff' => true,
            'joining_date' => '2026-01-01',
        ]);
        $assignment = TeacherAssignment::query()->create([
            'employee_id' => $employee->id,
            'academic_session_id' => $session->id,
            'school_class_id' => $schoolClass->id,
            'section_id' => $section->id,
        ]);

        $this->actingAs($teacher)
            ->getJson('/api/students')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $studentId);

        $assignment->update(['status' => 'inactive']);
        $this->getJson('/api/students')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_medical_records_are_denied_to_roles_without_the_restricted_permission(): void
    {
        [$session, $schoolClass, $section] = $this->createTarget('2026-2027', 'Class 1', 'A');
        $studentId = $this->admitStudent($session, $schoolClass, $section, 'Asha', '1');
        $principal = User::factory()->create();
        $principal->roles()->sync([Role::query()->where('name', 'principal')->value('id')]);

        $this->actingAs($principal)
            ->getJson("/api/students/{$studentId}/medical-record")
            ->assertForbidden();
    }

    public function test_student_csv_import_creates_admissions_and_reports_bad_rows(): void
    {
        [$session, $schoolClass, $section] = $this->createTarget('2026-2027', 'Class 1', 'A');
        $csv = "first_name,date_of_birth,guardian_first_name,guardian_phone,guardian_relationship,roll_number,email,gender\n".
            "Asha,2018-01-01,Morgan,555-0100,Parent,1,,\n".
            ",2018-01-01,Morgan,555-0100,Parent,2,,\n";

        $this->post('/api/students/import', [
            'academic_session_id' => $session->id,
            'school_class_id' => $schoolClass->id,
            'section_id' => $section->id,
            'file' => UploadedFile::fake()->createWithContent('students.csv', $csv),
        ])->assertOk()
            ->assertJsonPath('created_count', 1)
            ->assertJsonPath('error_count', 1);
        $this->assertDatabaseHas('students', ['first_name' => 'Asha']);
        $this->assertMatchesRegularExpression(
            '/^ADM-\d{6}$/',
            (string) Student::query()->where('first_name', 'Asha')->value('admission_number'),
        );
    }

    public function test_teacher_assignments_reject_multiple_class_teachers_for_the_same_scope(): void
    {
        [$session, $schoolClass, $section] = $this->createTarget('2026-2027', 'Class 1', 'A');
        $firstTeacher = Employee::query()->create([
            'first_name' => 'Alex',
            'employment_type' => 'full_time',
            'is_teaching_staff' => true,
            'joining_date' => '2026-01-01',
        ]);
        $secondTeacher = Employee::query()->create([
            'first_name' => 'Sam',
            'employment_type' => 'full_time',
            'is_teaching_staff' => true,
            'joining_date' => '2026-01-01',
        ]);

        $payload = [
            'academic_session_id' => $session->id,
            'school_class_id' => $schoolClass->id,
            'section_id' => $section->id,
            'is_class_teacher' => true,
        ];
        $this->postJson('/api/teacher-assignments', ['employee_id' => $firstTeacher->id, ...$payload])->assertCreated();
        $this->postJson('/api/teacher-assignments', ['employee_id' => $secondTeacher->id, ...$payload])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['is_class_teacher']);
    }

    public function test_student_documents_are_private_and_download_permission_is_enforced(): void
    {
        Storage::fake('local');
        [$session, $schoolClass, $section] = $this->createTarget('2026-2027', 'Class 1', 'A');
        $studentId = $this->admitStudent($session, $schoolClass, $section, 'Asha', '1');
        $documentId = $this->postJson("/api/students/{$studentId}/documents", [
            'category' => 'photograph',
            'document' => UploadedFile::fake()->image('student.jpg'),
        ])->assertCreated()->json('data.id');

        $document = StudentDocument::query()->findOrFail($documentId);
        Storage::disk('local')->assertExists($document->stored_path);
        $download = $this->get("/api/student-documents/{$documentId}/download")->assertOk();
        $this->assertStringContainsString('private', (string) $download->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $download->headers->get('Cache-Control'));

        $teacher = User::factory()->create();
        $teacher->roles()->sync([Role::query()->where('name', 'teacher')->value('id')]);
        $this->actingAs($teacher)->getJson("/api/student-documents/{$documentId}/download")->assertForbidden();
    }

    public function test_employee_csv_import_creates_records_and_reports_invalid_rows(): void
    {
        $csv = "first_name,last_name,email,employment_type,is_teaching_staff,joining_date\n".
            "Jamie,,,full_time,true,2026-06-01\n".
            ",Invalid,,full_time,false,2026-06-01\n";
        $response = $this->post('/api/employees/import', [
            'file' => UploadedFile::fake()->createWithContent('employees.csv', $csv),
        ]);

        $response->assertOk()
            ->assertJsonPath('created_count', 1)
            ->assertJsonPath('error_count', 1);
        $this->assertDatabaseHas('employees', ['first_name' => 'Jamie', 'email' => null, 'is_teaching_staff' => true]);
        $this->assertMatchesRegularExpression(
            '/^EMP-\d{6}$/',
            (string) Employee::query()->where('first_name', 'Jamie')->value('employee_id'),
        );
    }

    public function test_employee_import_export_routes_are_available_with_permissions(): void
    {
        $this->get('/api/employees/import-template')->assertOk();
        $this->get('/api/employees/export')->assertOk();
    }

    /**
     * @return array{AcademicSession, SchoolClass, Section}
     */
    private function createTarget(string $sessionName, string $className, string $sectionName, ?int $capacity = null): array
    {
        $session = AcademicSession::query()->firstOrCreate(['name' => $sessionName], [
            'starts_on' => $sessionName === '2026-2027' ? '2026-04-01' : '2027-04-01',
            'ends_on' => $sessionName === '2026-2027' ? '2027-03-31' : '2028-03-31',
            'is_active' => true,
        ]);
        $schoolClass = SchoolClass::query()->create([
            'academic_session_id' => $session->id,
            'name' => $className,
            'capacity' => $capacity,
        ]);
        $section = Section::query()->create(['school_class_id' => $schoolClass->id, 'name' => $sectionName]);

        return [$session, $schoolClass, $section];
    }

    private function admitStudent(AcademicSession $session, SchoolClass $schoolClass, Section $section, string $name, string $roll): int
    {
        return $this->postJson('/api/students', $this->admissionPayload($session, $schoolClass, $section, $name, $roll))
            ->assertCreated()
            ->json('data.id');
    }

    private function admissionPayload(AcademicSession $session, SchoolClass $schoolClass, Section $section, string $name, string $roll): array
    {
        return [
            'first_name' => $name,
            'date_of_birth' => '2018-01-01',
            'admission_date' => '2026-04-01',
            'academic_session_id' => $session->id,
            'school_class_id' => $schoolClass->id,
            'section_id' => $section->id,
            'roll_number' => $roll,
            'guardians' => [[
                'first_name' => 'Morgan',
                'last_name' => 'Lee',
                'phone' => '555-0100',
                'relationship' => 'Parent',
                'is_primary' => true,
            ]],
        ];
    }
}
