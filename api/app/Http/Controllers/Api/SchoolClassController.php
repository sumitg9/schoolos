<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SchoolClass;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SchoolClassController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => SchoolClass::query()
                ->with(['academicSession:id,name', 'sections', 'subjects:id,name,code,type'])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $schoolClass = SchoolClass::create($data);
        AuditLog::record('school_class.created', $schoolClass);

        return response()->json(['data' => $schoolClass->load(['academicSession:id,name', 'sections', 'subjects:id,name,code,type'])], 201);
    }

    public function update(Request $request, SchoolClass $schoolClass): JsonResponse
    {
        $data = $this->validated($request, $schoolClass);
        $schoolClass->update($data);
        AuditLog::record('school_class.updated', $schoolClass, $data);

        return response()->json(['data' => $schoolClass->refresh()->load(['academicSession:id,name', 'sections', 'subjects:id,name,code,type'])]);
    }

    public function destroy(SchoolClass $schoolClass): JsonResponse
    {
        $schoolClass->delete();
        AuditLog::record('school_class.deleted', $schoolClass);

        return response()->json(status: 204);
    }

    private function validated(Request $request, ?SchoolClass $schoolClass = null): array
    {
        return $request->validate([
            'academic_session_id' => ['required', 'integer', 'exists:academic_sessions,id'],
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('school_classes', 'name')
                    ->where('academic_session_id', $request->input('academic_session_id'))
                    ->ignore($schoolClass?->id),
            ],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:32767'],
            'room_number' => ['nullable', 'string', 'max:100'],
        ]);
    }
}
