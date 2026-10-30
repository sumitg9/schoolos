<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccessControlController extends Controller
{
    public function createUser(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['integer', 'distinct', 'exists:roles,id'],
        ]);
        $superAdminRoleId = Role::query()->where('name', 'super-admin')->value('id');
        abort_if(
            ! $request->user()->isSuperAdmin() && in_array($superAdminRoleId, $data['role_ids'], true),
            403,
            'Only a Super Admin can assign the Super Admin role.',
        );

        $user = DB::transaction(function () use ($data) {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'is_active' => true,
            ]);
            $user->roles()->sync($data['role_ids']);

            return $user;
        });

        AuditLog::record('user.created', $user, ['role_ids' => $data['role_ids']]);

        return response()->json(['data' => $user->load('roles:id,name,label')], 201);
    }

    public function roles(): JsonResponse
    {
        return response()->json([
            'data' => Role::query()->with('permissions:id,name,label')->orderBy('label')->get(),
            'permissions' => Permission::query()->orderBy('name')->get(['id', 'name', 'label']),
        ]);
    }

    public function updateRolePermissions(Request $request, Role $role): JsonResponse
    {
        abort_if($role->name === 'super-admin' && ! $request->user()->isSuperAdmin(), 403);

        $data = $request->validate([
            'permission_ids' => ['present', 'array'],
            'permission_ids.*' => ['integer', 'distinct', 'exists:permissions,id'],
        ]);

        DB::transaction(fn () => $role->permissions()->sync($data['permission_ids']));
        AuditLog::record('role.permissions_updated', $role, ['permission_ids' => $data['permission_ids']]);

        return response()->json(['data' => $role->load('permissions:id,name,label')]);
    }

    public function users(): JsonResponse
    {
        return response()->json([
            'data' => User::query()->with('roles:id,name,label')->orderBy('name')->get(['id', 'name', 'email', 'is_active']),
            'roles' => Role::query()->orderBy('label')->get(['id', 'name', 'label']),
        ]);
    }

    public function updateUserRoles(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'role_ids' => ['present', 'array'],
            'role_ids.*' => ['integer', 'distinct', 'exists:roles,id'],
        ]);

        if ($user->is($request->user()) && ! in_array(Role::query()->where('name', 'super-admin')->value('id'), $data['role_ids'], true)) {
            return response()->json(['message' => 'You cannot remove your own Super Admin role.'], 422);
        }

        if ($user->is($request->user()) && ! $user->isSuperAdmin()) {
            return response()->json(['message' => 'You cannot change your own roles.'], 422);
        }

        $superAdminRoleId = Role::query()->where('name', 'super-admin')->value('id');
        abort_if(
            ! $request->user()->isSuperAdmin()
                && ($user->roles()->where('name', 'super-admin')->exists()
                    || in_array($superAdminRoleId, $data['role_ids'], true)),
            403,
            'Only a Super Admin can change Super Admin role assignments.',
        );

        DB::transaction(fn () => $user->roles()->sync($data['role_ids']));
        AuditLog::record('user.roles_updated', $user, ['role_ids' => $data['role_ids']]);

        return response()->json(['data' => $user->refresh()->load('roles:id,name,label')]);
    }

    public function updateUserStatus(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        abort_if(
            $user->roles()->where('name', 'super-admin')->exists() && ! $request->user()->isSuperAdmin(),
            403,
        );
        if ($user->is($request->user()) && ! $data['is_active']) {
            return response()->json(['message' => 'You cannot deactivate your own account.'], 422);
        }

        $user->update($data);
        AuditLog::record('user.status_updated', $user, $data);

        return response()->json(['data' => $user->only(['id', 'name', 'email', 'is_active'])]);
    }
}
