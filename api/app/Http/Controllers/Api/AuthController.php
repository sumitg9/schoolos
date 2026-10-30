<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt([...$credentials, 'is_active' => true])) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are invalid or the account is inactive.'],
            ]);
        }

        $request->session()->regenerate();
        AuditLog::record('auth.login');

        return response()->json(['user' => $request->user()->load('school:id,name,slug', 'roles.permissions')]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $request->user()->load('roles.permissions')]);
    }

    public function logout(Request $request): JsonResponse
    {
        AuditLog::record('auth.logout');
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Signed out.']);
    }
}
