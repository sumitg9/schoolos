<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Employee;
use App\Models\ExamSchedule;
use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\TeacherAssignment;
use App\Models\TimetableSlot;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcademicManagementApiTest extends TestCase
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

    public function test_exam_schedule_marks_and_printable_report_card_workflow(): void
    {
        [$session, $schoolClass, $section, $subject] = $this->createAcademicTarget();
        $student = Student::query()->create([
            'admission_number' => 'ADM-100001',
            'first_name' => 'Jordan',
            'last_name' => 'Kim',
            'admission_date' => '2026-04-01',
            'status' => 'active',
        ]);
        $enrollment = StudentEnrollment::query()->create([
            'student_id' => $student->id,
            'academic_session_id' => $session->id,
            'school_class_id' => $schoolClass->id,
            'section_id' => $section->id,
            'roll_number' => '12',
            'enrolled_on' => '2026-04-01',
            'status' => 'active',
        ]);

        $examId = $this->postJson('/api/exams', [
            'academic_session_id' => $session->id,
            'school_class_id' => $schoolClass->id,
            'title' => 'Term 1',
            'starts_on' => '2026-10-12',
            'ends_on' => '2026-10-12',
            'subjects' => [[
                'subject_id' => $subject->id,
                'exam_date' => '2026-10-12',
                'starts_at' => '09:00',
                'duration_minutes' => 90,
                'maximum_marks' => 100,
                'passing_marks' => 35,
            ]],
            'grading_scale' => [
                ['label' => 'Excellent', 'min_percentage' => 90],
                ['label' => 'Satisfactory', 'min_percentage' => 0],
            ],
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');

        $examSubjectId = ExamSchedule::query()->findOrFail($examId)->subjects()->value('id');
        $this->putJson("/api/exams/{$examId}/marks", [
            'marks' => [[
                'exam_schedule_subject_id' => $examSubjectId,
                'student_enrollment_id' => $enrollment->id,
                'marks_obtained' => 85,
            ]],
        ])->assertUnprocessable();
        $this->postJson("/api/exams/{$examId}/publish")->assertOk()->assertJsonPath('data.status', 'published');
        $this->postJson("/api/exams/{$examId}/approve-results")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['results']);
        $this->putJson("/api/exams/{$examId}/marks", [
            'marks' => [[
                'exam_schedule_subject_id' => $examSubjectId,
                'student_enrollment_id' => $enrollment->id,
                'marks_obtained' => 101,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['marks.0.marks_obtained']);
        $this->putJson("/api/exams/{$examId}/marks", [
            'marks' => [[
                'exam_schedule_subject_id' => $examSubjectId,
                'student_enrollment_id' => $enrollment->id,
                'marks_obtained' => 85,
                'remarks' => 'Strong work',
            ]],
        ])->assertOk()->assertJsonPath('saved_count', 1);
        $this->postJson("/api/exams/{$examId}/approve-results")
            ->assertOk()
            ->assertJsonPath('data.results_status', 'approved');
        $this->putJson("/api/exams/{$examId}/marks", [
            'marks' => [[
                'exam_schedule_subject_id' => $examSubjectId,
                'student_enrollment_id' => $enrollment->id,
                'marks_obtained' => 90,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['results']);
        $this->postJson("/api/exams/{$examId}/publish-results")
            ->assertOk()
            ->assertJsonPath('data.results_status', 'published');
        $this->getJson("/api/exams/{$examId}/report-cards")
            ->assertOk()
            ->assertJsonPath('cards.0.percentage', 85)
            ->assertJsonPath('cards.0.grade', 'Satisfactory')
            ->assertJsonPath('cards.0.results_status', 'published')
            ->assertJsonPath('cards.0.result', 'PASS')
            ->assertJsonPath('cards.0.subjects.0.remarks', 'Strong work');
    }

    public function test_report_cards_include_students_from_completed_historical_enrollments(): void
    {
        [$session, $schoolClass, $section, $subject] = $this->createAcademicTarget();
        $student = Student::query()->create([
            'admission_number' => 'ADM-HISTORY-001',
            'first_name' => 'Historical',
            'last_name' => 'Student',
            'admission_date' => '2026-04-01',
            'status' => 'active',
        ]);
        $enrollment = $this->enrollStudent($student, $session, $schoolClass, $section);
        $enrollment->update(['status' => 'completed']);

        $examId = $this->postJson('/api/exams', [
            'academic_session_id' => $session->id,
            'school_class_id' => $schoolClass->id,
            'title' => 'Historical term exam',
            'starts_on' => '2026-10-12',
            'ends_on' => '2026-10-12',
            'subjects' => [[
                'subject_id' => $subject->id,
                'exam_date' => '2026-10-12',
                'starts_at' => '09:00',
                'duration_minutes' => 90,
                'maximum_marks' => 100,
                'passing_marks' => 35,
            ]],
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/exams/{$examId}/publish")->assertOk();

        $this->getJson("/api/exams/{$examId}/report-cards")
            ->assertOk()
            ->assertJsonPath('cards.0.enrollment_id', $enrollment->id)
            ->assertJsonPath('cards.0.student.first_name', 'Historical');
    }

    public function test_homework_requires_matching_session_class_subject_and_due_date(): void
    {
        [$session, $schoolClass, $section, $subject] = $this->createAcademicTarget();
        $this->postJson('/api/homework', [
            'academic_session_id' => $session->id,
            'school_class_id' => $schoolClass->id,
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'title' => 'Read chapter 1',
            'description' => 'Read and answer the review questions.',
            'assigned_on' => '2026-10-15',
            'due_on' => '2026-10-14',
        ])->assertUnprocessable()->assertJsonValidationErrors(['due_on']);

        $this->postJson('/api/homework', [
            'academic_session_id' => $session->id,
            'school_class_id' => $schoolClass->id,
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'title' => 'Read chapter 1',
            'description' => 'Read and answer the review questions.',
            'assigned_on' => '2026-10-14',
            'due_on' => '2026-10-15',
        ])->assertCreated()->assertJsonPath('data.subject.id', $subject->id);

        $this->getJson('/api/homework')->assertOk()->assertJsonPath('data.data.0.title', 'Read chapter 1');
    }

    public function test_homework_rejects_a_subject_not_mapped_to_the_selected_class(): void
    {
        [$session, $schoolClass, $section] = $this->createAcademicTarget();
        $otherClass = SchoolClass::query()->create([
            'academic_session_id' => $session->id,
            'name' => 'Class 2',
        ]);
        $subject = Subject::query()->create([
            'academic_session_id' => $session->id,
            'name' => 'Class 2 Science',
            'code' => 'SCI-2',
        ]);
        $otherClass->subjects()->attach($subject);

        $this->postJson('/api/homework', [
            'academic_session_id' => $session->id,
            'school_class_id' => $schoolClass->id,
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'title' => 'Class-specific work',
            'description' => 'This subject belongs to a different class.',
            'assigned_on' => '2026-10-14',
            'due_on' => '2026-10-15',
        ])->assertUnprocessable()->assertJsonValidationErrors(['subject_id']);
    }

    public function test_exam_schedule_rejects_overlapping_subject_times(): void
    {
        [$session, $schoolClass, , $subject] = $this->createAcademicTarget();
        $secondSubject = Subject::query()->create([
            'academic_session_id' => $session->id,
            'name' => 'Science',
            'code' => 'SCI',
        ]);
        $schoolClass->subjects()->attach($secondSubject);
        $this->postJson('/api/exams', [
            'academic_session_id' => $session->id,
            'school_class_id' => $schoolClass->id,
            'title' => 'Overlapping schedule',
            'starts_on' => '2026-10-12',
            'ends_on' => '2026-10-12',
            'subjects' => [
                [
                    'subject_id' => $subject->id,
                    'exam_date' => '2026-10-12',
                    'starts_at' => '09:00',
                    'duration_minutes' => 90,
                    'maximum_marks' => 100,
                    'passing_marks' => 35,
                ],
                [
                    'subject_id' => $secondSubject->id,
                    'exam_date' => '2026-10-12',
                    'starts_at' => '10:00',
                    'duration_minutes' => 60,
                    'maximum_marks' => 100,
                    'passing_marks' => 35,
                ],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors(['subjects.1.starts_at']);
        $this->assertDatabaseCount('exam_schedules', 0);
    }

    public function test_timetable_rejects_time_overlaps_for_the_same_section(): void
    {
        [$session, $schoolClass, $section, $subject] = $this->createAcademicTarget();
        $teacher = Employee::query()->create([
            'first_name' => 'Teacher',
            'employment_type' => 'full_time',
            'is_teaching_staff' => true,
            'joining_date' => '2026-01-01',
        ]);
        TeacherAssignment::query()->create([
            'employee_id' => $teacher->id,
            'academic_session_id' => $session->id,
            'school_class_id' => $schoolClass->id,
            'section_id' => $section->id,
            'subject_id' => $subject->id,
        ]);
        $payload = [
            'academic_session_id' => $session->id,
            'school_class_id' => $schoolClass->id,
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'employee_id' => $teacher->id,
            'weekday' => 1,
            'period_number' => 1,
            'starts_at' => '09:00',
            'ends_at' => '09:45',
            'room_number' => 'A-1',
        ];
        $this->postJson('/api/timetable', $payload)->assertCreated();
        $this->postJson('/api/timetable', [...$payload, 'period_number' => 2, 'starts_at' => '09:30', 'ends_at' => '10:15'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at']);
        $this->postJson('/api/timetable', [...$payload, 'starts_at' => '10:00', 'ends_at' => '10:45'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['period_number']);

        $this->assertSame(1, TimetableSlot::query()->count());
    }

    public function test_notices_created_by_teachers_are_drafts_and_cannot_be_published_by_them(): void
    {
        $teacher = User::factory()->create();
        $teacher->roles()->sync([Role::query()->where('name', 'teacher')->value('id')]);
        $this->actingAs($teacher);
        $noticeId = $this->postJson('/api/notices', [
            'title' => 'Library hours',
            'body' => 'The library will close at 4 PM.',
            'audience' => 'staff',
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
        $this->postJson("/api/notices/{$noticeId}/publish")->assertForbidden();

        $admin = User::factory()->create();
        $admin->roles()->sync([Role::query()->where('name', 'super-admin')->value('id')]);
        $this->actingAs($admin)->postJson("/api/notices/{$noticeId}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published');
        $this->assertDatabaseHas('school_notices', ['id' => $noticeId, 'status' => 'published']);
    }

    public function test_teacher_cannot_read_or_edit_marks_outside_assigned_subjects_and_sections(): void
    {
        [$session, $schoolClass, $section, $subject, $otherSubject] = $this->createAcademicTarget(withOtherSubject: true);
        $otherSection = Section::query()->create(['school_class_id' => $schoolClass->id, 'name' => 'B']);
        $firstStudent = Student::query()->create(['admission_number' => 'ADM-200001', 'first_name' => 'A', 'admission_date' => '2026-04-01']);
        $secondStudent = Student::query()->create(['admission_number' => 'ADM-200002', 'first_name' => 'B', 'admission_date' => '2026-04-01']);
        $firstEnrollment = $this->enrollStudent($firstStudent, $session, $schoolClass, $section);
        $secondEnrollment = $this->enrollStudent($secondStudent, $session, $schoolClass, $otherSection);
        $exam = ExamSchedule::query()->create([
            'academic_session_id' => $session->id,
            'school_class_id' => $schoolClass->id,
            'title' => 'Term exam',
            'starts_on' => '2026-10-12',
            'ends_on' => '2026-10-12',
            'status' => 'published',
        ]);
        $allowedExamSubject = $exam->subjects()->create([
            'subject_id' => $subject->id,
            'exam_date' => '2026-10-12',
            'starts_at' => '09:00',
            'duration_minutes' => 60,
            'maximum_marks' => 100,
            'passing_marks' => 35,
        ]);
        $unassignedExamSubject = $exam->subjects()->create([
            'subject_id' => $otherSubject->id,
            'exam_date' => '2026-10-12',
            'starts_at' => '11:00',
            'duration_minutes' => 60,
            'maximum_marks' => 100,
            'passing_marks' => 35,
        ]);
        $teacherUser = User::factory()->create();
        $teacherUser->roles()->sync([Role::query()->where('name', 'teacher')->value('id')]);
        $employee = Employee::query()->create([
            'user_id' => $teacherUser->id,
            'first_name' => 'Assigned',
            'employment_type' => 'full_time',
            'is_teaching_staff' => true,
            'joining_date' => '2026-01-01',
        ]);
        TeacherAssignment::query()->create([
            'employee_id' => $employee->id,
            'academic_session_id' => $session->id,
            'school_class_id' => $schoolClass->id,
            'section_id' => $section->id,
            'subject_id' => $subject->id,
        ]);

        $this->actingAs($teacherUser)
            ->getJson("/api/exams/{$exam->id}/marks")
            ->assertOk()
            ->assertJsonCount(1, 'enrollments')
            ->assertJsonCount(1, 'exam.subjects');
        $this->putJson("/api/exams/{$exam->id}/marks", [
            'marks' => [[
                'exam_schedule_subject_id' => $unassignedExamSubject->id,
                'student_enrollment_id' => $firstEnrollment->id,
                'marks_obtained' => 50,
            ]],
        ])->assertUnprocessable();
        $this->putJson("/api/exams/{$exam->id}/marks", [
            'marks' => [[
                'exam_schedule_subject_id' => $allowedExamSubject->id,
                'student_enrollment_id' => $secondEnrollment->id,
                'marks_obtained' => 50,
            ]],
        ])->assertUnprocessable();
    }

    /**
     * @return array{AcademicSession, SchoolClass, Section, Subject, ?Subject}
     */
    private function createAcademicTarget(bool $withOtherSubject = false): array
    {
        $session = AcademicSession::query()->create([
            'name' => '2026-2027',
            'starts_on' => '2026-04-01',
            'ends_on' => '2027-03-31',
            'is_active' => true,
        ]);
        $schoolClass = SchoolClass::query()->create([
            'academic_session_id' => $session->id,
            'name' => 'Class 1',
        ]);
        $section = Section::query()->create(['school_class_id' => $schoolClass->id, 'name' => 'A']);
        $subject = Subject::query()->create([
            'academic_session_id' => $session->id,
            'name' => 'Mathematics',
            'code' => 'MATH',
            'type' => 'compulsory',
        ]);
        $schoolClass->subjects()->attach($subject);
        $otherSubject = $withOtherSubject ? Subject::query()->create([
            'academic_session_id' => $session->id,
            'name' => 'Science',
            'code' => 'SCI',
            'type' => 'compulsory',
        ]) : null;
        if ($otherSubject) {
            $schoolClass->subjects()->attach($otherSubject);
        }

        return [$session, $schoolClass, $section, $subject, $otherSubject];
    }

    private function enrollStudent(Student $student, AcademicSession $session, SchoolClass $schoolClass, Section $section): StudentEnrollment
    {
        return StudentEnrollment::query()->create([
            'student_id' => $student->id,
            'academic_session_id' => $session->id,
            'school_class_id' => $schoolClass->id,
            'section_id' => $section->id,
            'enrolled_on' => '2026-04-01',
            'status' => 'active',
        ]);
    }
}
