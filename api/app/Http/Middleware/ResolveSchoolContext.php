<?php

namespace App\Http\Middleware;

use App\Tenancy\SchoolContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveSchoolContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $activeSchoolId = $user?->roles()->where('name', 'super-admin')->exists()
            ? ($request->hasSession() ? $request->session()->get('active_school_id', $user->school_id) : $user->school_id)
            : $user?->school_id;
        SchoolContext::set($activeSchoolId ? (int) $activeSchoolId : null);

        try {
            return $next($request);
        } finally {
            SchoolContext::set(null);
        }
    }
}
