<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SchoolSetting;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SchoolSettingController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'data' => SchoolSetting::query()->firstOrNew(['singleton_key' => 'school'], ['name' => 'School']),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'timezone' => ['required', 'timezone'],
            'date_format' => ['required', Rule::in(['d-m-Y', 'Y-m-d', 'm/d/Y'])],
        ]);

        $settings = SchoolSetting::query()->firstOrNew(['singleton_key' => 'school']);
        $settings->fill($data)->save();
        School::query()->whereKey($settings->school_id)->update(['name' => $data['name']]);

        AuditLog::record('school_settings.updated', $settings, $data);

        return response()->json(['data' => $settings->refresh()]);
    }
}
