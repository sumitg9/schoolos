<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SchoolClass;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SubjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Subject::query()->with(['academicSession:id,name', 'classes:id,name,academic_session_id'])->orderBy('name');

        if ($request->filled('academic_session_id')) {
            $query->where('academic_session_id', $request->integer('academic_session_id'));
        }

        return response()->json(['data' => $query->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $classIds = $data['class_ids'];
        unset($data['class_ids']);
        $subject = Subject::create($data);
        $subject->classes()->sync($classIds);
        AuditLog::record('subject.created', $subject);

        return response()->json(['data' => $subject->load(['academicSession:id,name', 'classes:id,name,academic_session_id'])], 201);
    }

    public function update(Request $request, Subject $subject): JsonResponse
    {
        $data = $this->validated($request, $subject);
        $classIds = $data['class_ids'];
        unset($data['class_ids']);
        $subject->update($data);
        $subject->classes()->sync($classIds);
        AuditLog::record('subject.updated', $subject, $data);

        return response()->json(['data' => $subject->refresh()->load(['academicSession:id,name', 'classes:id,name,academic_session_id'])]);
    }

    public function destroy(Subject $subject): JsonResponse
    {
        $subject->delete();
        AuditLog::record('subject.deleted', $subject);

        return response()->json(status: 204);
    }

    private function validated(Request $request, ?Subject $subject = null): array
    {
        $sessionId = $request->input('academic_session_id', $subject?->academic_session_id);

        $data = $request->validate([
            'academic_session_id' => ['required', 'integer', 'exists:academic_sessions,id'],
            'name' => [
                'required',
                'string',
                'max:150',
                Rule::unique('subjects', 'name')->where('academic_session_id', $sessionId)->ignore($subject?->id),
            ],
            'code' => [
                'nullable',
                'string',
                'max:32',
                Rule::unique('subjects', 'code')->where('academic_session_id', $sessionId)->ignore($subject?->id),
            ],
            'type' => ['required', Rule::in(['compulsory', 'optional', 'elective'])],
            'class_ids' => ['required', 'array', 'min:1'],
            'class_ids.*' => ['required', 'integer', 'distinct', 'exists:school_classes,id'],
        ]);
        $classIds = $data['class_ids'];
        $classes = SchoolClass::query()
            ->where('academic_session_id', $data['academic_session_id'])
            ->whereIn('id', $classIds)
            ->count();
        if ($classes !== count($classIds)) {
            throw ValidationException::withMessages([
                'class_ids' => ['Every selected class must belong to the subject academic session and current school.'],
            ]);
        }

        return $data;
    }
}
