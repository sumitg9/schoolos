<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AcademicSessionController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => AcademicSession::query()->orderByDesc('starts_on')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $session = DB::transaction(function () use ($data) {
            if ($data['is_active']) {
                AcademicSession::query()->where('is_active', true)->update(['is_active' => false]);
            }

            return AcademicSession::create($data);
        });

        AuditLog::record('academic_session.created', $session);

        return response()->json(['data' => $session], 201);
    }

    public function update(Request $request, AcademicSession $academicSession): JsonResponse
    {
        $data = $this->validated($request, $academicSession);

        DB::transaction(function () use ($data, $academicSession) {
            if ($data['is_active']) {
                AcademicSession::query()->whereKeyNot($academicSession->id)->where('is_active', true)->update(['is_active' => false]);
            }
            $academicSession->update($data);
        });

        AuditLog::record('academic_session.updated', $academicSession, $data);

        return response()->json(['data' => $academicSession->refresh()]);
    }

    public function destroy(AcademicSession $academicSession): JsonResponse
    {
        $academicSession->delete();
        AuditLog::record('academic_session.deleted', $academicSession);

        return response()->json(status: 204);
    }

    private function validated(Request $request, ?AcademicSession $session = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('academic_sessions', 'name')->ignore($session?->id)],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
            'is_active' => ['sometimes', 'boolean'],
        ]) + ['is_active' => $request->has('is_active')
            ? $request->boolean('is_active')
            : ($session?->is_active ?? false)];
    }
}
