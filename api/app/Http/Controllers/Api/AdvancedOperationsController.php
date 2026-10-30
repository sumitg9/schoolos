<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\LibraryBook;
use App\Models\LibraryLoan;
use App\Models\Student;
use App\Models\TransportAssignment;
use App\Models\TransportRoute;
use App\Models\TransportVehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdvancedOperationsController extends Controller
{
    public function library(): JsonResponse
    {
        return response()->json([
            'books' => LibraryBook::query()->withCount(['loans as active_loans' => fn ($query) => $query->where('status', 'issued')])->orderBy('title')->get(),
            'loans' => LibraryLoan::query()->with(['book:id,title,isbn', 'student:id,admission_number,first_name,last_name', 'employee:id,employee_id,first_name,last_name'])
                ->latest('issued_on')->limit(200)->get(),
            'students' => Student::query()->where('status', 'active')->orderBy('first_name')->get(['id', 'admission_number', 'first_name', 'last_name']),
            'employees' => Employee::query()->where('status', 'active')->orderBy('first_name')->get(['id', 'employee_id', 'first_name', 'last_name']),
        ]);
    }

    public function createBook(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'author' => ['nullable', 'string', 'max:180'],
            'isbn' => ['nullable', 'string', 'max:32'],
            'category' => ['nullable', 'string', 'max:100'],
            'total_copies' => ['required', 'integer', 'min:1', 'max:32767'],
        ]);
        if (! empty($data['isbn']) && LibraryBook::query()->where('isbn', $data['isbn'])->exists()) {
            throw ValidationException::withMessages(['isbn' => ['This ISBN already exists in this school library.']]);
        }
        $book = LibraryBook::query()->create($data + ['available_copies' => $data['total_copies']]);
        AuditLog::record('library.book_created', $book, ['total_copies' => $book->total_copies]);

        return response()->json(['data' => $book], 201);
    }

    public function issueBook(Request $request): JsonResponse
    {
        $data = $request->validate([
            'book_id' => ['required', 'integer', 'exists:library_books,id'],
            'student_id' => ['nullable', 'integer', 'exists:students,id', 'required_without:employee_id'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id', 'required_without:student_id'],
            'issued_on' => ['required', 'date'],
            'due_on' => ['required', 'date', 'after_or_equal:issued_on'],
        ]);
        if (isset($data['student_id']) === isset($data['employee_id'])) {
            throw ValidationException::withMessages(['student_id' => ['Choose exactly one student or employee borrower.']]);
        }

        $loan = DB::transaction(function () use ($data, $request) {
            $book = LibraryBook::query()->lockForUpdate()->findOrFail($data['book_id']);
            if ($book->available_copies < 1) {
                throw ValidationException::withMessages(['book_id' => ['No copies of this book are currently available.']]);
            }
            if (isset($data['student_id']) && Student::query()->whereKey($data['student_id'])->where('status', 'active')->doesntExist()) {
                throw ValidationException::withMessages(['student_id' => ['Choose an active student in this school.']]);
            }
            if (isset($data['employee_id']) && Employee::query()->whereKey($data['employee_id'])->where('status', 'active')->doesntExist()) {
                throw ValidationException::withMessages(['employee_id' => ['Choose an active employee in this school.']]);
            }
            $book->decrement('available_copies');

            return LibraryLoan::query()->create($data + ['status' => 'issued', 'issued_by' => $request->user()->id]);
        });
        AuditLog::record('library.book_issued', $loan, ['book_id' => $loan->book_id]);

        return response()->json(['data' => $loan->load(['book', 'student', 'employee'])], 201);
    }

    public function returnBook(LibraryLoan $loan): JsonResponse
    {
        DB::transaction(function () use ($loan) {
            $lockedLoan = LibraryLoan::query()->lockForUpdate()->findOrFail($loan->id);
            if ($lockedLoan->status !== 'issued') {
                throw ValidationException::withMessages(['loan' => ['This loan has already been returned or closed.']]);
            }
            $lockedLoan->update(['status' => 'returned', 'returned_on' => today()]);
            LibraryBook::query()->whereKey($lockedLoan->book_id)->increment('available_copies');
        });
        AuditLog::record('library.book_returned', $loan);

        return response()->json(['data' => $loan->refresh()->load('book')]);
    }

    public function transport(): JsonResponse
    {
        return response()->json([
            'vehicles' => TransportVehicle::query()->with('driver:id,employee_id,first_name,last_name')->orderBy('registration_number')->get(),
            'routes' => TransportRoute::query()->with('vehicle:id,registration_number,capacity,status')->withCount(['assignments as active_students' => fn ($query) => $query->where('status', 'active')])->orderBy('name')->get(),
            'assignments' => TransportAssignment::query()->with(['route:id,name,monthly_fee', 'student:id,admission_number,first_name,last_name'])->where('status', 'active')->latest()->limit(500)->get(),
            'students' => Student::query()->where('status', 'active')->orderBy('first_name')->get(['id', 'admission_number', 'first_name', 'last_name']),
            'employees' => Employee::query()->where('status', 'active')->orderBy('first_name')->get(['id', 'employee_id', 'first_name', 'last_name']),
        ]);
    }

    public function createVehicle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'registration_number' => ['required', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:150'],
            'capacity' => ['required', 'integer', 'min:1', 'max:32767'],
            'driver_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
        ]);
        if (TransportVehicle::query()->where('registration_number', $data['registration_number'])->exists()) {
            throw ValidationException::withMessages(['registration_number' => ['This vehicle registration already exists in this school.']]);
        }
        if (! empty($data['driver_employee_id']) && Employee::query()->whereKey($data['driver_employee_id'])->where('status', 'active')->doesntExist()) {
            throw ValidationException::withMessages(['driver_employee_id' => ['Choose an active employee from this school.']]);
        }
        $vehicle = TransportVehicle::query()->create($data + ['status' => 'active']);
        AuditLog::record('transport.vehicle_created', $vehicle);

        return response()->json(['data' => $vehicle->load('driver')], 201);
    }

    public function updateVehicle(Request $request, TransportVehicle $vehicle): JsonResponse
    {
        $data = $request->validate([
            'description' => ['sometimes', 'nullable', 'string', 'max:150'],
            'capacity' => ['sometimes', 'required', 'integer', 'min:1', 'max:32767'],
            'driver_employee_id' => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
            'status' => ['sometimes', 'required', 'in:active,maintenance,inactive'],
        ]);
        if (array_key_exists('driver_employee_id', $data) && $data['driver_employee_id']
            && Employee::query()->whereKey($data['driver_employee_id'])->where('status', 'active')->doesntExist()) {
            throw ValidationException::withMessages(['driver_employee_id' => ['Choose an active employee from this school.']]);
        }
        $vehicle = DB::transaction(function () use ($vehicle, $data) {
            $lockedVehicle = TransportVehicle::query()->lockForUpdate()->findOrFail($vehicle->id);
            $activeRiders = TransportAssignment::query()->where('status', 'active')
                ->whereHas('route', fn ($query) => $query->where('vehicle_id', $lockedVehicle->id))
                ->count();
            if (isset($data['capacity']) && $data['capacity'] < $activeRiders) {
                throw ValidationException::withMessages(['capacity' => ['Capacity cannot be lower than the number of currently assigned students.']]);
            }
            if (($data['status'] ?? null) !== null && $data['status'] !== 'active' && $activeRiders > 0) {
                throw ValidationException::withMessages(['status' => ['End active student transport assignments before taking this vehicle out of service.']]);
            }
            $lockedVehicle->update($data);

            return $lockedVehicle;
        });
        AuditLog::record('transport.vehicle_updated', $vehicle, $data);

        return response()->json(['data' => $vehicle->refresh()->load('driver')]);
    }

    public function createRoute(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'stops' => ['required', 'string', 'max:5000'],
            'monthly_fee' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'vehicle_id' => ['nullable', 'integer', 'exists:transport_vehicles,id'],
        ]);
        if (! empty($data['vehicle_id']) && TransportVehicle::query()->whereKey($data['vehicle_id'])->where('status', 'active')->doesntExist()) {
            throw ValidationException::withMessages(['vehicle_id' => ['Choose an active vehicle from this school.']]);
        }
        if (TransportRoute::query()->where('name', $data['name'])->exists()) {
            throw ValidationException::withMessages(['name' => ['A route with this name already exists in this school.']]);
        }
        $route = TransportRoute::query()->create($data + ['status' => 'active']);
        AuditLog::record('transport.route_created', $route);

        return response()->json(['data' => $route->load('vehicle')], 201);
    }

    public function updateRoute(Request $request, TransportRoute $route): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'stops' => ['sometimes', 'required', 'string', 'max:5000'],
            'monthly_fee' => ['sometimes', 'required', 'numeric', 'min:0', 'max:99999999.99'],
            'vehicle_id' => ['sometimes', 'nullable', 'integer', 'exists:transport_vehicles,id'],
            'status' => ['sometimes', 'required', 'in:active,inactive'],
        ]);
        if (isset($data['name']) && TransportRoute::query()->where('name', $data['name'])->where('id', '!=', $route->id)->exists()) {
            throw ValidationException::withMessages(['name' => ['A route with this name already exists in this school.']]);
        }
        if (array_key_exists('vehicle_id', $data) && $data['vehicle_id']) {
            $vehicle = TransportVehicle::query()->whereKey($data['vehicle_id'])->where('status', 'active')->first();
            if (! $vehicle) {
                throw ValidationException::withMessages(['vehicle_id' => ['Choose an active vehicle from this school.']]);
            }
        }
        $route = DB::transaction(function () use ($route, $data) {
            $lockedRoute = TransportRoute::query()->lockForUpdate()->findOrFail($route->id);
            $riders = TransportAssignment::query()->where('transport_route_id', $lockedRoute->id)->where('status', 'active')->count();
            if ($riders && (($data['status'] ?? null) === 'inactive'
                || (array_key_exists('vehicle_id', $data) && $data['vehicle_id'] !== $lockedRoute->vehicle_id))) {
                throw ValidationException::withMessages(['status' => ['End active student assignments before deactivating this route or changing its vehicle.']]);
            }
            if (isset($data['vehicle_id']) && $data['vehicle_id']) {
                $vehicle = TransportVehicle::query()->lockForUpdate()->findOrFail($data['vehicle_id']);
                $otherRiders = TransportAssignment::query()->where('status', 'active')
                    ->whereHas('route', fn ($query) => $query->where('vehicle_id', $vehicle->id))
                    ->when($lockedRoute->vehicle_id === $vehicle->id, fn ($query) => $query->where('transport_route_id', '!=', $lockedRoute->id))
                    ->count();
                if ($otherRiders + $riders > $vehicle->capacity) {
                    throw ValidationException::withMessages(['vehicle_id' => ['The selected vehicle does not have enough capacity for these active assignments.']]);
                }
            }
            $lockedRoute->update($data);

            return $lockedRoute;
        });
        AuditLog::record('transport.route_updated', $route, $data);

        return response()->json(['data' => $route->refresh()->load('vehicle')]);
    }

    public function assignTransport(Request $request): JsonResponse
    {
        $data = $request->validate([
            'transport_route_id' => ['required', 'integer', 'exists:transport_routes,id'],
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'pickup_point' => ['nullable', 'string', 'max:180'],
            'starts_on' => ['required', 'date'],
        ]);
        $assignment = DB::transaction(function () use ($data) {
            $student = Student::query()->lockForUpdate()->findOrFail($data['student_id']);
            if ($student->status !== 'active') {
                throw ValidationException::withMessages(['student_id' => ['Choose an active student in this school.']]);
            }
            if (TransportAssignment::query()->where('student_id', $student->id)->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(['student_id' => ['This student already has an active transport assignment.']]);
            }
            $route = TransportRoute::query()->lockForUpdate()->with('vehicle')->findOrFail($data['transport_route_id']);
            if ($route->status !== 'active') {
                throw ValidationException::withMessages(['transport_route_id' => ['Choose an active route from this school.']]);
            }
            if ($route->vehicle_id) {
                $vehicle = TransportVehicle::query()->lockForUpdate()->findOrFail($route->vehicle_id);
                $activeRiders = TransportAssignment::query()
                    ->where('status', 'active')
                    ->whereHas('route', fn ($query) => $query->where('vehicle_id', $vehicle->id))
                    ->count();
                if ($activeRiders >= $vehicle->capacity) {
                    throw ValidationException::withMessages(['transport_route_id' => ['This vehicle has reached its passenger capacity.']]);
                }
            }

            return TransportAssignment::query()->create($data + ['status' => 'active']);
        });
        AuditLog::record('transport.student_assigned', $assignment);

        return response()->json(['data' => $assignment->load(['route', 'student'])], 201);
    }

    public function endTransportAssignment(TransportAssignment $assignment): JsonResponse
    {
        $assignment = DB::transaction(function () use ($assignment) {
            $lockedAssignment = TransportAssignment::query()->lockForUpdate()->findOrFail($assignment->id);
            if ($lockedAssignment->status !== 'active') {
                throw ValidationException::withMessages(['assignment' => ['Only active transport assignments can be ended.']]);
            }
            $lockedAssignment->update(['status' => 'ended', 'ends_on' => today()]);

            return $lockedAssignment;
        });
        AuditLog::record('transport.assignment_ended', $assignment);

        return response()->json(['data' => $assignment->refresh()->load(['route', 'student'])]);
    }

    public function inventory(): JsonResponse
    {
        return response()->json([
            'items' => InventoryItem::query()->withSum(['transactions as units_received' => fn ($query) => $query->where('type', 'received')], 'quantity')->orderBy('name')->get(),
            'transactions' => InventoryTransaction::query()->with('item:id,name,sku,unit')->latest()->limit(300)->get(),
        ]);
    }

    public function createInventoryItem(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'sku' => ['nullable', 'string', 'max:80'],
            'category' => ['nullable', 'string', 'max:100'],
            'unit' => ['required', 'string', 'max:30'],
            'quantity' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'reorder_level' => ['required', 'integer', 'min:0', 'max:4294967295'],
        ]);
        if (! empty($data['sku']) && InventoryItem::query()->where('sku', $data['sku'])->exists()) {
            throw ValidationException::withMessages(['sku' => ['This SKU already exists in this school.']]);
        }
        $item = DB::transaction(function () use ($data, $request) {
            $item = InventoryItem::query()->create($data);
            if ($item->quantity > 0) {
                $item->transactions()->create([
                    'type' => 'received',
                    'quantity' => $item->quantity,
                    'description' => 'Opening stock',
                    'recorded_by' => $request->user()->id,
                ]);
            }

            return $item;
        });
        AuditLog::record('inventory.item_created', $item);

        return response()->json(['data' => $item], 201);
    }

    public function adjustInventory(Request $request, InventoryItem $item): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['received', 'issued', 'adjustment'])],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'description' => ['nullable', 'string', 'max:180'],
        ]);
        $transaction = DB::transaction(function () use ($data, $item, $request) {
            $lockedItem = InventoryItem::query()->lockForUpdate()->findOrFail($item->id);
            $quantity = (int) $data['quantity'];
            if ($data['type'] === 'issued' && $quantity > $lockedItem->quantity) {
                throw ValidationException::withMessages(['quantity' => ['Cannot issue more stock than is available.']]);
            }
            if ($data['type'] === 'received' || $data['type'] === 'adjustment') {
                $lockedItem->increment('quantity', $quantity);
            } else {
                $lockedItem->decrement('quantity', $quantity);
            }

            return $lockedItem->transactions()->create($data + ['recorded_by' => $request->user()->id]);
        });
        AuditLog::record('inventory.stock_adjusted', $transaction, ['type' => $transaction->type, 'quantity' => $transaction->quantity]);

        return response()->json(['data' => $transaction->load('item')], 201);
    }
}
