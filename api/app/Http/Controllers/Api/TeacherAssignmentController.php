<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Subject;
use App\Models\TeacherAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TeacherAssignmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = TeacherAssignment::query()->where('status', 'active')->with([
            'employee:id,employee_id,first_name,last_name,designation',
            'academicSession:id,name',
            'schoolClass:id,name',
            'section:id,name',
            'subject:id,name,code',
        ])->latest();

        if ($request->filled('academic_session_id')) {
            $query->where('academic_session_id', $request->integer('academic_session_id'));
        }
        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->integer('employee_id'));
        }

        return response()->json(['data' => $query->paginate(max(1, min($request->integer('per_page', 50), 100)))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'academic_session_id' => ['required', 'integer', 'exists:academic_sessions,id'],
            'school_class_id' => ['required', 'integer', 'exists:school_classes,id'],
            'section_id' => ['nullable', 'integer', 'exists:sections,id'],
            'subject_id' => ['nullable', 'integer', 'exists:subjects,id'],
            'is_class_teacher' => ['sometimes', 'boolean'],
        ]);

        $employee = Employee::query()->findOrFail($data['employee_id']);
        $session = AcademicSession::query()->findOrFail($data['academic_session_id']);
        $schoolClass = SchoolClass::query()->findOrFail($data['school_class_id']);
        if (! $employee->is_teaching_staff || $employee->status !== 'active') {
            throw ValidationException::withMessages(['employee_id' => ['Assign only active teaching employees.']]);
        }
        if ($schoolClass->academic_session_id !== $session->id) {
            throw ValidationException::withMessages(['school_class_id' => ['The selected class does not belong to the selected academic session.']]);
        }
        if (! empty($data['section_id']) && Section::query()->whereKey($data['section_id'])->value('school_class_id') !== $schoolClass->id) {
            throw ValidationException::withMessages(['section_id' => ['The selected section does not belong to the selected class.']]);
        }
        if (! empty($data['subject_id']) && ! Subject::query()->whereKey($data['subject_id'])
            ->where('academic_session_id', $session->id)
            ->whereHas('classes', fn ($query) => $query->whereKey($schoolClass->id))->exists()) {
            throw ValidationException::withMessages(['subject_id' => ['The selected subject is not assigned to the selected class and academic session.']]);
        }

        $duplicate = TeacherAssignment::query()
            ->where('employee_id', $employee->id)
            ->where('academic_session_id', $session->id)
            ->where('school_class_id', $schoolClass->id)
            ->where('section_id', $data['section_id'] ?? null)
            ->where('subject_id', $data['subject_id'] ?? null)
            ->where('status', 'active')
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['employee_id' => ['This teacher assignment already exists.']]);
        }
        if (($data['is_class_teacher'] ?? false) && TeacherAssignment::query()
            ->where('academic_session_id', $session->id)
            ->where('school_class_id', $schoolClass->id)
            ->where('section_id', $data['section_id'] ?? null)
            ->where('is_class_teacher', true)
            ->where('status', 'active')
            ->exists()) {
            throw ValidationException::withMessages(['is_class_teacher' => ['A class teacher is already assigned to this class and section.']]);
        }

        $assignment = TeacherAssignment::query()->create($data + ['is_class_teacher' => false]);
        AuditLog::record('teacher_assignment.created', $assignment, [
            'employee_id' => $employee->id,
            'school_class_id' => $schoolClass->id,
            'subject_id' => $data['subject_id'] ?? null,
        ]);

        return response()->json([
            'data' => $assignment->load([
                'employee:id,employee_id,first_name,last_name,designation',
                'academicSession:id,name',
                'schoolClass:id,name',
                'section:id,name',
                'subject:id,name,code',
            ]),
        ], 201);
    }

    public function destroy(TeacherAssignment $teacherAssignment): JsonResponse
    {
        $teacherAssignment->update(['status' => 'inactive']);
        AuditLog::record('teacher_assignment.deleted', $teacherAssignment);

        return response()->json(status: 204);
    }
}
