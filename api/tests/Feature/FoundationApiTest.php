<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FoundationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_academic_endpoints_require_authentication(): void
    {
        $this->getJson('/api/academic-sessions')->assertUnauthorized();
    }

    public function test_stateful_api_login_authenticates_the_user(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.test']);
        $user->roles()->sync([Role::query()->where('name', 'super-admin')->value('id')]);

        $this->postJson('/api/login', [
            'email' => 'admin@example.test',
            'password' => 'password',
        ], [
            'Origin' => 'http://localhost:3000',
            'Referer' => 'http://localhost:3000/login',
        ])->assertOk()->assertJsonPath('user.email', 'admin@example.test');

        $this->getJson('/api/me', [
            'Origin' => 'http://localhost:3000',
            'Referer' => 'http://localhost:3000/',
        ])->assertOk()->assertJsonPath('user.email', 'admin@example.test');
    }

    public function test_role_permissions_are_enforced_by_the_api(): void
    {
        $teacher = User::factory()->create();
        $teacher->roles()->sync([Role::query()->where('name', 'teacher')->value('id')]);
        $this->assertTrue($teacher->is_active);
        $this->assertTrue($teacher->hasPermission('academic_sessions.view'));
        $this->actingAs($teacher);

        $this->getJson('/api/academic-sessions')->assertOk();
        $this->postJson('/api/academic-sessions', [
            'name' => '2026-2027',
            'starts_on' => '2026-04-01',
            'ends_on' => '2027-03-31',
            'is_active' => true,
        ])->assertForbidden();
    }

    public function test_creating_an_active_session_deactivates_the_previous_one_and_is_audited(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->sync([Role::query()->where('name', 'super-admin')->value('id')]);
        $this->actingAs($admin);

        $this->postJson('/api/academic-sessions', [
            'name' => '2026-2027',
            'starts_on' => '2026-04-01',
            'ends_on' => '2027-03-31',
            'is_active' => true,
        ])->assertCreated();

        $this->postJson('/api/academic-sessions', [
            'name' => '2027-2028',
            'starts_on' => '2027-04-01',
            'ends_on' => '2028-03-31',
            'is_active' => true,
        ])->assertCreated();

        $this->assertSame(1, AcademicSession::query()->where('is_active', true)->count());
        $this->assertDatabaseHas('academic_sessions', ['name' => '2026-2027', 'is_active' => false]);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $admin->id, 'action' => 'academic_session.created']);
        $this->assertSame(2, AuditLog::query()->count());
    }

    public function test_classes_sections_and_subjects_are_linked_to_their_academic_session(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->sync([Role::query()->where('name', 'super-admin')->value('id')]);
        $this->actingAs($admin);

        $sessionId = $this->postJson('/api/academic-sessions', [
            'name' => '2026-2027',
            'starts_on' => '2026-04-01',
            'ends_on' => '2027-03-31',
            'is_active' => true,
        ])->assertCreated()->json('data.id');

        $classId = $this->postJson('/api/classes', [
            'academic_session_id' => $sessionId,
            'name' => 'Class 1',
            'capacity' => 30,
            'room_number' => 'A-101',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/classes/{$classId}/sections", [
            'name' => 'A',
        ])->assertCreated();

        $subjectId = $this->postJson('/api/subjects', [
            'academic_session_id' => $sessionId,
            'name' => 'Mathematics',
            'code' => 'MATH-1',
            'type' => 'compulsory',
            'class_ids' => [$classId],
        ])->assertCreated()->assertJsonPath('data.classes.0.id', $classId)->json('data.id');

        $this->assertDatabaseHas('school_classes', ['id' => $classId, 'academic_session_id' => $sessionId]);
        $this->assertDatabaseHas('sections', ['school_class_id' => $classId, 'name' => 'A']);
        $this->assertDatabaseHas('subjects', ['id' => $subjectId, 'academic_session_id' => $sessionId, 'code' => 'MATH-1']);
        $this->assertDatabaseHas('school_class_subject', ['school_class_id' => $classId, 'subject_id' => $subjectId]);
    }

    public function test_subjects_cannot_be_mapped_to_classes_from_another_session(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->sync([Role::query()->where('name', 'super-admin')->value('id')]);
        $this->actingAs($admin);

        $sessionId = $this->postJson('/api/academic-sessions', [
            'name' => '2026-2027',
            'starts_on' => '2026-04-01',
            'ends_on' => '2027-03-31',
            'is_active' => true,
        ])->assertCreated()->json('data.id');
        $otherSessionId = $this->postJson('/api/academic-sessions', [
            'name' => '2027-2028',
            'starts_on' => '2027-04-01',
            'ends_on' => '2028-03-31',
            'is_active' => false,
        ])->assertCreated()->json('data.id');
        $classId = $this->postJson('/api/classes', [
            'academic_session_id' => $otherSessionId,
            'name' => 'Class 1',
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/subjects', [
            'academic_session_id' => $sessionId,
            'name' => 'Mathematics',
            'type' => 'compulsory',
            'class_ids' => [$classId],
        ])->assertUnprocessable()->assertJsonValidationErrors(['class_ids']);
    }

    public function test_school_settings_are_validated_and_saved(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->sync([Role::query()->where('name', 'super-admin')->value('id')]);
        $this->actingAs($admin);

        $this->putJson('/api/settings/school', [
            'name' => 'Green Valley School',
            'address' => 'Pune, Maharashtra',
            'phone' => '+91 20 1234 5678',
            'email' => 'office@example.test',
            'currency' => 'INR',
            'timezone' => 'Asia/Kolkata',
            'date_format' => 'd-m-Y',
        ])->assertOk()->assertJsonPath('data.name', 'Green Valley School');

        $this->assertDatabaseHas('school_settings', ['name' => 'Green Valley School', 'currency' => 'INR']);
    }

    public function test_school_admin_cannot_grant_or_change_the_super_admin_role(): void
    {
        $schoolAdmin = User::factory()->create();
        $schoolAdmin->roles()->sync([Role::query()->where('name', 'school-admin')->value('id')]);
        $superAdmin = User::factory()->create();
        $superAdminRoleId = Role::query()->where('name', 'super-admin')->value('id');
        $teacherRoleId = Role::query()->where('name', 'teacher')->value('id');
        $superAdmin->roles()->sync([$superAdminRoleId]);
        $this->actingAs($schoolAdmin);

        $this->putJson("/api/access/users/{$superAdmin->id}/roles", ['role_ids' => []])->assertForbidden();
        $this->putJson("/api/access/users/{$superAdmin->id}/status", ['is_active' => false])->assertForbidden();
        $this->putJson("/api/access/roles/{$superAdminRoleId}/permissions", ['permission_ids' => []])->assertForbidden();
        $this->postJson('/api/access/users', [
            'name' => 'New Teacher',
            'email' => 'new-teacher@example.test',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
            'role_ids' => [$teacherRoleId],
        ])->assertCreated()->assertJsonMissingPath('data.password');
        $this->postJson('/api/access/users', [
            'name' => 'New Administrator',
            'email' => 'new-admin@example.test',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
            'role_ids' => [$superAdminRoleId],
        ])->assertForbidden();
    }
}
