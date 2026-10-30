<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\ExamSchedule;
use App\Models\ExamScheduleSubject;
use App\Models\HomeworkAssignment;
use App\Models\SchoolClass;
use App\Models\SchoolNotice;
use App\Models\Section;
use App\Models\StudentEnrollment;
use App\Models\StudentExamMark;
use App\Models\Subject;
use App\Models\TeacherAssignment;
use App\Models\TimetableSlot;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AcademicManagementController extends Controller
{
    public function exams(Request $request): JsonResponse
    {
        $query = ExamSchedule::query()->with([
            'academicSession:id,name',
            'schoolClass:id,name',
            'subjects.subject:id,name,code',
        ])->orderByDesc('starts_on');

        if ($request->filled('academic_session_id')) {
            $query->where('academic_session_id', $request->integer('academic_session_id'));
        }
        if ($request->filled('school_class_id')) {
            $query->where('school_class_id', $request->integer('school_class_id'));
        }
        if ($this->isTeacher($request->user())) {
            $employeeId = $this->employeeId($request->user());
            $query->where('status', 'published')->whereExists(function ($assignments) use ($employeeId) {
                $assignments->selectRaw('1')
                    ->from('teacher_assignments')
                    ->whereColumn('teacher_assignments.academic_session_id', 'exam_schedules.academic_session_id')
                    ->whereColumn('teacher_assignments.school_class_id', 'exam_schedules.school_class_id')
                    ->where('teacher_assignments.employee_id', $employeeId)
                    ->where('teacher_assignments.status', 'active');
            });
        }

        return response()->json(['data' => $query->paginate(max(1, min($request->integer('per_page', 50), 100)))]);
    }

    public function createExam(Request $request): JsonResponse
    {
        $data = $request->validate([
            'academic_session_id' => ['required', 'integer', 'exists:academic_sessions,id'],
            'school_class_id' => ['required', 'integer', 'exists:school_classes,id'],
            'title' => ['required', 'string', 'max:150'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'subjects' => ['required', 'array', 'min:1', 'max:40'],
            'subjects.*.subject_id' => ['required', 'integer', 'distinct', 'exists:subjects,id'],
            'subjects.*.exam_date' => ['required', 'date', 'after_or_equal:starts_on', 'before_or_equal:ends_on'],
            'subjects.*.starts_at' => ['required', 'date_format:H:i'],
            'subjects.*.duration_minutes' => ['required', 'integer', 'min:15', 'max:360'],
            'subjects.*.maximum_marks' => ['required', 'numeric', 'gt:0', 'max:10000'],
            'subjects.*.passing_marks' => ['required', 'numeric', 'min:0', 'max:10000'],
            'grading_scale' => ['nullable', 'array', 'min:2', 'max:10'],
            'grading_scale.*.label' => ['required', 'string', 'max:24'],
            'grading_scale.*.min_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);
        if (isset($data['grading_scale'])) {
            $labels = array_column($data['grading_scale'], 'label');
            $thresholds = array_map(
                fn ($threshold) => (int) round((float) $threshold * 100),
                array_column($data['grading_scale'], 'min_percentage'),
            );
            if (count(array_unique($labels)) !== count($labels) || count(array_unique($thresholds)) !== count($thresholds)) {
                throw ValidationException::withMessages([
                    'grading_scale' => ['Grade labels and percentage thresholds must be unique.'],
                ]);
            }
            usort($data['grading_scale'], fn ($left, $right) => (float) $right['min_percentage'] <=> (float) $left['min_percentage']);
            if ((float) end($data['grading_scale'])['min_percentage'] !== 0.0) {
                throw ValidationException::withMessages([
                    'grading_scale' => ['The lowest grade threshold must be 0 so every result receives a grade.'],
                ]);
            }
        } else {
            $data['grading_scale'] = $this->defaultGradingScale();
        }
        foreach ($data['subjects'] as $index => $subjectData) {
            foreach (array_slice($data['subjects'], 0, $index) as $scheduledSubject) {
                if ($subjectData['exam_date'] === $scheduledSubject['exam_date']
                    && $this->examTimesOverlap($subjectData, $scheduledSubject)) {
                    throw ValidationException::withMessages([
                        "subjects.{$index}.starts_at" => ['Subject exam times cannot overlap on the same date.'],
                    ]);
                }
            }
        }
        $this->validateClassSession($data['academic_session_id'], $data['school_class_id']);
        $existingSchedules = ExamScheduleSubject::query()
            ->whereHas('examSchedule', fn ($query) => $query
                ->where('academic_session_id', $data['academic_session_id'])
                ->where('school_class_id', $data['school_class_id']))
            ->get(['exam_date', 'starts_at', 'duration_minutes']);
        foreach ($data['subjects'] as $index => $subjectData) {
            foreach ($existingSchedules as $scheduledSubject) {
                if ($subjectData['exam_date'] === $scheduledSubject->exam_date->toDateString()
                    && $this->examTimesOverlap($subjectData, [
                        'starts_at' => substr((string) $scheduledSubject->starts_at, 0, 5),
                        'duration_minutes' => $scheduledSubject->duration_minutes,
                    ])) {
                    throw ValidationException::withMessages([
                        "subjects.{$index}.starts_at" => ['This subject overlaps an existing examination in this class.'],
                    ]);
                }
            }
        }

        foreach ($data['subjects'] as $index => $subjectData) {
            if (Subject::query()->whereKey($subjectData['subject_id'])
                ->where('academic_session_id', $data['academic_session_id'])
                ->whereHas('classes', fn ($query) => $query->whereKey($data['school_class_id']))
                ->doesntExist()) {
                throw ValidationException::withMessages([
                    "subjects.{$index}.subject_id" => ['The subject must be assigned to the selected class and academic session.'],
                ]);
            }
            if ((float) $subjectData['passing_marks'] > (float) $subjectData['maximum_marks']) {
                throw ValidationException::withMessages([
                    "subjects.{$index}.passing_marks" => ['Passing marks cannot exceed maximum marks.'],
                ]);
            }
        }
        if ($this->isTeacher($request->user())) {
            foreach ($data['subjects'] as $subjectData) {
                $this->ensureTeachingAssignment(
                    $request->user(),
                    $data['academic_session_id'],
                    $data['school_class_id'],
                    $subjectData['subject_id'],
                );
            }
        }

        $exam = DB::transaction(function () use ($data, $request) {
            $exam = ExamSchedule::query()->create([
                'academic_session_id' => $data['academic_session_id'],
                'school_class_id' => $data['school_class_id'],
                'title' => $data['title'],
                'starts_on' => $data['starts_on'],
                'ends_on' => $data['ends_on'],
                'status' => 'draft',
                'results_status' => 'draft',
                'grading_scale' => $data['grading_scale'],
                'created_by' => $request->user()->id,
            ]);
            foreach ($data['subjects'] as $subjectData) {
                $exam->subjects()->create($subjectData);
            }

            return $exam;
        });
        AuditLog::record('exam_schedule.created', $exam, [
            'subject_count' => count($data['subjects']),
            'school_class_id' => $exam->school_class_id,
        ]);

        return response()->json(['data' => $this->loadExam($exam)], 201);
    }

    public function publishExam(Request $request, ExamSchedule $exam): JsonResponse
    {
        if ($exam->status !== 'draft') {
            throw ValidationException::withMessages(['exam' => ['Only a draft examination schedule can be published.']]);
        }
        if ($exam->subjects()->doesntExist()) {
            throw ValidationException::withMessages(['subjects' => ['Add at least one subject before publishing this exam.']]);
        }
        $exam->update(['status' => 'published']);
        AuditLog::record('exam_schedule.published', $exam);

        return response()->json(['data' => $this->loadExam($exam->refresh())]);
    }

    public function deleteExam(ExamSchedule $exam): JsonResponse
    {
        if ($exam->subjects()->whereHas('marks')->exists()) {
            throw ValidationException::withMessages(['exam' => ['An exam with recorded marks cannot be deleted.']]);
        }
        $exam->delete();
        AuditLog::record('exam_schedule.deleted', $exam);

        return response()->json(status: 204);
    }

    public function approveResults(Request $request, ExamSchedule $exam): JsonResponse
    {
        if ($exam->status !== 'published') {
            throw ValidationException::withMessages(['exam' => ['Publish the examination schedule before approving results.']]);
        }
        if ($exam->results_status !== 'draft') {
            throw ValidationException::withMessages(['results' => ['Only draft results can be approved.']]);
        }
        $enrollmentIds = StudentEnrollment::query()
            ->where('academic_session_id', $exam->academic_session_id)
            ->where('school_class_id', $exam->school_class_id)
            ->where('status', 'active')
            ->pluck('id');
        $subjectIds = $exam->subjects()->pluck('id');
        if ($enrollmentIds->isEmpty() || $subjectIds->isEmpty()) {
            throw ValidationException::withMessages(['results' => ['Results require active students and scheduled subjects.']]);
        }
        $expectedMarks = $enrollmentIds->count() * $subjectIds->count();
        $recordedMarks = StudentExamMark::query()
            ->whereIn('student_enrollment_id', $enrollmentIds)
            ->whereIn('exam_schedule_subject_id', $subjectIds)
            ->count();
        if ($recordedMarks !== $expectedMarks) {
            throw ValidationException::withMessages([
                'results' => ['Enter marks for every active student and scheduled subject before approving results.'],
            ]);
        }

        $exam->update([
            'results_status' => 'approved',
            'results_approved_by' => $request->user()->id,
            'results_approved_at' => now(),
        ]);
        AuditLog::record('exam_results.approved', $exam, [
            'recorded_marks' => $recordedMarks,
            'enrollment_count' => $enrollmentIds->count(),
        ]);

        return response()->json(['data' => $this->loadExam($exam->refresh())]);
    }

    public function publishResults(Request $request, ExamSchedule $exam): JsonResponse
    {
        if ($exam->results_status !== 'approved') {
            throw ValidationException::withMessages(['results' => ['Approve examination results before publishing them.']]);
        }

        $exam->update([
            'results_status' => 'published',
            'results_published_by' => $request->user()->id,
            'results_published_at' => now(),
        ]);
        AuditLog::record('exam_results.published', $exam);

        return response()->json(['data' => $this->loadExam($exam->refresh())]);
    }

    public function examMarks(Request $request, ExamSchedule $exam): JsonResponse
    {
        $this->ensureExamVisible($request->user(), $exam);
        $exam = $this->loadExam($exam);
        $allowedSubjectIds = null;
        if ($this->isTeacher($request->user())) {
            $employeeId = $this->employeeId($request->user());
            $allowedSubjectIds = TeacherAssignment::query()
                ->where('employee_id', $employeeId)
                ->where('academic_session_id', $exam->academic_session_id)
                ->where('school_class_id', $exam->school_class_id)
                ->where('status', 'active')
                ->whereNotNull('subject_id')
                ->pluck('subject_id');
            $exam->setRelation('subjects', $exam->subjects->whereIn('subject_id', $allowedSubjectIds)->values());
        }
        $enrollments = StudentEnrollment::query()
            ->where('academic_session_id', $exam->academic_session_id)
            ->where('school_class_id', $exam->school_class_id)
            ->where('status', 'active')
            ->when($this->isTeacher($request->user()), function ($query) use ($request) {
                $employeeId = $this->employeeId($request->user());
                $query->whereHas('schoolClass.teacherAssignments', fn ($assignments) => $assignments
                    ->where('employee_id', $employeeId)
                    ->where('status', 'active')
                    ->where(fn ($scope) => $scope->whereNull('section_id')
                        ->orWhereColumn('teacher_assignments.section_id', 'student_enrollments.section_id')));
            })
            ->with(['student:id,admission_number,first_name,last_name', 'section:id,name'])
            ->orderBy('section_id')
            ->orderBy('roll_number')
            ->get();
        $marks = StudentExamMark::query()
            ->whereIn('exam_schedule_subject_id', $exam->subjects->pluck('id'))
            ->whereIn('student_enrollment_id', $enrollments->pluck('id'))
            ->get()
            ->keyBy(fn ($mark) => "{$mark->exam_schedule_subject_id}:{$mark->student_enrollment_id}");

        return response()->json([
            'exam' => $exam,
            'enrollments' => $enrollments,
            'marks' => $marks->values(),
        ]);
    }

    public function saveExamMarks(Request $request, ExamSchedule $exam): JsonResponse
    {
        $this->ensureExamVisible($request->user(), $exam);
        if ($exam->status !== 'published') {
            throw ValidationException::withMessages(['exam' => ['Publish the exam schedule before entering marks.']]);
        }
        if ($exam->results_status !== 'draft') {
            throw ValidationException::withMessages(['results' => ['Marks are locked after results have been approved.']]);
        }
        $data = $request->validate([
            'marks' => ['required', 'array', 'min:1', 'max:2000'],
            'marks.*.exam_schedule_subject_id' => [
                'required', 'integer', Rule::exists('exam_schedule_subjects', 'id')->where('exam_schedule_id', $exam->id),
            ],
            'marks.*.student_enrollment_id' => [
                'required', 'integer',
                Rule::exists('student_enrollments', 'id')
                    ->where('academic_session_id', $exam->academic_session_id)
                    ->where('school_class_id', $exam->school_class_id)
                    ->where('status', 'active'),
            ],
            'marks.*.marks_obtained' => ['required', 'numeric', 'min:0', 'max:10000'],
            'marks.*.remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $entries = [];
        $seen = [];
        foreach ($data['marks'] as $index => $markData) {
            $key = $markData['exam_schedule_subject_id'].':'.$markData['student_enrollment_id'];
            if (isset($seen[$key])) {
                throw ValidationException::withMessages([
                    "marks.{$index}.student_enrollment_id" => ['A mark for this student and subject appears more than once.'],
                ]);
            }
            $seen[$key] = true;
            $examSubject = ExamScheduleSubject::query()->findOrFail($markData['exam_schedule_subject_id']);
            if ((float) $markData['marks_obtained'] > (float) $examSubject->maximum_marks) {
                throw ValidationException::withMessages([
                    "marks.{$index}.marks_obtained" => ['Marks cannot exceed the maximum for this subject.'],
                ]);
            }
            if ($this->isTeacher($request->user())) {
                $enrollment = StudentEnrollment::query()->findOrFail($markData['student_enrollment_id']);
                $this->ensureTeachingAssignment(
                    $request->user(),
                    $exam->academic_session_id,
                    $exam->school_class_id,
                    $examSubject->subject_id,
                    $enrollment->section_id,
                );
            }
            $entries[] = [$examSubject, $markData];
        }

        DB::transaction(function () use ($entries, $request) {
            foreach ($entries as [$examSubject, $markData]) {
                StudentExamMark::query()->updateOrCreate(
                    [
                        'exam_schedule_subject_id' => $examSubject->id,
                        'student_enrollment_id' => $markData['student_enrollment_id'],
                    ],
                    [
                        'marks_obtained' => $markData['marks_obtained'],
                        'remarks' => $markData['remarks'] ?? null,
                        'entered_by' => $request->user()->id,
                    ],
                );
            }
        });
        AuditLog::record('exam_marks.saved', $exam, ['entry_count' => count($entries)]);

        return response()->json(['message' => 'Marks saved.', 'saved_count' => count($entries)]);
    }

    public function reportCards(Request $request, ExamSchedule $exam): JsonResponse
    {
        $this->ensureExamVisible($request->user(), $exam);
        if ($exam->status !== 'published') {
            throw ValidationException::withMessages(['exam' => ['Publish the exam before generating report cards.']]);
        }
        $exam = $this->loadExam($exam);
        $markedEnrollmentIds = StudentExamMark::query()
            ->whereIn('exam_schedule_subject_id', $exam->subjects->pluck('id'))
            ->distinct()
            ->pluck('student_enrollment_id');
        $enrollments = StudentEnrollment::query()
            ->where(function ($query) use ($exam, $markedEnrollmentIds) {
                $query->where(function ($currentEnrollments) use ($exam) {
                    $currentEnrollments
                        ->where('academic_session_id', $exam->academic_session_id)
                        ->where('school_class_id', $exam->school_class_id);
                })->orWhereIn('id', $markedEnrollmentIds);
            })
            ->with(['student:id,admission_number,first_name,last_name', 'section:id,name'])
            ->orderBy('roll_number')
            ->get();
        $marks = StudentExamMark::query()
            ->whereIn('exam_schedule_subject_id', $exam->subjects->pluck('id'))
            ->whereIn('student_enrollment_id', $enrollments->pluck('id'))
            ->get()
            ->groupBy('student_enrollment_id');
        $subjectCount = $exam->subjects->count();
        $cards = $enrollments->map(function ($enrollment) use ($exam, $marks, $subjectCount) {
            $studentMarks = $marks->get($enrollment->id, collect());
            $bySubject = $exam->subjects->map(function ($examSubject) use ($studentMarks) {
                $mark = $studentMarks->firstWhere('exam_schedule_subject_id', $examSubject->id);

                return [
                    'subject' => $examSubject->subject,
                    'maximum_marks' => $examSubject->maximum_marks,
                    'passing_marks' => $examSubject->passing_marks,
                    'marks_obtained' => $mark?->marks_obtained,
                    'remarks' => $mark?->remarks,
                ];
            });
            $complete = $studentMarks->count() === $subjectCount;
            $obtained = (float) $studentMarks->sum(fn ($mark) => (float) $mark->marks_obtained);
            $maximum = (float) $exam->subjects->sum(fn ($subject) => (float) $subject->maximum_marks);
            $percentage = $complete && $maximum > 0 ? round($obtained / $maximum * 100, 2) : null;
            $passed = $complete && $bySubject->every(fn ($subject) => (float) $subject['marks_obtained'] >= (float) $subject['passing_marks']);

            return [
                'enrollment_id' => $enrollment->id,
                'student' => $enrollment->student,
                'section' => $enrollment->section,
                'roll_number' => $enrollment->roll_number,
                'subjects' => $bySubject,
                'total_obtained' => $complete ? $obtained : null,
                'total_maximum' => $maximum,
                'percentage' => $percentage,
                'grade' => $percentage === null ? null : $this->grade($percentage, $exam->grading_scale),
                'result' => ! $complete ? 'PENDING' : ($passed ? 'PASS' : 'NEEDS IMPROVEMENT'),
                'results_status' => $exam->results_status,
            ];
        });

        return response()->json([
            'exam' => $exam,
            'cards' => $cards,
        ]);
    }

    public function homework(Request $request): JsonResponse
    {
        $query = HomeworkAssignment::query()->with([
            'academicSession:id,name', 'schoolClass:id,name', 'section:id,name',
            'subject:id,name,code', 'employee:id,first_name,last_name',
        ])->orderByDesc('assigned_on');
        $this->applyTeachingScope($query, $request->user(), 'homework_assignments');

        foreach (['academic_session_id', 'school_class_id', 'section_id', 'status'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        return response()->json(['data' => $query->paginate(max(1, min($request->integer('per_page', 50), 100)))]);
    }

    public function createHomework(Request $request): JsonResponse
    {
        $data = $request->validate([
            'academic_session_id' => ['required', 'integer', 'exists:academic_sessions,id'],
            'school_class_id' => ['required', 'integer', 'exists:school_classes,id'],
            'section_id' => ['nullable', 'integer', 'exists:sections,id'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'title' => ['required', 'string', 'max:180'],
            'description' => ['required', 'string', 'max:10000'],
            'assigned_on' => ['required', 'date'],
            'due_on' => ['required', 'date', 'after_or_equal:assigned_on'],
        ]);
        $this->validateClassSession($data['academic_session_id'], $data['school_class_id']);
        $this->validateSection($data['section_id'] ?? null, $data['school_class_id']);
        $this->validateSubjectSession($data['subject_id'], $data['academic_session_id'], $data['school_class_id']);
        if ($this->isTeacher($request->user())) {
            $this->ensureTeachingAssignment($request->user(), $data['academic_session_id'], $data['school_class_id'], $data['subject_id'], $data['section_id'] ?? null);
        }

        $homework = HomeworkAssignment::query()->create($data + [
            'employee_id' => Employee::query()->where('user_id', $request->user()->id)->value('id'),
            'created_by' => $request->user()->id,
        ]);
        AuditLog::record('homework.created', $homework);

        return response()->json(['data' => $homework->load(['academicSession:id,name', 'schoolClass:id,name', 'section:id,name', 'subject:id,name', 'employee:id,first_name,last_name'])], 201);
    }

    public function deleteHomework(HomeworkAssignment $homework): JsonResponse
    {
        $homework->delete();
        AuditLog::record('homework.deleted', $homework);

        return response()->json(status: 204);
    }

    public function timetable(Request $request): JsonResponse
    {
        $query = TimetableSlot::query()->with([
            'academicSession:id,name', 'schoolClass:id,name', 'section:id,name',
            'subject:id,name,code', 'employee:id,employee_id,first_name,last_name',
        ])->orderBy('weekday')->orderBy('period_number');
        $this->applyTeachingScope($query, $request->user(), 'timetable_slots');
        foreach (['academic_session_id', 'school_class_id', 'section_id', 'weekday'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        return response()->json(['data' => $query->get()]);
    }

    public function createTimetableSlot(Request $request): JsonResponse
    {
        $data = $request->validate([
            'academic_session_id' => ['required', 'integer', 'exists:academic_sessions,id'],
            'school_class_id' => ['required', 'integer', 'exists:school_classes,id'],
            'section_id' => ['required', 'integer', 'exists:sections,id'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'weekday' => ['required', 'integer', 'between:1,7'],
            'period_number' => ['required', 'integer', 'between:1,20'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
            'room_number' => ['nullable', 'string', 'max:50'],
        ]);
        $this->validateClassSession($data['academic_session_id'], $data['school_class_id']);
        $this->validateSection($data['section_id'], $data['school_class_id']);
        $this->validateSubjectSession($data['subject_id'], $data['academic_session_id'], $data['school_class_id']);
        $employee = Employee::query()->findOrFail($data['employee_id']);
        if (! $employee->is_teaching_staff || $employee->status !== 'active') {
            throw ValidationException::withMessages(['employee_id' => ['Choose an active teaching employee.']]);
        }
        $hasAssignment = TeacherAssignment::query()
            ->where('employee_id', $employee->id)
            ->where('academic_session_id', $data['academic_session_id'])
            ->where('school_class_id', $data['school_class_id'])
            ->where('subject_id', $data['subject_id'])
            ->where('status', 'active')
            ->where(fn ($scope) => $scope->whereNull('section_id')->orWhere('section_id', $data['section_id']))
            ->exists();
        if (! $hasAssignment) {
            throw ValidationException::withMessages(['employee_id' => ['The teacher must have an active assignment for this subject and section.']]);
        }
        $periodConflict = TimetableSlot::query()
            ->where('academic_session_id', $data['academic_session_id'])
            ->where('weekday', $data['weekday'])
            ->where('section_id', $data['section_id'])
            ->where('period_number', $data['period_number'])
            ->exists();
        if ($periodConflict) {
            throw ValidationException::withMessages(['period_number' => ['This section already has a slot for this period on the selected weekday.']]);
        }
        $conflict = TimetableSlot::query()
            ->where('academic_session_id', $data['academic_session_id'])
            ->where('weekday', $data['weekday'])
            ->where(fn ($query) => $query
                ->where('section_id', $data['section_id'])
                ->orWhere('employee_id', $data['employee_id']))
            ->where('starts_at', '<', $data['ends_at'])
            ->where('ends_at', '>', $data['starts_at'])
            ->exists();
        if ($conflict) {
            throw ValidationException::withMessages(['starts_at' => ['This time overlaps an existing class or teacher assignment.']]);
        }

        $slot = TimetableSlot::query()->create($data);
        AuditLog::record('timetable_slot.created', $slot);

        return response()->json(['data' => $slot->load(['academicSession:id,name', 'schoolClass:id,name', 'section:id,name', 'subject:id,name', 'employee:id,employee_id,first_name,last_name'])], 201);
    }

    public function deleteTimetableSlot(TimetableSlot $slot): JsonResponse
    {
        $slot->delete();
        AuditLog::record('timetable_slot.deleted', $slot);

        return response()->json(status: 204);
    }

    public function notices(Request $request): JsonResponse
    {
        $query = SchoolNotice::query()->with('creator:id,name')->latest();
        $user = $request->user();
        if ($this->isTeacher($user)) {
            $query->where(function ($visible) use ($user) {
                $visible->where('created_by', $user->id)
                    ->orWhere(function ($published) {
                        $published->where('status', 'published')
                            ->whereIn('audience', ['all', 'staff'])
                            ->where(fn ($time) => $time->whereNull('publish_at')->orWhere('publish_at', '<=', now()))
                            ->where(fn ($time) => $time->whereNull('expires_at')->orWhere('expires_at', '>', now()));
                    });
            });
        } elseif ($user->roles()->whereIn('name', ['student', 'parent'])->exists()) {
            $audience = $user->roles()->where('name', 'student')->exists() ? 'students' : 'parents';
            $query->where('status', 'published')
                ->whereIn('audience', ['all', $audience])
                ->where(fn ($time) => $time->whereNull('publish_at')->orWhere('publish_at', '<=', now()))
                ->where(fn ($time) => $time->whereNull('expires_at')->orWhere('expires_at', '>', now()));
        }

        return response()->json(['data' => $query->paginate(max(1, min($request->integer('per_page', 50), 100)))]);
    }

    public function createNotice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'body' => ['required', 'string', 'max:20000'],
            'audience' => ['required', Rule::in(['all', 'staff', 'students', 'parents'])],
            'publish_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:publish_at'],
        ]);
        $canPublish = $request->user()->hasPermission('notices.publish');
        $notice = SchoolNotice::query()->create($data + [
            'status' => $canPublish ? 'published' : 'draft',
            'created_by' => $request->user()->id,
        ]);
        AuditLog::record('notice.created', $notice, ['status' => $notice->status, 'audience' => $notice->audience]);

        return response()->json(['data' => $notice->load('creator:id,name')], 201);
    }

    public function publishNotice(SchoolNotice $notice): JsonResponse
    {
        $notice->update(['status' => 'published']);
        AuditLog::record('notice.published', $notice);

        return response()->json(['data' => $notice->refresh()->load('creator:id,name')]);
    }

    public function deleteNotice(SchoolNotice $notice): JsonResponse
    {
        $notice->delete();
        AuditLog::record('notice.deleted', $notice);

        return response()->json(status: 204);
    }

    private function loadExam(ExamSchedule $exam): ExamSchedule
    {
        return $exam->load([
            'academicSession:id,name',
            'schoolClass:id,name',
            'subjects' => fn ($query) => $query->with('subject:id,name,code')->orderBy('exam_date')->orderBy('starts_at'),
        ]);
    }

    private function ensureExamVisible(User $user, ExamSchedule $exam): void
    {
        if (! $this->isTeacher($user)) {
            return;
        }
        $employeeId = $this->employeeId($user);
        $allowed = $employeeId && TeacherAssignment::query()
            ->where('employee_id', $employeeId)
            ->where('academic_session_id', $exam->academic_session_id)
            ->where('school_class_id', $exam->school_class_id)
            ->where('status', 'active')
            ->exists();
        abort_unless($allowed && $exam->status === 'published', 404);
    }

    private function validateClassSession(int $sessionId, int $classId): void
    {
        if (! SchoolClass::query()->whereKey($classId)->where('academic_session_id', $sessionId)->exists()) {
            throw ValidationException::withMessages(['school_class_id' => ['The selected class does not belong to this academic session.']]);
        }
    }

    private function validateSection(?int $sectionId, int $classId): void
    {
        if ($sectionId && ! Section::query()->whereKey($sectionId)->where('school_class_id', $classId)->exists()) {
            throw ValidationException::withMessages(['section_id' => ['The selected section does not belong to this class.']]);
        }
    }

    private function validateSubjectSession(int $subjectId, int $sessionId, int $classId): void
    {
        if (! Subject::query()->whereKey($subjectId)->where('academic_session_id', $sessionId)
            ->whereHas('classes', fn ($query) => $query->whereKey($classId))->exists()) {
            throw ValidationException::withMessages(['subject_id' => ['The selected subject is not assigned to this class and academic session.']]);
        }
    }

    private function ensureTeachingAssignment(
        User $user,
        int $sessionId,
        int $classId,
        int $subjectId,
        ?int $sectionId = null,
        ?int $employeeId = null,
    ): void {
        if (! $this->isTeacher($user)) {
            return;
        }
        $employeeId ??= $this->employeeId($user);
        $allowed = $employeeId && TeacherAssignment::query()
            ->where('employee_id', $employeeId)
            ->where('academic_session_id', $sessionId)
            ->where('school_class_id', $classId)
            ->where('subject_id', $subjectId)
            ->where('status', 'active')
            ->where(fn ($scope) => $scope->whereNull('section_id')->orWhere('section_id', $sectionId))
            ->exists();
        if (! $allowed) {
            throw ValidationException::withMessages(['subject_id' => ['You are not assigned to teach this subject in the selected class or section.']]);
        }
    }

    private function applyTeachingScope(Builder $query, User $user, string $table): void
    {
        if (! $this->isTeacher($user)) {
            return;
        }
        $employeeId = $this->employeeId($user);
        $query->where($table.'.employee_id', $employeeId ?? 0);
    }

    private function isTeacher(User $user): bool
    {
        return ! $user->isSuperAdmin() && $user->roles()->where('name', 'teacher')->exists();
    }

    private function employeeId(User $user): ?int
    {
        $id = Employee::query()->where('user_id', $user->id)->value('id');

        return $id === null ? null : (int) $id;
    }

    private function defaultGradingScale(): array
    {
        return [
            ['label' => 'A+', 'min_percentage' => 90],
            ['label' => 'A', 'min_percentage' => 80],
            ['label' => 'B', 'min_percentage' => 70],
            ['label' => 'C', 'min_percentage' => 60],
            ['label' => 'D', 'min_percentage' => 50],
            ['label' => 'F', 'min_percentage' => 0],
        ];
    }

    private function grade(float $percentage, ?array $gradingScale): string
    {
        foreach ($gradingScale ?: $this->defaultGradingScale() as $grade) {
            if ($percentage >= (float) $grade['min_percentage']) {
                return $grade['label'];
            }
        }

        return 'F';
    }

    private function examTimesOverlap(array $left, array $right): bool
    {
        [$leftStartHour, $leftStartMinute] = array_map('intval', explode(':', $left['starts_at']));
        [$rightStartHour, $rightStartMinute] = array_map('intval', explode(':', $right['starts_at']));
        $leftStart = $leftStartHour * 60 + $leftStartMinute;
        $rightStart = $rightStartHour * 60 + $rightStartMinute;

        return $leftStart < $rightStart + (int) $right['duration_minutes']
            && $rightStart < $leftStart + (int) $left['duration_minutes'];
    }
}
