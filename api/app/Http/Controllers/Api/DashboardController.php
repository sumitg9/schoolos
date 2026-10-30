<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\Employee;
use App\Models\SchoolClass;
use App\Models\SchoolSetting;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'school_name' => SchoolSetting::query()->value('name') ?? 'School',
                'active_session' => AcademicSession::query()->where('is_active', true)->value('name'),
                'academic_sessions' => AcademicSession::query()->count(),
                'classes' => SchoolClass::query()->count(),
                'subjects' => Subject::query()->count(),
                'active_students' => Student::query()->where('status', 'active')->count(),
                'active_employees' => Employee::query()->where('status', 'active')->count(),
                'active_users' => User::query()->where('is_active', true)->count(),
            ],
        ]);
    }
}
