<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\School;
use App\Models\SchoolSetting;
use App\Tenancy\SchoolContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SchoolController extends Controller
{
    public function index(): JsonResponse
    {
        $query = School::query();
        if (! request()->user()->roles()->where('name', 'super-admin')->exists()) {
            $query->whereKey(request()->user()->school_id ?? 0);
        }

        return response()->json([
            'data' => $query->orderBy('name')->get(['id', 'name', 'slug', 'status']),
            'active_school_id' => SchoolContext::id(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->roles()->where('name', 'super-admin')->exists(), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'slug' => ['nullable', 'string', 'max:180', 'alpha_dash', 'unique:schools,slug'],
        ]);
        $school = DB::transaction(function () use ($data) {
            $school = School::query()->create([
                'name' => $data['name'],
                'slug' => $data['slug'] ?? $this->uniqueSlug($data['name']),
                'status' => 'active',
            ]);
            SchoolContext::set($school->id);
            SchoolSetting::query()->create([
                'singleton_key' => 'school',
                'name' => $school->name,
            ]);

            return $school;
        });
        AuditLog::record('school.created', $school, ['slug' => $school->slug]);

        return response()->json(['data' => $school], 201);
    }

    public function switch(Request $request, School $school): JsonResponse
    {
        abort_unless($request->user()->roles()->where('name', 'super-admin')->exists(), 403);
        abort_unless($school->status === 'active', 422, 'The selected school is inactive.');
        $request->session()->put('active_school_id', $school->id);

        return response()->json(['data' => $school->only(['id', 'name', 'slug'])]);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'school';
        $slug = $base;
        $suffix = 2;
        while (School::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
