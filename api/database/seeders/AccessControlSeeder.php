<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AccessControlSeeder extends Seeder
{
    public function run(): void
    {
        $modules = [
            'dashboard' => 'Dashboard',
            'school_settings' => 'School settings',
            'academic_sessions' => 'Academic sessions',
            'classes' => 'Classes and sections',
            'subjects' => 'Subjects',
            'roles' => 'Roles and permissions',
            'users' => 'Users',
            'audit_logs' => 'Audit logs',
            'students' => 'Student records',
            'students.medical_records' => 'Restricted student medical records',
            'employees' => 'Employee records',
            'teacher_assignments' => 'Teacher assignments',
            'exams' => 'Examination schedules',
            'marks' => 'Marks and report cards',
            'homework' => 'Homework and assignments',
            'timetable' => 'Timetable',
            'notices' => 'Notices and announcements',
            'attendance' => 'Student attendance',
            'fees' => 'Fee charges',
            'fee_payments' => 'Fee payments',
            'portal_accounts' => 'Parent and student portal accounts',
            'schools' => 'School administration',
            'online_payments' => 'Online fee payments',
            'notifications' => 'Notifications',
            'library' => 'Library',
            'transport' => 'Transport management',
            'payroll' => 'Payroll',
            'leave' => 'Leave management',
            'inventory' => 'Inventory',
            'assets' => 'Asset register',
            'expenses' => 'Operational expenses',
            'events' => 'School events',
        ];
        $actions = ['view', 'create', 'edit', 'delete', 'approve', 'publish', 'export', 'print', 'import', 'upload'];

        foreach ($modules as $module => $label) {
            foreach ($actions as $action) {
                Permission::query()->updateOrCreate(
                    ['name' => "{$module}.{$action}"],
                    ['label' => "{$action} {$label}"],
                );
            }
        }
        Permission::query()->updateOrCreate(
            ['name' => 'payroll.pay'],
            ['label' => 'pay Payroll'],
        );
        Permission::query()->updateOrCreate(
            ['name' => 'expenses.pay'],
            ['label' => 'pay Operational expenses'],
        );

        $roleDefinitions = [
            'super-admin' => ['Super Admin', array_keys($modules), $actions],
            'school-admin' => ['School Admin', array_keys($modules), [...$actions, 'pay']],
            'principal' => ['Principal', ['dashboard', 'academic_sessions', 'classes', 'subjects', 'audit_logs', 'students', 'employees', 'teacher_assignments', 'exams', 'marks', 'homework', 'timetable', 'notices', 'attendance', 'fees', 'fee_payments', 'portal_accounts', 'notifications', 'library', 'transport', 'payroll', 'leave', 'inventory', 'assets', 'expenses', 'events'], ['view', 'create', 'edit', 'delete', 'approve', 'publish', 'export', 'print', 'import', 'upload', 'pay']],
            'teacher' => ['Teacher', ['dashboard', 'academic_sessions', 'classes', 'subjects', 'students', 'teacher_assignments', 'exams', 'marks', 'homework', 'timetable', 'notices', 'attendance'], ['view']],
            'accountant' => ['Accountant', ['dashboard', 'fees', 'fee_payments', 'payroll', 'expenses', 'events'], ['view', 'create', 'edit', 'approve', 'pay']],
            'hr-manager' => ['HR Manager', ['dashboard', 'users', 'employees', 'leave', 'payroll'], ['view', 'create', 'edit', 'approve', 'export', 'print', 'import', 'upload']],
            'transport-manager' => ['Transport Manager', ['dashboard', 'transport'], ['view', 'create', 'edit']],
            'librarian' => ['Librarian', ['library'], ['view', 'create', 'edit', 'delete']],
            'inventory-manager' => ['Inventory Manager', ['inventory', 'assets'], ['view', 'create', 'edit']],
            'operations-manager' => ['Operations Manager', ['dashboard', 'transport', 'assets', 'expenses', 'events'], ['view', 'create', 'edit', 'approve', 'pay']],
            'parent' => ['Parent', [], []],
            'student' => ['Student', [], []],
        ];

        DB::transaction(function () use ($roleDefinitions) {
            foreach ($roleDefinitions as $name => [$label, $moduleNames, $roleActions]) {
                $role = Role::query()->updateOrCreate(['name' => $name], ['label' => $label]);
                $permissionNames = $name === 'super-admin'
                    ? Permission::query()->pluck('id')
                    : Permission::query()
                        ->whereIn('name', collect($moduleNames)
                            ->flatMap(fn ($module) => collect($roleActions)->map(fn ($action) => "{$module}.{$action}"))
                            ->all())
                        ->pluck('id');
                $role->permissions()->sync($permissionNames);
            }

            $teacher = Role::query()->where('name', 'teacher')->firstOrFail();
            $teacherPermissions = Permission::query()
                ->whereIn('name', [
                    'marks.create', 'marks.edit',
                    'homework.create', 'homework.edit',
                    'notices.create',
                    'attendance.create',
                ])
                ->pluck('id');
            $teacher->permissions()->syncWithoutDetaching($teacherPermissions);
        });
    }
}
