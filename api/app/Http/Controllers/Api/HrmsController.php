<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeLeaveRequest;
use App\Models\EmployeeSalaryStructure;
use App\Models\PayrollRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class HrmsController extends Controller
{
    public function payroll(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'pay_year' => ['nullable', 'integer', 'between:2000,2100'],
            'pay_month' => ['nullable', 'integer', 'between:1,12'],
        ]);
        $query = PayrollRecord::query()->with('employee:id,employee_id,first_name,last_name,department');
        if (isset($filters['pay_year'])) {
            $query->where('pay_year', $filters['pay_year']);
        }
        if (isset($filters['pay_month'])) {
            $query->where('pay_month', $filters['pay_month']);
        }
        $records = (clone $query)->latest('pay_year')->latest('pay_month')->latest('id')->limit(500)->get();
        $reportQuery = clone $query;

        return response()->json([
            'records' => $records,
            'employees' => Employee::query()->where('status', 'active')->orderBy('first_name')
                ->get(['id', 'employee_id', 'first_name', 'last_name', 'department']),
            'structures' => EmployeeSalaryStructure::query()->with('employee:id,employee_id,first_name,last_name')
                ->latest('effective_from')->limit(500)->get(),
            'report' => [
                'records' => (clone $reportQuery)->count(),
                'draft' => (clone $reportQuery)->where('status', 'draft')->count(),
                'approved' => (clone $reportQuery)->where('status', 'approved')->count(),
                'paid' => (clone $reportQuery)->where('status', 'paid')->count(),
                'gross_amount' => number_format((float) (clone $reportQuery)->selectRaw('COALESCE(SUM(base_salary + allowances), 0) as total')->value('total'), 2, '.', ''),
                'deductions' => number_format((float) (clone $reportQuery)->sum('deductions'), 2, '.', ''),
                'net_amount' => number_format((float) (clone $reportQuery)->sum('net_amount'), 2, '.', ''),
            ],
        ]);
    }

    public function createSalaryStructure(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'effective_from' => ['required', 'date'],
            'base_salary' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'allowances' => ['nullable', 'array', 'max:30'],
            'allowances.*.name' => ['required', 'string', 'max:80'],
            'allowances.*.amount' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'deductions' => ['nullable', 'array', 'max:30'],
            'deductions.*.name' => ['required', 'string', 'max:80'],
            'deductions.*.amount' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
        ]);
        $employee = Employee::query()->whereKey($data['employee_id'])->where('status', 'active')->first();
        if (! $employee) {
            throw ValidationException::withMessages(['employee_id' => ['Select an active employee in this school.']]);
        }
        $data['allowances'] = $this->normalizeComponents($data['allowances'] ?? []);
        $data['deductions'] = $this->normalizeComponents($data['deductions'] ?? []);
        if (EmployeeSalaryStructure::query()->where('employee_id', $employee->id)->whereDate('effective_from', $data['effective_from'])->exists()) {
            throw ValidationException::withMessages(['effective_from' => ['A salary structure already exists for this employee on that date.']]);
        }
        $data['created_by'] = $request->user()->id;
        $structure = EmployeeSalaryStructure::query()->create($data);
        $structure->load('employee:id,employee_id,first_name,last_name');
        AuditLog::record('hrms.salary_structure_created', $structure, [
            'employee_id' => $structure->employee_id,
            'effective_from' => $structure->effective_from->toDateString(),
            'base_salary' => $structure->base_salary,
            'allowances' => $structure->allowances,
            'deductions' => $structure->deductions,
        ]);

        return response()->json(['data' => $structure], 201);
    }

    public function createPayroll(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'pay_year' => ['required', 'integer', 'between:2000,2100'],
            'pay_month' => ['required', 'integer', 'between:1,12'],
        ]);
        $employee = Employee::query()->whereKey($data['employee_id'])->where('status', 'active')->first();
        if (! $employee) {
            throw ValidationException::withMessages(['employee_id' => ['Payroll can only be prepared for an active employee in this school.']]);
        }
        $periodStart = sprintf('%04d-%02d-01', $data['pay_year'], $data['pay_month']);
        $structure = EmployeeSalaryStructure::query()
            ->where('employee_id', $employee->id)
            ->whereDate('effective_from', '<=', $periodStart)
            ->latest('effective_from')
            ->first();
        if (! $structure) {
            throw ValidationException::withMessages(['employee_id' => ['Create an effective salary structure before preparing payroll.']]);
        }
        if (PayrollRecord::query()->where('employee_id', $employee->id)
            ->where('pay_year', $data['pay_year'])->where('pay_month', $data['pay_month'])->exists()) {
            throw ValidationException::withMessages(['employee_id' => ['Payroll already exists for this employee and month.']]);
        }

        $allowances = $structure->allowances ?? [];
        $deductions = $structure->deductions ?? [];
        $baseCents = $this->toCents($structure->base_salary);
        $allowanceCents = $this->componentTotal($allowances);
        $deductionCents = $this->componentTotal($deductions);
        $netCents = $baseCents + $allowanceCents - $deductionCents;
        if ($netCents < 0) {
            throw ValidationException::withMessages(['deductions' => ['Total deductions cannot exceed base salary and allowances.']]);
        }

        $record = PayrollRecord::query()->create([
            'employee_id' => $employee->id,
            'pay_year' => $data['pay_year'],
            'pay_month' => $data['pay_month'],
            'base_salary' => $this->fromCents($baseCents),
            'allowances' => $this->fromCents($allowanceCents),
            'deductions' => $this->fromCents($deductionCents),
            'allowance_items' => $allowances,
            'deduction_items' => $deductions,
            'net_amount' => $this->fromCents($netCents),
            'status' => 'draft',
            'created_by' => $request->user()->id,
        ]);
        $record->load('employee:id,employee_id,first_name,last_name,department');
        AuditLog::record('payroll.record_created', $record, [
            'employee_id' => $record->employee_id,
            'pay_year' => $record->pay_year,
            'pay_month' => $record->pay_month,
            'net_amount' => $record->net_amount,
        ]);

        return response()->json(['data' => $record], 201);
    }

    public function approvePayroll(Request $request, PayrollRecord $record): JsonResponse
    {
        if ($record->status !== 'draft') {
            throw ValidationException::withMessages(['record' => ['Only draft payroll can be approved.']]);
        }
        if ($record->created_by === $request->user()->id) {
            throw ValidationException::withMessages(['record' => ['The payroll creator cannot approve their own record.']]);
        }
        $record->update([
            'status' => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'slip_number' => sprintf('SAL-%04d%02d-%06d', $record->pay_year, $record->pay_month, $record->id),
        ]);
        AuditLog::record('payroll.approved', $record, ['slip_number' => $record->slip_number]);

        return response()->json(['data' => $record->refresh()->load('employee')]);
    }

    public function payPayroll(Request $request, PayrollRecord $record): JsonResponse
    {
        if ($record->status !== 'approved') {
            throw ValidationException::withMessages(['record' => ['Only approved payroll can be marked as paid.']]);
        }
        if ($record->created_by === $request->user()->id) {
            throw ValidationException::withMessages(['record' => ['The payroll creator cannot pay their own record.']]);
        }
        $data = $request->validate(['reference' => ['nullable', 'string', 'max:100']]);
        $record->update([
            'status' => 'paid',
            'reference' => $data['reference'] ?? null,
            'paid_at' => now(),
            'processed_by' => $request->user()->id,
        ]);
        AuditLog::record('payroll.paid', $record, ['reference' => $record->reference]);

        return response()->json(['data' => $record->refresh()->load('employee')]);
    }

    public function salarySlip(PayrollRecord $record): JsonResponse
    {
        if (! in_array($record->status, ['approved', 'paid'], true)) {
            throw ValidationException::withMessages(['record' => ['Salary slips are available after payroll approval.']]);
        }

        return response()->json(['data' => $record->load('employee:id,employee_id,first_name,last_name,department,designation')]);
    }

    public function leave(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:pending,approved,rejected'],
        ]);
        $query = EmployeeLeaveRequest::query()->with('employee:id,employee_id,first_name,last_name,department')
            ->latest('starts_on')->latest('id');
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return response()->json([
            'requests' => $query->limit(500)->get(),
            'employees' => Employee::query()->where('status', 'active')->orderBy('first_name')
                ->get(['id', 'employee_id', 'first_name', 'last_name', 'department']),
            'summary' => [
                'pending' => EmployeeLeaveRequest::query()->where('status', 'pending')->count(),
                'approved' => EmployeeLeaveRequest::query()->where('status', 'approved')->count(),
                'rejected' => EmployeeLeaveRequest::query()->where('status', 'rejected')->count(),
            ],
        ]);
    }

    public function createLeave(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'leave_type' => ['required', 'in:annual,sick,personal,unpaid,other'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $employee = Employee::query()->whereKey($data['employee_id'])->where('status', 'active')->first();
        if (! $employee) {
            throw ValidationException::withMessages(['employee_id' => ['Select an active employee in this school.']]);
        }
        $this->assertNoLeaveOverlap($employee->id, $data['starts_on'], $data['ends_on']);
        $leave = EmployeeLeaveRequest::query()->create($data);
        $leave->load('employee:id,employee_id,first_name,last_name,department');
        AuditLog::record('hrms.leave_requested', $leave, [
            'employee_id' => $leave->employee_id,
            'leave_type' => $leave->leave_type,
            'starts_on' => $leave->starts_on->toDateString(),
            'ends_on' => $leave->ends_on->toDateString(),
        ]);

        return response()->json(['data' => $leave], 201);
    }

    public function reviewLeave(Request $request, EmployeeLeaveRequest $leave): JsonResponse
    {
        if ($leave->status !== 'pending') {
            throw ValidationException::withMessages(['leave' => ['Only pending leave requests can be reviewed.']]);
        }
        $data = $request->validate([
            'status' => ['required', 'in:approved,rejected'],
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);
        if ($data['status'] === 'approved') {
            $this->assertNoLeaveOverlap(
                $leave->employee_id,
                $leave->starts_on->toDateString(),
                $leave->ends_on->toDateString(),
                $leave->id,
            );
        }
        $leave->update([
            'status' => $data['status'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_note' => $data['review_note'] ?? null,
        ]);
        AuditLog::record('hrms.leave_'.$data['status'], $leave, ['review_note' => $leave->review_note]);

        return response()->json(['data' => $leave->refresh()->load('employee')]);
    }

    private function assertNoLeaveOverlap(int $employeeId, string $startsOn, string $endsOn, ?int $exceptId = null, array $statuses = ['pending', 'approved']): void
    {
        $overlap = EmployeeLeaveRequest::query()
            ->where('employee_id', $employeeId)
            ->whereIn('status', $statuses)
            ->whereDate('starts_on', '<=', $endsOn)
            ->whereDate('ends_on', '>=', $startsOn)
            ->when($exceptId, fn ($query) => $query->where('id', '!=', $exceptId))
            ->exists();
        if ($overlap) {
            throw ValidationException::withMessages(['starts_on' => ['This leave period overlaps another pending or approved request.']]);
        }
    }

    private function normalizeComponents(array $components): array
    {
        return collect($components)->map(fn (array $component) => [
            'name' => trim($component['name']),
            'amount' => $this->fromCents($this->toCents($component['amount'])),
        ])->values()->all();
    }

    private function componentTotal(array $components): int
    {
        return array_sum(array_map(fn (array $component) => $this->toCents($component['amount']), $components));
    }

    private function toCents(string|int|float $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private function fromCents(int $amount): string
    {
        return number_format($amount / 100, 2, '.', '');
    }
}
