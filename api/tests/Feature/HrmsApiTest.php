<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeLeaveRequest;
use App\Models\PayrollRecord;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HrmsApiTest extends TestCase
{
    use RefreshDatabase;

    private int $schoolId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        $this->schoolId = School::query()->firstOrCreate(
            ['slug' => 'hrms-test-school'],
            ['name' => 'HRMS Test School', 'status' => 'active'],
        )->id;
        $this->actingAs($this->admin());
    }

    public function test_leave_requests_reject_overlaps_and_keep_review_history(): void
    {
        $employee = $this->employee();
        $leaveId = $this->postJson('/api/leave', [
            'employee_id' => $employee->id,
            'leave_type' => 'annual',
            'starts_on' => '2026-11-02',
            'ends_on' => '2026-11-04',
            'reason' => 'Family event',
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/leave', [
            'employee_id' => $employee->id,
            'leave_type' => 'sick',
            'starts_on' => '2026-11-04',
            'ends_on' => '2026-11-05',
        ])->assertUnprocessable()->assertJsonValidationErrors(['starts_on']);

        $this->postJson("/api/leave/{$leaveId}/review", ['status' => 'approved', 'review_note' => 'Approved'])
            ->assertOk()->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.review_note', 'Approved');
        $this->postJson("/api/leave/{$leaveId}/review", ['status' => 'rejected'])
            ->assertUnprocessable()->assertJsonValidationErrors(['leave']);

        $this->assertSame('approved', EmployeeLeaveRequest::query()->findOrFail($leaveId)->status);
        $this->assertSame(1, AuditLog::query()->where('action', 'hrms.leave_approved')->count());
    }

    public function test_hr_and_accounting_roles_have_separate_leave_and_payroll_permissions(): void
    {
        $hrManagerPermissions = Role::query()->where('name', 'hr-manager')->firstOrFail()
            ->permissions()->pluck('name')->all();
        $accountantPermissions = Role::query()->where('name', 'accountant')->firstOrFail()
            ->permissions()->pluck('name')->all();

        $this->assertContains('leave.approve', $hrManagerPermissions);
        $this->assertContains('payroll.create', $hrManagerPermissions);
        $this->assertNotContains('payroll.pay', $hrManagerPermissions);
        $this->assertContains('payroll.approve', $accountantPermissions);
        $this->assertContains('payroll.pay', $accountantPermissions);
    }

    public function test_payroll_snapshots_effective_salary_and_requires_separate_approval_and_payment(): void
    {
        $employee = $this->employee();
        $this->postJson('/api/payroll/structures', [
            'employee_id' => $employee->id,
            'effective_from' => '2026-01-01',
            'base_salary' => '50000.00',
            'allowances' => [
                ['name' => 'Housing', 'amount' => '5000.00'],
                ['name' => 'Travel', 'amount' => '1000.00'],
            ],
            'deductions' => [['name' => 'Tax', 'amount' => '2000.00']],
        ])->assertCreated();
        $this->postJson('/api/payroll/structures', [
            'employee_id' => $employee->id,
            'effective_from' => '2026-03-15',
            'base_salary' => '70000.00',
            'allowances' => [],
            'deductions' => [],
        ])->assertCreated();

        $recordId = $this->postJson('/api/payroll', [
            'employee_id' => $employee->id,
            'pay_year' => 2026,
            'pay_month' => 3,
        ])->assertCreated()
            ->assertJsonPath('data.base_salary', '50000.00')
            ->assertJsonPath('data.allowances', '6000.00')
            ->assertJsonPath('data.deductions', '2000.00')
            ->assertJsonPath('data.net_amount', '54000.00')
            ->json('data.id');

        $this->postJson("/api/payroll/{$recordId}/approve")
            ->assertUnprocessable()->assertJsonValidationErrors(['record']);

        $approver = $this->admin();
        $this->actingAs($approver);
        $this->postJson("/api/payroll/{$recordId}/approve")
            ->assertOk()->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.slip_number', 'SAL-202603-000001');
        $this->getJson("/api/payroll/{$recordId}/slip")
            ->assertOk()->assertJsonPath('data.slip_number', 'SAL-202603-000001');

        $this->actingAs(User::query()->whereKey($this->firstAdminId())->firstOrFail());
        $this->postJson("/api/payroll/{$recordId}/pay", ['reference' => 'BANK-REF-01'])
            ->assertUnprocessable()->assertJsonValidationErrors(['record']);

        $payer = $this->admin();
        $this->actingAs($payer);
        $this->postJson("/api/payroll/{$recordId}/pay", ['reference' => 'BANK-REF-01'])
            ->assertOk()->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.reference', 'BANK-REF-01');

        $this->assertSame('paid', PayrollRecord::query()->findOrFail($recordId)->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payroll.approved']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payroll.paid']);
        $this->getJson('/api/payroll?pay_year=2026&pay_month=3')
            ->assertOk()->assertJsonPath('report.paid', 1)->assertJsonPath('report.net_amount', '54000.00');
    }

    private function employee(): Employee
    {
        $employee = new Employee([
            'employee_id' => 'HR-'.fake()->unique()->numerify('#####'),
            'first_name' => 'Taylor',
            'last_name' => 'Employee',
            'email' => fake()->unique()->safeEmail(),
            'joining_date' => '2025-01-01',
            'status' => 'active',
        ]);
        $employee->school_id = $this->schoolId;
        $employee->save();

        return $employee;
    }

    private function admin(): User
    {
        $user = User::factory()->create(['school_id' => $this->schoolId]);
        $user->roles()->sync([Role::query()->where('name', 'super-admin')->value('id')]);

        return $user;
    }

    private function firstAdminId(): int
    {
        return (int) User::query()->orderBy('id')->value('id');
    }
}
