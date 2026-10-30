<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Guardian;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\StudentEnrollmentHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class StudentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = $this->visibleStudents($request)
            ->with([
                'guardians',
                'enrollments' => fn ($query) => $query->where('status', 'active')
                    ->with(['academicSession:id,name', 'schoolClass:id,name', 'section:id,name']),
            ])
            ->latest('id');

        if ($request->filled('search')) {
            $term = '%'.mb_strtolower(trim($request->string('search')->toString())).'%';
            $query->where(function (Builder $query) use ($term) {
                $query->whereRaw('LOWER(first_name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(last_name, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(admission_number) LIKE ?', [$term]);
            });
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }
        if ($request->filled('academic_session_id')) {
            $query->whereHas('enrollments', fn ($enrollments) => $enrollments
                ->where('academic_session_id', $request->integer('academic_session_id'))
                ->where('status', 'active'));
        }

        return response()->json($query->paginate(max(1, min($request->integer('per_page', 20), 100))));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedProfile($request, creating: true);
        $enrollment = $request->validate([
            'academic_session_id' => ['required', 'integer', 'exists:academic_sessions,id'],
            'school_class_id' => ['required', 'integer', 'exists:school_classes,id'],
            'section_id' => ['nullable', 'integer', 'exists:sections,id'],
            'roll_number' => ['nullable', 'string', 'max:32'],
        ]);
        $guardians = $this->validatedGuardians($request);
        $target = $this->validateEnrollmentTarget($enrollment);
        $this->assertEnrollmentCapacity($target['class'], $target['section']);
        $this->assertRollAvailable($enrollment['section_id'] ?? null, $enrollment['roll_number'] ?? null);
        $possibleDuplicates = $this->findPossibleDuplicates($data);

        $student = DB::transaction(function () use ($data, $enrollment, $guardians, $request) {
            $student = Student::query()->create($data + ['created_by' => $request->user()->id]);
            $student->forceFill(['admission_number' => 'ADM-'.str_pad((string) $student->id, 6, '0', STR_PAD_LEFT)])->save();
            $this->syncGuardians($student, $guardians);
            $newEnrollment = $student->enrollments()->create([
                ...$enrollment,
                'enrolled_on' => $data['admission_date'],
                'status' => 'active',
            ]);
            $this->recordHistory($student, null, $newEnrollment, 'admitted', null, $request);

            return $student;
        });

        AuditLog::record('student.admitted', $student, [
            'admission_number' => $student->admission_number,
            'academic_session_id' => $enrollment['academic_session_id'],
            'school_class_id' => $enrollment['school_class_id'],
        ]);

        return response()->json([
            'data' => $this->details($student),
            'possible_duplicates' => $possibleDuplicates,
        ], 201);
    }

    public function show(Request $request, int $student): JsonResponse
    {
        $record = $this->visibleStudents($request)->findOrFail($student);

        return response()->json(['data' => $this->details($record)]);
    }

    public function update(Request $request, int $student): JsonResponse
    {
        $record = $this->visibleStudents($request)->findOrFail($student);
        $data = $this->validatedProfile($request, creating: false);
        $record->update($data);
        AuditLog::record('student.profile_updated', $record, ['fields' => array_keys($data)]);

        return response()->json(['data' => $this->details($record->refresh())]);
    }

    public function updateGuardians(Request $request, int $student): JsonResponse
    {
        $record = $this->visibleStudents($request)->findOrFail($student);
        $guardians = $this->validatedGuardians($request);

        DB::transaction(fn () => $this->syncGuardians($record, $guardians));
        AuditLog::record('student.guardians_updated', $record, ['guardian_count' => count($guardians)]);

        return response()->json(['data' => $this->details($record->refresh())]);
    }

    public function updateMedicalRecord(Request $request, int $student): JsonResponse
    {
        $record = $this->visibleStudents($request)->findOrFail($student);
        $data = $request->validate([
            'medical_notes' => ['nullable', 'string', 'max:10000'],
            'allergies' => ['nullable', 'string', 'max:5000'],
            'emergency_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $record->medicalRecord()->updateOrCreate(
            ['student_id' => $record->id],
            $data + ['updated_by' => $request->user()->id],
        );
        AuditLog::record('student.medical_record_updated', $record, ['fields' => array_keys($data)]);

        return response()->json(['message' => 'Restricted medical record saved.']);
    }

    public function showMedicalRecord(Request $request, int $student): JsonResponse
    {
        $record = $this->visibleStudents($request)->findOrFail($student);

        return response()->json(['data' => $record->medicalRecord()->first()]);
    }

    public function promote(Request $request, int $student): JsonResponse
    {
        $record = $this->visibleStudents($request)->findOrFail($student);
        $data = $request->validate([
            'academic_session_id' => ['required', 'integer', 'exists:academic_sessions,id'],
            'school_class_id' => ['required', 'integer', 'exists:school_classes,id'],
            'section_id' => ['nullable', 'integer', 'exists:sections,id'],
            'roll_number' => ['nullable', 'string', 'max:32'],
            'enrolled_on' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $current = $record->enrollments()->where('status', 'active')->latest('id')->firstOrFail();
        if ($current->academic_session_id === $data['academic_session_id']) {
            throw ValidationException::withMessages([
                'academic_session_id' => ['Promotion must create an enrollment in a different academic session. Use transfer for a class or section change within this session.'],
            ]);
        }

        $target = $this->validateEnrollmentTarget($data);
        $this->assertEnrollmentCapacity($target['class'], $target['section']);
        $this->assertRollAvailable($data['section_id'] ?? null, $data['roll_number'] ?? null);

        $newEnrollment = DB::transaction(function () use ($record, $current, $data, $request) {
            $current->update(['status' => 'completed']);
            $newEnrollment = $record->enrollments()->create([
                ...$data,
                'enrolled_on' => $data['enrolled_on'],
                'status' => 'active',
            ]);
            $this->recordHistory($record, $current, $newEnrollment, 'promoted', $data['reason'] ?? null, $request);

            return $newEnrollment;
        });

        AuditLog::record('student.promoted', $record, [
            'from_academic_session_id' => $current->academic_session_id,
            'to_academic_session_id' => $newEnrollment->academic_session_id,
        ]);

        return response()->json(['data' => $this->details($record->refresh())]);
    }

    public function transfer(Request $request, int $student): JsonResponse
    {
        $record = $this->visibleStudents($request)->findOrFail($student);
        $data = $request->validate([
            'school_class_id' => ['required', 'integer', 'exists:school_classes,id'],
            'section_id' => ['nullable', 'integer', 'exists:sections,id'],
            'roll_number' => ['nullable', 'string', 'max:32'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $current = $record->enrollments()->where('status', 'active')->latest('id')->firstOrFail();
        $data['academic_session_id'] = $current->academic_session_id;
        $target = $this->validateEnrollmentTarget($data);
        $this->assertEnrollmentCapacity($target['class'], $target['section'], $current->id);
        $this->assertRollAvailable($data['section_id'] ?? null, $data['roll_number'] ?? null, $current->id);
        $action = $current->school_class_id === $data['school_class_id'] ? 'section_transferred' : 'class_transferred';

        DB::transaction(function () use ($record, $current, $data, $action, $request) {
            $before = clone $current;
            $current->update([
                'school_class_id' => $data['school_class_id'],
                'section_id' => $data['section_id'] ?? null,
                'roll_number' => $data['roll_number'] ?? null,
            ]);
            $this->recordHistory($record, $before, $current, $action, $data['reason'], $request);
        });

        AuditLog::record('student.'.$action, $record, ['school_class_id' => $data['school_class_id']]);

        return response()->json(['data' => $this->details($record->refresh())]);
    }

    public function destroy(Request $request, int $student): JsonResponse
    {
        $record = $this->visibleStudents($request)->findOrFail($student);
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:1000']])['reason'] ?? null;

        DB::transaction(function () use ($record, $reason, $request) {
            $activeEnrollments = $record->enrollments()->where('status', 'active')->lockForUpdate()->get();
            foreach ($activeEnrollments as $enrollment) {
                $enrollment->update(['status' => 'withdrawn']);
                $this->recordHistory($record, $enrollment, null, 'withdrawn', $reason, $request);
            }
            $record->update(['status' => 'withdrawn']);
        });
        AuditLog::record('student.withdrawn', $record, ['reason_provided' => filled($reason)]);

        return response()->json(['message' => 'Student withdrawn; historical records have been preserved.']);
    }

    public function export(Request $request)
    {
        $students = $this->visibleStudents($request)
            ->with(['enrollments' => fn ($query) => $query->where('status', 'active')->with(['academicSession', 'schoolClass', 'section'])])
            ->orderBy('admission_number')
            ->get();

        AuditLog::record('students.exported', changes: ['record_count' => $students->count()]);

        return response()->streamDownload(function () use ($students) {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['admission_number', 'first_name', 'last_name', 'date_of_birth', 'gender', 'admission_date', 'status', 'academic_session', 'class', 'section', 'roll_number']);
            foreach ($students as $student) {
                $enrollment = $student->enrollments->first();
                fputcsv($output, [
                    $student->admission_number,
                    $student->first_name,
                    $student->last_name,
                    $student->date_of_birth?->toDateString(),
                    $student->gender,
                    $student->admission_date?->toDateString(),
                    $student->status,
                    $enrollment?->academicSession?->name,
                    $enrollment?->schoolClass?->name,
                    $enrollment?->section?->name,
                    $enrollment?->roll_number,
                ]);
            }
            fclose($output);
        }, 'students.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function importTemplate()
    {
        return response()->streamDownload(function () {
            $output = fopen('php://output', 'w');
            fputcsv($output, [
                'first_name',
                'last_name',
                'date_of_birth',
                'gender',
                'admission_date',
                'phone',
                'email',
                'address',
                'previous_school',
                'guardian_first_name',
                'guardian_last_name',
                'guardian_phone',
                'guardian_email',
                'guardian_relationship',
                'roll_number',
            ]);
            fclose($output);
        }, 'student-import-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function import(Request $request): JsonResponse
    {
        $context = $request->validate([
            'academic_session_id' => ['required', 'integer', 'exists:academic_sessions,id'],
            'school_class_id' => ['required', 'integer', 'exists:school_classes,id'],
            'section_id' => ['nullable', 'integer', 'exists:sections,id'],
            'file' => ['required', 'file', 'max:5120', 'mimes:csv,txt'],
        ]);
        $target = $this->validateEnrollmentTarget($context);
        $path = $request->file('file')->getRealPath();
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw ValidationException::withMessages(['file' => ['The uploaded CSV could not be read.']]);
        }

        $created = 0;
        $errors = [];
        try {
            $headers = fgetcsv($handle, null, ',', '"', '\\');
            $headers = is_array($headers)
                ? array_map(fn ($header) => mb_strtolower(trim((string) $header)), $headers)
                : [];
            $requiredHeaders = ['first_name', 'date_of_birth', 'guardian_first_name', 'guardian_phone', 'guardian_relationship'];
            if (array_diff($requiredHeaders, $headers)) {
                throw ValidationException::withMessages([
                    'file' => ['CSV must include: '.implode(', ', $requiredHeaders).'.'],
                ]);
            }

            $rowNumber = 1;
            while (($values = fgetcsv($handle, null, ',', '"', '\\')) !== false) {
                $rowNumber++;
                if (count(array_filter($values, fn ($value) => trim((string) $value) !== '')) === 0) {
                    continue;
                }
                if ($rowNumber > 501) {
                    $errors[] = ['row' => $rowNumber, 'message' => 'Import limit is 500 rows per file.'];
                    break;
                }
                $row = [];
                foreach ($headers as $index => $header) {
                    $value = isset($values[$index]) ? trim((string) $values[$index]) : '';
                    $row[$header] = $value === '' ? null : $value;
                }

                $validator = Validator::make($row, [
                    'first_name' => ['required', 'string', 'max:100'],
                    'last_name' => ['nullable', 'string', 'max:100'],
                    'date_of_birth' => ['required', 'date', 'before_or_equal:today'],
                    'gender' => ['nullable', 'string', 'max:30'],
                    'admission_date' => ['nullable', 'date'],
                    'phone' => ['nullable', 'string', 'max:40'],
                    'email' => ['nullable', 'email', 'max:255'],
                    'address' => ['nullable', 'string', 'max:2000'],
                    'previous_school' => ['nullable', 'string', 'max:255'],
                    'guardian_first_name' => ['required', 'string', 'max:100'],
                    'guardian_last_name' => ['nullable', 'string', 'max:100'],
                    'guardian_phone' => ['required', 'string', 'max:40'],
                    'guardian_email' => ['nullable', 'email', 'max:255'],
                    'guardian_relationship' => ['required', 'string', 'max:50'],
                    'roll_number' => ['nullable', 'string', 'max:32'],
                ]);
                if ($validator->fails()) {
                    $errors[] = ['row' => $rowNumber, 'message' => $validator->errors()->first()];

                    continue;
                }
                if (Student::query()
                    ->whereRaw('LOWER(first_name) = ?', [mb_strtolower($row['first_name'])])
                    ->whereRaw('LOWER(COALESCE(last_name, \'\')) = ?', [mb_strtolower($row['last_name'] ?? '')])
                    ->whereDate('date_of_birth', $row['date_of_birth'])
                    ->exists()) {
                    $errors[] = ['row' => $rowNumber, 'message' => 'A student with the same name and date of birth already exists.'];

                    continue;
                }

                try {
                    $this->assertEnrollmentCapacity($target['class'], $target['section']);
                    $this->assertRollAvailable($context['section_id'] ?? null, ($row['roll_number'] ?? '') ?: null);
                    $studentData = array_intersect_key($row, array_flip([
                        'first_name', 'last_name', 'date_of_birth', 'gender', 'phone', 'email', 'address', 'previous_school',
                    ]));
                    $admissionDate = ($row['admission_date'] ?? '') ?: today()->toDateString();
                    DB::transaction(function () use ($studentData, $admissionDate, $row, $context, $request) {
                        $student = Student::query()->create($studentData + [
                            'admission_date' => $admissionDate,
                            'created_by' => $request->user()->id,
                        ]);
                        $student->forceFill(['admission_number' => 'ADM-'.str_pad((string) $student->id, 6, '0', STR_PAD_LEFT)])->save();
                        $this->syncGuardians($student, [[
                            'first_name' => $row['guardian_first_name'],
                            'last_name' => $row['guardian_last_name'] ?? null,
                            'phone' => $row['guardian_phone'],
                            'email' => $row['guardian_email'] ?? null,
                            'relationship' => $row['guardian_relationship'],
                            'is_primary' => true,
                        ]]);
                        $enrollment = $student->enrollments()->create([
                            'academic_session_id' => $context['academic_session_id'],
                            'school_class_id' => $context['school_class_id'],
                            'section_id' => $context['section_id'] ?? null,
                            'roll_number' => ($row['roll_number'] ?? '') ?: null,
                            'enrolled_on' => $admissionDate,
                            'status' => 'active',
                        ]);
                        $this->recordHistory($student, null, $enrollment, 'admitted', 'CSV import', $request);
                    });
                    $created++;
                } catch (ValidationException $exception) {
                    $errors[] = ['row' => $rowNumber, 'message' => $exception->validator->errors()->first()];
                }
            }
        } finally {
            fclose($handle);
        }

        AuditLog::record('students.csv_imported', changes: [
            'created_count' => $created,
            'error_count' => count($errors),
        ]);

        return response()->json([
            'created_count' => $created,
            'error_count' => count($errors),
            'errors' => $errors,
        ]);
    }

    private function visibleStudents(Request $request): Builder
    {
        $query = Student::query();
        $user = $request->user();
        if (! $user->isSuperAdmin() && $user->roles()->where('name', 'teacher')->exists()) {
            $employeeId = Employee::query()->where('user_id', $user->id)->value('id');
            if (! $employeeId) {
                return $query->whereRaw('1 = 0');
            }
            $query->whereHas('enrollments', fn ($enrollments) => $enrollments
                ->where('status', 'active')
                ->whereHas('schoolClass.teacherAssignments', fn ($assignments) => $assignments
                    ->where('employee_id', $employeeId)
                    ->where('teacher_assignments.status', 'active')
                    ->where(fn ($scope) => $scope
                        ->whereNull('section_id')
                        ->orWhereColumn('teacher_assignments.section_id', 'student_enrollments.section_id'))));
        }

        return $query;
    }

    private function details(Student $student): array
    {
        return $student->load([
            'guardians',
            'enrollments' => fn ($query) => $query->with(['academicSession:id,name', 'schoolClass:id,name', 'section:id,name'])->latest('id'),
            'enrollmentHistory' => fn ($query) => $query->with('actor:id,name')->limit(100),
            'documents:id,student_id,category,original_name,mime_type,size_bytes,created_at',
        ])->toArray();
    }

    private function validatedProfile(Request $request, bool $creating): array
    {
        $rules = [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'gender' => ['nullable', 'string', 'max:30'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'previous_school' => ['nullable', 'string', 'max:255'],
            'transfer_certificate_number' => ['nullable', 'string', 'max:100'],
        ];
        if ($creating) {
            $rules['admission_date'] = ['sometimes', 'date'];
        }

        return $request->validate($rules) + ($creating ? [
            'admission_date' => $request->input('admission_date', today()->toDateString()),
        ] : []);
    }

    private function validatedGuardians(Request $request): array
    {
        $data = $request->validate([
            'guardians' => ['required', 'array', 'min:1', 'max:4'],
            'guardians.*.first_name' => ['required', 'string', 'max:100'],
            'guardians.*.last_name' => ['nullable', 'string', 'max:100'],
            'guardians.*.phone' => ['required', 'string', 'max:40'],
            'guardians.*.email' => ['nullable', 'email', 'max:255'],
            'guardians.*.occupation' => ['nullable', 'string', 'max:150'],
            'guardians.*.address' => ['nullable', 'string', 'max:2000'],
            'guardians.*.relationship' => ['required', 'string', 'max:50'],
            'guardians.*.is_primary' => ['sometimes', 'boolean'],
        ]);
        $guardians = $data['guardians'];
        if (collect($guardians)->where('is_primary', true)->count() > 1) {
            throw ValidationException::withMessages(['guardians' => ['Only one guardian can be marked as primary.']]);
        }
        if (! collect($guardians)->contains(fn ($guardian) => (bool) ($guardian['is_primary'] ?? false))) {
            $guardians[0]['is_primary'] = true;
        }

        return $guardians;
    }

    private function syncGuardians(Student $student, array $guardians): void
    {
        $sync = [];
        foreach ($guardians as $data) {
            $guardian = Guardian::query()
                ->where('phone', $data['phone'])
                ->whereRaw('LOWER(first_name) = ?', [mb_strtolower($data['first_name'])])
                ->whereRaw('LOWER(COALESCE(last_name, \'\')) = ?', [mb_strtolower($data['last_name'] ?? '')])
                ->first();
            if (! $guardian) {
                $guardian = Guardian::query()->create(collect($data)->only([
                    'first_name', 'last_name', 'phone', 'email', 'occupation', 'address',
                ])->all());
            }
            $sync[$guardian->id] = [
                'relationship' => $data['relationship'],
                'is_primary' => (bool) ($data['is_primary'] ?? false),
            ];
        }
        $student->guardians()->sync($sync);
    }

    private function validateEnrollmentTarget(array $data): array
    {
        $session = AcademicSession::query()->findOrFail($data['academic_session_id']);
        $schoolClass = SchoolClass::query()->findOrFail($data['school_class_id']);
        if ($schoolClass->academic_session_id !== $session->id) {
            throw ValidationException::withMessages(['school_class_id' => ['The selected class does not belong to the selected academic session.']]);
        }
        $section = null;
        if (! empty($data['section_id'])) {
            $section = Section::query()->findOrFail($data['section_id']);
            if ($section->school_class_id !== $schoolClass->id) {
                throw ValidationException::withMessages(['section_id' => ['The selected section does not belong to the selected class.']]);
            }
        }

        return ['session' => $session, 'class' => $schoolClass, 'section' => $section];
    }

    private function assertEnrollmentCapacity(SchoolClass $schoolClass, ?Section $section, ?int $exceptEnrollmentId = null): void
    {
        $classCount = StudentEnrollment::query()
            ->where('school_class_id', $schoolClass->id)
            ->where('status', 'active')
            ->when($exceptEnrollmentId, fn ($query) => $query->whereKeyNot($exceptEnrollmentId))
            ->count();
        if ($schoolClass->capacity && $classCount >= $schoolClass->capacity) {
            throw ValidationException::withMessages(['school_class_id' => ['The selected class has reached its configured capacity.']]);
        }

        if ($section?->capacity) {
            $sectionCount = StudentEnrollment::query()
                ->where('section_id', $section->id)
                ->where('status', 'active')
                ->when($exceptEnrollmentId, fn ($query) => $query->whereKeyNot($exceptEnrollmentId))
                ->count();
            if ($sectionCount >= $section->capacity) {
                throw ValidationException::withMessages(['section_id' => ['The selected section has reached its configured capacity.']]);
            }
        }
    }

    private function assertRollAvailable(?int $sectionId, ?string $rollNumber, ?int $exceptEnrollmentId = null): void
    {
        if (! $sectionId || ! $rollNumber) {
            return;
        }
        $query = StudentEnrollment::query()
            ->where('section_id', $sectionId)
            ->where('roll_number', $rollNumber)
            ->where('status', 'active')
            ->when($exceptEnrollmentId, fn ($query) => $query->whereKeyNot($exceptEnrollmentId));
        if ($query->exists()) {
            throw ValidationException::withMessages(['roll_number' => ['That roll number is already active in the selected section.']]);
        }
    }

    private function recordHistory(
        Student $student,
        ?StudentEnrollment $from,
        ?StudentEnrollment $to,
        string $action,
        ?string $reason,
        Request $request,
    ): void {
        StudentEnrollmentHistory::query()->create([
            'student_id' => $student->id,
            'from_enrollment_id' => $from?->id,
            'to_enrollment_id' => $to?->id,
            'from_class_id' => $from?->school_class_id,
            'from_section_id' => $from?->section_id,
            'from_roll_number' => $from?->roll_number,
            'to_class_id' => $to?->school_class_id,
            'to_section_id' => $to?->section_id,
            'to_roll_number' => $to?->roll_number,
            'action' => $action,
            'reason' => $reason,
            'performed_by' => $request->user()->id,
        ]);
    }

    private function findPossibleDuplicates(array $data): array
    {
        if (empty($data['date_of_birth'])) {
            return [];
        }

        return Student::query()
            ->whereRaw('LOWER(first_name) = ?', [mb_strtolower($data['first_name'])])
            ->whereRaw('LOWER(COALESCE(last_name, \'\')) = ?', [mb_strtolower($data['last_name'] ?? '')])
            ->whereDate('date_of_birth', $data['date_of_birth'])
            ->limit(5)
            ->get(['id', 'admission_number', 'first_name', 'last_name'])
            ->map(fn (Student $student) => [
                'id' => $student->id,
                'admission_number' => $student->admission_number,
                'name' => trim($student->first_name.' '.$student->last_name),
            ])
            ->all();
    }
}
