<?php

namespace Tests\Feature;

use App\Models\AssetLifecycleEvent;
use App\Models\Employee;
use App\Models\OperationalExpense;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolAsset;
use App\Models\Student;
use App\Models\TransportAssignment;
use App\Models\TransportRoute;
use App\Models\TransportVehicle;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationsAdministrationApiTest extends TestCase
{
    use RefreshDatabase;

    private int $schoolId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        $this->schoolId = School::query()->firstOrCreate(
            ['slug' => 'operations-test-school'],
            ['name' => 'Operations Test School', 'status' => 'active'],
        )->id;
        $admin = $this->admin();
        $this->actingAs($admin);
    }

    public function test_event_expenses_require_independent_review_and_flag_budget_overruns(): void
    {
        $eventId = $this->postJson('/api/events', [
            'name' => 'Annual Sports Day',
            'category' => 'Sports',
            'starts_on' => '2026-11-15',
            'ends_on' => '2026-11-15',
            'venue' => 'Main ground',
            'budget' => '50.00',
        ])->assertCreated()->json('data.id');

        $expenseId = $this->postJson('/api/expenses', [
            'event_id' => $eventId,
            'description' => 'Sports equipment',
            'category' => 'Supplies',
            'vendor' => 'School vendor',
            'spent_on' => '2026-11-10',
            'amount' => '75.00',
        ])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');

        $this->postJson("/api/expenses/{$expenseId}/review", ['status' => 'approved'])
            ->assertUnprocessable()->assertJsonValidationErrors(['expense']);

        $this->actingAs($this->admin());
        $this->postJson("/api/expenses/{$expenseId}/review", ['status' => 'approved'])
            ->assertOk()->assertJsonPath('data.status', 'approved');
        $this->getJson('/api/events')->assertOk()
            ->assertJsonPath('events.0.budget_overrun', true)
            ->assertJsonPath('events.0.committed_expenses', 75)
            ->assertJsonPath('events.0.pending_expense_count', 0);

        $this->patchJson("/api/events/{$eventId}", ['status' => 'cancelled'])
            ->assertUnprocessable()->assertJsonValidationErrors(['status']);

        $this->postJson("/api/expenses/{$expenseId}/pay", ['payment_reference' => 'BANK-001'])
            ->assertOk()->assertJsonPath('data.status', 'paid');
        $this->getJson('/api/expenses')->assertOk()->assertJsonPath('report.paid', 1);
        $this->assertSame('paid', OperationalExpense::query()->findOrFail($expenseId)->status);
    }

    public function test_asset_lifecycle_tracks_custodian_condition_and_terminal_disposal(): void
    {
        $employee = $this->employee();
        $assetId = $this->postJson('/api/assets', [
            'asset_tag' => 'LAPTOP-001',
            'name' => 'Library laptop',
            'category' => 'IT',
            'serial_number' => 'SERIAL-001',
            'acquired_on' => '2026-01-01',
            'acquisition_cost' => '750.00',
            'location' => 'Library',
            'condition' => 'new',
            'custodian_employee_id' => $employee->id,
            'warranty_ends_on' => '2027-01-01',
        ])->assertCreated()->assertJsonPath('data.status', 'active')->json('data.id');

        $this->postJson("/api/assets/{$assetId}/lifecycle", [
            'status' => 'maintenance',
            'condition' => 'fair',
            'location' => 'Repair room',
            'custodian_employee_id' => '',
            'occurred_on' => '2026-10-30',
            'notes' => 'Screen repair',
        ])->assertOk()->assertJsonPath('data.status', 'maintenance')
            ->assertJsonPath('data.condition', 'fair')
            ->assertJsonPath('data.custodian_employee_id', null);

        $history = AssetLifecycleEvent::query()->where('school_asset_id', $assetId)->latest('id')->firstOrFail();
        $this->assertSame('new', $history->from_condition);
        $this->assertSame('fair', $history->to_condition);
        $this->assertSame($employee->id, $history->from_custodian_employee_id);
        $this->assertNull($history->to_custodian_employee_id);

        $this->postJson("/api/assets/{$assetId}/lifecycle", [
            'status' => 'disposed',
            'condition' => 'poor',
            'occurred_on' => '2026-11-01',
            'notes' => 'Beyond economical repair',
        ])->assertOk()->assertJsonPath('data.status', 'disposed');
        $this->postJson("/api/assets/{$assetId}/lifecycle", [
            'status' => 'active',
            'occurred_on' => '2026-11-02',
        ])->assertUnprocessable()->assertJsonValidationErrors(['status']);

        $this->assertSame(3, AssetLifecycleEvent::query()->where('school_asset_id', $assetId)->count());
        $this->assertSame('disposed', SchoolAsset::query()->findOrFail($assetId)->status);
    }

    public function test_transport_vehicle_cannot_be_taken_out_of_service_below_active_riders(): void
    {
        $student = Student::query()->forceCreate([
            'school_id' => $this->schoolId,
            'admission_number' => 'TRANS-001',
            'first_name' => 'Alex',
            'admission_date' => '2026-01-01',
            'status' => 'active',
        ]);
        $vehicleId = $this->postJson('/api/transport/vehicles', [
            'registration_number' => 'BUS-OPERATIONS-001',
            'capacity' => 2,
        ])->assertCreated()->json('data.id');
        $routeId = $this->postJson('/api/transport/routes', [
            'name' => 'Operations route',
            'stops' => 'School, Main road',
            'monthly_fee' => 25,
            'vehicle_id' => $vehicleId,
        ])->assertCreated()->json('data.id');
        $this->postJson('/api/transport/assignments', [
            'transport_route_id' => $routeId,
            'student_id' => $student->id,
            'starts_on' => '2026-11-01',
        ])->assertCreated();

        $this->patchJson("/api/transport/vehicles/{$vehicleId}", ['capacity' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors(['capacity']);
        $this->patchJson("/api/transport/vehicles/{$vehicleId}", ['status' => 'maintenance'])
            ->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->patchJson("/api/transport/routes/{$routeId}", ['status' => 'inactive'])
            ->assertUnprocessable()->assertJsonValidationErrors(['status']);

        $assignment = TransportAssignment::query()->firstOrFail();
        $this->postJson("/api/transport/assignments/{$assignment->id}/end")->assertOk();
        $this->patchJson("/api/transport/vehicles/{$vehicleId}", ['status' => 'maintenance'])
            ->assertOk()->assertJsonPath('data.status', 'maintenance');
        $this->patchJson("/api/transport/routes/{$routeId}", ['status' => 'inactive'])
            ->assertOk()->assertJsonPath('data.status', 'inactive');

        $this->assertSame('maintenance', TransportVehicle::query()->findOrFail($vehicleId)->status);
        $this->assertSame('inactive', TransportRoute::query()->findOrFail($routeId)->status);
    }

    public function test_operations_permissions_are_enforced_on_server_routes(): void
    {
        $staff = User::factory()->create(['school_id' => $this->schoolId]);
        $staff->roles()->sync([Role::query()->where('name', 'teacher')->value('id')]);
        $this->actingAs($staff);

        $this->getJson('/api/transport')->assertForbidden();
        $this->getJson('/api/expenses')->assertForbidden();
        $this->postJson('/api/events', [
            'name' => 'Unauthorized event',
            'starts_on' => '2026-11-15',
            'ends_on' => '2026-11-15',
            'budget' => 100,
        ])->assertForbidden();

        $manager = User::factory()->create(['school_id' => $this->schoolId]);
        $manager->roles()->sync([Role::query()->where('name', 'operations-manager')->value('id')]);
        $this->actingAs($manager);
        $this->getJson('/api/assets')->assertOk();
        $this->getJson('/api/events')->assertOk();
        $this->getJson('/api/expenses')->assertOk();
    }

    public function test_linked_event_expense_and_asset_changes_are_audited_together(): void
    {
        $eventId = $this->postJson('/api/events', [
            'name' => 'Integrated event',
            'starts_on' => '2026-12-01',
            'ends_on' => '2026-12-01',
            'budget' => 100,
        ])->assertCreated()->json('data.id');
        $expenseId = $this->postJson('/api/expenses', [
            'event_id' => $eventId,
            'description' => 'Event materials',
            'category' => 'Supplies',
            'spent_on' => '2026-11-30',
            'amount' => 50,
        ])->assertCreated()->json('data.id');
        $assetId = $this->postJson('/api/assets', [
            'asset_tag' => 'INTEGRATION-001',
            'name' => 'Event projector',
            'category' => 'AV',
            'acquired_on' => '2026-11-01',
            'acquisition_cost' => 250,
            'condition' => 'good',
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/assets/{$assetId}/lifecycle", [
            'status' => 'maintenance',
            'occurred_on' => '2026-11-30',
            'notes' => 'Routine check before event.',
        ])->assertOk();

        $this->getJson('/api/events')->assertOk()
            ->assertJsonPath('events.0.expenses.0.id', $expenseId)
            ->assertJsonPath('events.0.expenses.0.event_id', $eventId);
        $this->assertDatabaseHas('audit_logs', ['action' => 'operations.event_created']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'operations.expense_submitted']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'operations.asset_registered']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'operations.asset_lifecycle_updated']);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['school_id' => $this->schoolId]);
        $user->roles()->sync([Role::query()->where('name', 'super-admin')->value('id')]);

        return $user;
    }

    private function employee(): Employee
    {
        $employee = new Employee([
            'employee_id' => 'OPS-EMP-001',
            'first_name' => 'Morgan',
            'last_name' => 'Custodian',
            'joining_date' => '2025-01-01',
            'status' => 'active',
        ]);
        $employee->school_id = $this->schoolId;
        $employee->save();

        return $employee;
    }
}
