<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SchoolClass;
use App\Models\Section;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SectionController extends Controller
{
    public function store(Request $request, SchoolClass $schoolClass): JsonResponse
    {
        $data = $this->validated($request, $schoolClass);
        $section = $schoolClass->sections()->create($data);
        AuditLog::record('section.created', $section);

        return response()->json(['data' => $section], 201);
    }

    public function update(Request $request, Section $section): JsonResponse
    {
        $data = $this->validated($request, $section->schoolClass, $section);
        $section->update($data);
        AuditLog::record('section.updated', $section, $data);

        return response()->json(['data' => $section->refresh()]);
    }

    public function destroy(Section $section): JsonResponse
    {
        $section->delete();
        AuditLog::record('section.deleted', $section);

        return response()->json(status: 204);
    }

    private function validated(Request $request, SchoolClass $schoolClass, ?Section $section = null): array
    {
        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('sections', 'name')
                    ->where('school_class_id', $schoolClass->id)
                    ->ignore($section?->id),
            ],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:32767'],
            'room_number' => ['nullable', 'string', 'max:100'],
        ]);
    }
}
