<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\AuditLog;
use App\Models\StudentEnrollment;
use App\Models\TeacherAssignment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AttendanceController extends Controller
{
    public function roster(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'academic_session_id' => ['required', 'integer', 'exists:academic_sessions,id'],
            'school_class_id' => ['required', 'integer', 'exists:school_classes,id'],
            'section_id' => ['required', 'integer', 'exists:sections,id'],
            'attendance_date' => ['required', 'date'],
        ]);
        $this->validateTarget($filters['academic_session_id'], $filters['school_class_id'], $filters['section_id']);
        $this->ensureAssigned($request->user(), $filters['academic_session_id'], $filters['school_class_id'], $filters['section_id']);

        $enrollments = StudentEnrollment::query()
            ->where('academic_session_id', $filters['academic_session_id'])
            ->where('school_class_id', $filters['school_class_id'])
            ->where('section_id', $filters['section_id'])
            ->where('status', 'active')
            ->with('student:id,admission_number,first_name,last_name')
            ->orderBy('roll_number')
            ->get();
        $records = DB::table('student_attendance')
            ->whereIn('student_enrollment_id', $enrollments->pluck('id'))
            ->where('attendance_date', $filters['attendance_date'])
            ->get()
            ->keyBy('student_enrollment_id');

        return response()->json([
            'data' => $enrollments->map(fn (StudentEnrollment $enrollment) => [
                'enrollment_id' => $enrollment->id,
                'roll_number' => $enrollment->roll_number,
                'student' => $enrollment->student,
                'status' => $records->get($enrollment->id)?->status,
                'remarks' => $records->get($enrollment->id)?->remarks,
            ]),
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'academic_session_id' => ['required', 'integer', 'exists:academic_sessions,id'],
            'school_class_id' => ['required', 'integer', 'exists:school_classes,id'],
            'section_id' => ['required', 'integer', 'exists:sections,id'],
            'attendance_date' => ['required', 'date'],
            'entries' => ['required', 'array', 'min:1', 'max:100'],
            'entries.*.student_enrollment_id' => ['required', 'integer', 'distinct', 'exists:student_enrollments,id'],
            'entries.*.status' => ['required', Rule::in(['present', 'absent', 'late', 'excused'])],
            'entries.*.remarks' => ['nullable', 'string', 'max:500'],
        ]);
        $this->validateTarget($data['academic_session_id'], $data['school_class_id'], $data['section_id']);
        $this->ensureAssigned($request->user(), $data['academic_session_id'], $data['school_class_id'], $data['section_id']);
        $enrollmentIds = collect($data['entries'])->pluck('student_enrollment_id');
        $validCount = StudentEnrollment::query()
            ->whereIn('id', $enrollmentIds)
            ->where('academic_session_id', $data['academic_session_id'])
            ->where('school_class_id', $data['school_class_id'])
            ->where('section_id', $data['section_id'])
            ->where('status', 'active')
            ->count();
        if ($validCount !== $enrollmentIds->count()) {
            throw ValidationException::withMessages(['entries' => ['Every student must be actively enrolled in the selected session, class and section.']]);
        }

        DB::transaction(function () use ($data, $request) {
            foreach ($data['entries'] as $entry) {
                AttendanceRecord::query()->updateOrCreate(
                    [
                        'student_enrollment_id' => $entry['student_enrollment_id'],
                        'attendance_date' => $data['attendance_date'],
                    ],
                    [
                        'status' => $entry['status'],
                        'remarks' => $entry['remarks'] ?? null,
                        'recorded_by' => $request->user()->id,
                    ],
                );
            }
        });
        AuditLog::record('attendance.saved', null, [
            'academic_session_id' => $data['academic_session_id'],
            'school_class_id' => $data['school_class_id'],
            'section_id' => $data['section_id'],
            'attendance_date' => $data['attendance_date'],
            'entry_count' => count($data['entries']),
        ]);

        return response()->json(['message' => 'Attendance saved.', 'saved_count' => count($data['entries'])]);
    }

    private function validateTarget(int $sessionId, int $classId, int $sectionId): void
    {
        if (! SchoolClass::query()->whereKey($classId)->where('academic_session_id', $sessionId)->exists()) {
            throw ValidationException::withMessages(['school_class_id' => ['The selected class does not belong to this academic session.']]);
        }
        if (! Section::query()->whereKey($sectionId)->where('school_class_id', $classId)->exists()) {
            throw ValidationException::withMessages(['section_id' => ['The selected section does not belong to this class.']]);
        }
    }

    private function ensureAssigned(User $user, int $sessionId, int $classId, int $sectionId): void
    {
        if (! $user->roles()->where('name', 'teacher')->exists()) {
            return;
        }
        $employeeId = Employee::query()->where('user_id', $user->id)->value('id');
        $assigned = $employeeId && TeacherAssignment::query()
            ->where('employee_id', $employeeId)
            ->where('academic_session_id', $sessionId)
            ->where('school_class_id', $classId)
            ->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('section_id')->orWhere('section_id', $sectionId))
            ->exists();
        abort_unless($assigned, 403, 'You are not assigned to this class or section.');
    }
}
