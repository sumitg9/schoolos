<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\OperationalExpense;
use App\Models\SchoolAsset;
use App\Models\SchoolEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OperationsAdministrationController extends Controller
{
    public function expenses(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $query = OperationalExpense::query()->with('event:id,name,budget,starts_on')
            ->latest('spent_on')->latest('id');
        if (isset($filters['from'])) {
            $query->whereDate('spent_on', '>=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $query->whereDate('spent_on', '<=', $filters['to']);
        }
        $reportQuery = clone $query;

        return response()->json([
            'expenses' => (clone $query)->limit(500)->get(),
            'events' => SchoolEvent::query()->whereIn('status', ['planned', 'in_progress'])
                ->orderBy('starts_on')->get(['id', 'name', 'budget', 'starts_on']),
            'report' => [
                'count' => (clone $reportQuery)->count(),
                'pending' => (clone $reportQuery)->where('status', 'pending')->count(),
                'approved' => (clone $reportQuery)->where('status', 'approved')->count(),
                'paid' => (clone $reportQuery)->where('status', 'paid')->count(),
                'rejected' => (clone $reportQuery)->where('status', 'rejected')->count(),
                'pending_amount' => $this->sumAmount(clone $reportQuery, 'pending'),
                'approved_amount' => $this->sumAmount(clone $reportQuery, 'approved'),
                'paid_amount' => $this->sumAmount(clone $reportQuery, 'paid'),
            ],
        ]);
    }

    public function createExpense(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event_id' => ['nullable', 'integer', 'exists:school_events,id'],
            'description' => ['required', 'string', 'max:180'],
            'category' => ['required', 'string', 'max:80'],
            'vendor' => ['nullable', 'string', 'max:180'],
            'spent_on' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99'],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);
        if (! empty($data['event_id'])) {
            $event = SchoolEvent::query()->whereKey($data['event_id'])->first();
            if (! $event || in_array($event->status, ['completed', 'cancelled'], true)) {
                throw ValidationException::withMessages(['event_id' => ['Choose an active event in this school.']]);
            }
        }
        $expense = OperationalExpense::query()->create($data + [
            'status' => 'pending',
            'submitted_by' => $request->user()->id,
        ]);
        AuditLog::record('operations.expense_submitted', $expense, [
            'event_id' => $expense->event_id,
            'category' => $expense->category,
            'amount' => $expense->amount,
        ]);

        return response()->json(['data' => $expense->load('event')], 201);
    }

    public function reviewExpense(Request $request, OperationalExpense $expense): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:approved,rejected'],
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);
        $expense = DB::transaction(function () use ($request, $expense, $data) {
            $lockedExpense = OperationalExpense::query()->lockForUpdate()->findOrFail($expense->id);
            if ($lockedExpense->status !== 'pending') {
                throw ValidationException::withMessages(['expense' => ['Only pending expenses can be reviewed.']]);
            }
            if ($lockedExpense->submitted_by === $request->user()->id) {
                throw ValidationException::withMessages(['expense' => ['The expense submitter cannot approve or reject their own expense.']]);
            }
            $lockedExpense->update([
                'status' => $data['status'],
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
                'review_note' => $data['review_note'] ?? null,
            ]);

            return $lockedExpense;
        });
        AuditLog::record('operations.expense_'.$data['status'], $expense, ['review_note' => $expense->review_note]);

        return response()->json(['data' => $expense->refresh()->load('event')]);
    }

    public function payExpense(Request $request, OperationalExpense $expense): JsonResponse
    {
        $data = $request->validate(['payment_reference' => ['nullable', 'string', 'max:100']]);
        $expense = DB::transaction(function () use ($request, $expense, $data) {
            $lockedExpense = OperationalExpense::query()->lockForUpdate()->findOrFail($expense->id);
            if ($lockedExpense->status !== 'approved') {
                throw ValidationException::withMessages(['expense' => ['Only approved expenses can be marked as paid.']]);
            }
            if ($lockedExpense->submitted_by === $request->user()->id) {
                throw ValidationException::withMessages(['expense' => ['The expense submitter cannot pay their own expense.']]);
            }
            $lockedExpense->update([
                'status' => 'paid',
                'paid_by' => $request->user()->id,
                'paid_at' => now(),
                'payment_reference' => $data['payment_reference'] ?? null,
            ]);

            return $lockedExpense;
        });
        AuditLog::record('operations.expense_paid', $expense, ['payment_reference' => $expense->payment_reference]);

        return response()->json(['data' => $expense->refresh()->load('event')]);
    }

    public function assets(): JsonResponse
    {
        $assets = SchoolAsset::query()->with(['custodian:id,employee_id,first_name,last_name', 'lifecycleEvents' => fn ($query) => $query->latest('occurred_on')->latest('id')])
            ->orderBy('asset_tag')->limit(1000)->get();

        return response()->json([
            'assets' => $assets,
            'employees' => Employee::query()->where('status', 'active')->orderBy('first_name')
                ->get(['id', 'employee_id', 'first_name', 'last_name', 'department']),
            'report' => [
                'count' => $assets->count(),
                'active' => $assets->where('status', 'active')->count(),
                'maintenance' => $assets->where('status', 'maintenance')->count(),
                'disposed' => $assets->where('status', 'disposed')->count(),
                'lost' => $assets->where('status', 'lost')->count(),
                'acquisition_value' => number_format((float) $assets->sum('acquisition_cost'), 2, '.', ''),
            ],
        ]);
    }

    public function createAsset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'asset_tag' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:180'],
            'category' => ['required', 'string', 'max:80'],
            'serial_number' => ['nullable', 'string', 'max:100'],
            'acquired_on' => ['required', 'date'],
            'acquisition_cost' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'location' => ['nullable', 'string', 'max:180'],
            'condition' => ['required', 'in:new,good,fair,poor'],
            'warranty_ends_on' => ['nullable', 'date', 'after_or_equal:acquired_on'],
            'custodian_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        if (SchoolAsset::query()->where('asset_tag', $data['asset_tag'])->exists()) {
            throw ValidationException::withMessages(['asset_tag' => ['This asset tag already exists in this school.']]);
        }
        $this->assertActiveEmployee($data['custodian_employee_id'] ?? null);
        $asset = SchoolAsset::query()->create($data + [
            'status' => 'active',
            'created_by' => $request->user()->id,
        ]);
        $event = $asset->lifecycleEvents()->create([
            'event_type' => 'acquired',
            'occurred_on' => $asset->acquired_on,
            'to_status' => 'active',
            'to_condition' => $asset->condition,
            'to_custodian_employee_id' => $asset->custodian_employee_id,
            'location' => $asset->location,
            'notes' => 'Asset registered.',
            'recorded_by' => $request->user()->id,
        ]);
        AuditLog::record('operations.asset_registered', $asset, [
            'asset_tag' => $asset->asset_tag,
            'acquisition_cost' => $asset->acquisition_cost,
        ]);

        return response()->json(['data' => $asset->load(['custodian', 'lifecycleEvents'])], 201);
    }

    public function updateAsset(Request $request, SchoolAsset $asset): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:active,maintenance,disposed,lost'],
            'condition' => ['nullable', 'in:new,good,fair,poor'],
            'location' => ['nullable', 'string', 'max:180'],
            'custodian_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'occurred_on' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        if ($asset->status === 'disposed') {
            throw ValidationException::withMessages(['status' => ['Disposed assets are terminal and cannot be changed.']]);
        }
        $this->assertActiveEmployee($data['custodian_employee_id'] ?? null);
        [$asset, $previousStatus] = DB::transaction(function () use ($request, $asset, $data) {
            $lockedAsset = SchoolAsset::query()->lockForUpdate()->findOrFail($asset->id);
            if ($lockedAsset->status === 'disposed') {
                throw ValidationException::withMessages(['status' => ['Disposed assets are terminal and cannot be changed.']]);
            }
            $previousStatus = $lockedAsset->status;
            $previousCondition = $lockedAsset->condition;
            $previousCustodian = $lockedAsset->custodian_employee_id;
            $lockedAsset->update([
                'status' => $data['status'],
                'condition' => $data['condition'] ?? $lockedAsset->condition,
                'location' => array_key_exists('location', $data) ? $data['location'] : $lockedAsset->location,
                'custodian_employee_id' => array_key_exists('custodian_employee_id', $data)
                    ? $data['custodian_employee_id']
                    : $lockedAsset->custodian_employee_id,
            ]);
            $lockedAsset->lifecycleEvents()->create([
                'event_type' => $data['status'] === 'maintenance' ? 'maintenance' : ($data['status'] === 'disposed' ? 'disposed' : 'status_changed'),
                'occurred_on' => $data['occurred_on'],
                'from_status' => $previousStatus,
                'to_status' => $data['status'],
                'from_condition' => $previousCondition,
                'to_condition' => $data['condition'] ?? $lockedAsset->condition,
                'from_custodian_employee_id' => $previousCustodian,
                'to_custodian_employee_id' => array_key_exists('custodian_employee_id', $data)
                    ? $data['custodian_employee_id']
                    : $lockedAsset->custodian_employee_id,
                'location' => $data['location'] ?? $lockedAsset->location,
                'notes' => $data['notes'] ?? null,
                'recorded_by' => $request->user()->id,
            ]);

            return [$lockedAsset, $previousStatus];
        });
        AuditLog::record('operations.asset_lifecycle_updated', $asset, [
            'from_status' => $previousStatus,
            'to_status' => $asset->status,
        ]);

        return response()->json(['data' => $asset->refresh()->load(['custodian', 'lifecycleEvents'])]);
    }

    public function events(): JsonResponse
    {
        $events = SchoolEvent::query()
            ->withSum(['expenses as committed_expenses' => fn ($query) => $query->whereIn('status', ['approved', 'paid'])], 'amount')
            ->withCount(['expenses as pending_expense_count' => fn ($query) => $query->where('status', 'pending')])
            ->withCount(['expenses as approved_expense_count' => fn ($query) => $query->whereIn('status', ['approved', 'paid'])])
            ->withSum(['expenses as pending_amount' => fn ($query) => $query->where('status', 'pending')], 'amount')
            ->withSum(['expenses as rejected_amount' => fn ($query) => $query->where('status', 'rejected')], 'amount')
            ->with(['expenses' => fn ($query) => $query->latest('spent_on')->latest('id')])
            ->latest('starts_on')->limit(500)->get();
        $events->each(function (SchoolEvent $event) {
            $event->setAttribute('committed_expenses', $event->committed_expenses ?? '0.00');
            $event->setAttribute('budget_overrun', (float) $event->committed_expenses > (float) $event->budget);
        });

        return response()->json(['events' => $events]);
    }

    public function createEvent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'category' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:5000'],
            'venue' => ['nullable', 'string', 'max:180'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'budget' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
        ]);
        $event = SchoolEvent::query()->create($data + [
            'status' => 'planned',
            'created_by' => $request->user()->id,
        ]);
        AuditLog::record('operations.event_created', $event, [
            'starts_on' => $event->starts_on->toDateString(),
            'ends_on' => $event->ends_on->toDateString(),
            'budget' => $event->budget,
        ]);

        return response()->json(['data' => $event], 201);
    }

    public function updateEvent(Request $request, SchoolEvent $event): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:180'],
            'category' => ['sometimes', 'nullable', 'string', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'venue' => ['sometimes', 'nullable', 'string', 'max:180'],
            'starts_on' => ['sometimes', 'required', 'date'],
            'ends_on' => ['sometimes', 'required', 'date'],
            'budget' => ['sometimes', 'required', 'numeric', 'min:0', 'max:99999999.99'],
            'status' => ['sometimes', 'required', 'in:planned,in_progress,completed,cancelled'],
        ]);
        $startsOn = $data['starts_on'] ?? $event->starts_on->toDateString();
        $endsOn = $data['ends_on'] ?? $event->ends_on->toDateString();
        if ($endsOn < $startsOn) {
            throw ValidationException::withMessages(['ends_on' => ['The event end date must be on or after the start date.']]);
        }
        if (in_array($event->status, ['completed', 'cancelled'], true)) {
            throw ValidationException::withMessages(['status' => ['Completed or cancelled events are terminal.']]);
        }
        if (($data['status'] ?? null) === 'cancelled' && $event->expenses()->whereIn('status', ['pending', 'approved', 'paid'])->exists()) {
            throw ValidationException::withMessages(['status' => ['Resolve pending and committed expenses before cancelling this event.']]);
        }
        $event->update($data);
        AuditLog::record('operations.event_updated', $event, $data);

        return response()->json(['data' => $event->refresh()]);
    }

    private function assertActiveEmployee(?int $employeeId): void
    {
        if ($employeeId && Employee::query()->whereKey($employeeId)->where('status', 'active')->doesntExist()) {
            throw ValidationException::withMessages(['custodian_employee_id' => ['Choose an active employee in this school.']]);
        }
    }

    private function sumAmount($query, string $status): string
    {
        return number_format((float) (clone $query)->where('status', $status)->sum('amount'), 2, '.', '');
    }
}
