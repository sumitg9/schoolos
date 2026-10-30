<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Employee::query()
            ->with('user:id,name,email')
            ->withCount('assignments')
            ->latest('id');

        if ($request->filled('search')) {
            $term = '%'.mb_strtolower(trim($request->string('search')->toString())).'%';
            $query->where(function (Builder $query) use ($term) {
                $query->whereRaw('LOWER(first_name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(last_name, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(employee_id) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(email, \'\')) LIKE ?', [$term]);
            });
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }
        if ($request->filled('department')) {
            $query->where('department', $request->string('department')->toString());
        }

        return response()->json($query->paginate(max(1, min($request->integer('per_page', 20), 100))));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $employee = Employee::query()->create($data + ['created_by' => $request->user()->id]);
        $employee->forceFill(['employee_id' => 'EMP-'.str_pad((string) $employee->id, 6, '0', STR_PAD_LEFT)])->save();
        AuditLog::record('employee.created', $employee, [
            'employee_id' => $employee->employee_id,
            'is_teaching_staff' => $employee->is_teaching_staff,
        ]);

        return response()->json(['data' => $employee->load('user:id,name,email')], 201);
    }

    public function show(Employee $employee): JsonResponse
    {
        return response()->json([
            'data' => $employee->load([
                'user:id,name,email',
                'assignments' => fn ($query) => $query->with([
                    'academicSession:id,name',
                    'schoolClass:id,name',
                    'section:id,name',
                    'subject:id,name,code',
                ])->latest(),
                'documents:id,employee_id,category,original_name,mime_type,size_bytes,created_at',
            ]),
        ]);
    }

    public function update(Request $request, Employee $employee): JsonResponse
    {
        $data = $this->validated($request, $employee);
        $employee->update($data);
        AuditLog::record('employee.updated', $employee, ['fields' => array_keys($data)]);

        return response()->json(['data' => $employee->refresh()->load('user:id,name,email')]);
    }

    public function destroy(Employee $employee): JsonResponse
    {
        $employee->update(['status' => 'inactive']);
        AuditLog::record('employee.deactivated', $employee);

        return response()->json(['message' => 'Employee deactivated; employment and assignment history has been preserved.']);
    }

    public function export()
    {
        $employees = Employee::query()->orderBy('employee_id')->get();
        AuditLog::record('employees.exported', changes: ['record_count' => $employees->count()]);

        return response()->streamDownload(function () use ($employees) {
            $output = fopen('php://output', 'w');
            fputcsv($output, [
                'employee_id',
                'first_name',
                'last_name',
                'email',
                'phone',
                'department',
                'designation',
                'employment_type',
                'is_teaching_staff',
                'joining_date',
                'status',
            ]);
            foreach ($employees as $employee) {
                fputcsv($output, [
                    $employee->employee_id,
                    $employee->first_name,
                    $employee->last_name,
                    $employee->email,
                    $employee->phone,
                    $employee->department,
                    $employee->designation,
                    $employee->employment_type,
                    $employee->is_teaching_staff ? 'true' : 'false',
                    $employee->joining_date?->toDateString(),
                    $employee->status,
                ]);
            }
            fclose($output);
        }, 'employees.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function importTemplate()
    {
        return response()->streamDownload(function () {
            $output = fopen('php://output', 'w');
            fputcsv($output, [
                'first_name',
                'last_name',
                'email',
                'phone',
                'department',
                'designation',
                'employment_type',
                'is_teaching_staff',
                'joining_date',
                'qualification',
                'experience_years',
            ]);
            fclose($output);
        }, 'employee-import-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function import(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:csv,txt'],
        ]);
        $handle = fopen($data['file']->getRealPath(), 'rb');
        if ($handle === false) {
            throw ValidationException::withMessages(['file' => ['The uploaded CSV could not be read.']]);
        }

        $created = 0;
        $errors = [];
        try {
            $headers = fgetcsv($handle, null, ',', '"', '\\');
            $headers = is_array($headers)
                ? array_map(fn ($header) => mb_strtolower(trim((string) $header)), $headers)
                : [];
            $requiredHeaders = ['first_name', 'employment_type', 'is_teaching_staff', 'joining_date'];
            if (array_diff($requiredHeaders, $headers)) {
                throw ValidationException::withMessages([
                    'file' => ['CSV must include: '.implode(', ', $requiredHeaders).'.'],
                ]);
            }

            $rowNumber = 1;
            while (($values = fgetcsv($handle, null, ',', '"', '\\')) !== false) {
                $rowNumber++;
                if (count(array_filter($values, fn ($value) => trim((string) $value) !== '')) === 0) {
                    continue;
                }
                if ($rowNumber > 501) {
                    $errors[] = ['row' => $rowNumber, 'message' => 'Import limit is 500 rows per file.'];
                    break;
                }
                $row = [];
                foreach ($headers as $index => $header) {
                    $value = isset($values[$index]) ? trim((string) $values[$index]) : '';
                    $row[$header] = $value === '' ? null : $value;
                }
                $validator = Validator::make($row, [
                    'first_name' => ['required', 'string', 'max:100'],
                    'last_name' => ['nullable', 'string', 'max:100'],
                    'email' => ['nullable', 'email', 'max:255', 'unique:employees,email'],
                    'phone' => ['nullable', 'string', 'max:40'],
                    'department' => ['nullable', 'string', 'max:150'],
                    'designation' => ['nullable', 'string', 'max:150'],
                    'employment_type' => ['required', Rule::in(['full_time', 'part_time', 'contract', 'temporary'])],
                    'is_teaching_staff' => ['required', Rule::in(['true', 'false', '1', '0'])],
                    'joining_date' => ['required', 'date'],
                    'qualification' => ['nullable', 'string', 'max:255'],
                    'experience_years' => ['nullable', 'integer', 'min:0', 'max:100'],
                ]);
                if ($validator->fails()) {
                    $errors[] = ['row' => $rowNumber, 'message' => $validator->errors()->first()];

                    continue;
                }

                $profile = array_intersect_key($row, array_flip([
                    'first_name', 'last_name', 'email', 'phone', 'department', 'designation',
                    'employment_type', 'joining_date', 'qualification', 'experience_years',
                ]));
                $profile['is_teaching_staff'] = in_array($row['is_teaching_staff'], ['true', '1'], true);
                try {
                    $employee = DB::transaction(function () use ($profile, $request) {
                        $employee = Employee::query()->create($profile + ['created_by' => $request->user()->id]);
                        $employee->forceFill(['employee_id' => 'EMP-'.str_pad((string) $employee->id, 6, '0', STR_PAD_LEFT)])->save();

                        return $employee;
                    });
                    AuditLog::record('employee.imported', $employee, ['employee_id' => $employee->employee_id]);
                    $created++;
                } catch (QueryException $exception) {
                    if ($exception->getCode() !== '23000' && $exception->getCode() !== '23505') {
                        throw $exception;
                    }
                    $errors[] = ['row' => $rowNumber, 'message' => 'A record with the supplied unique email already exists.'];
                }
            }
        } finally {
            fclose($handle);
        }

        AuditLog::record('employees.csv_imported', changes: [
            'created_count' => $created,
            'error_count' => count($errors),
        ]);

        return response()->json([
            'created_count' => $created,
            'error_count' => count($errors),
            'errors' => $errors,
        ]);
    }

    private function validated(Request $request, ?Employee $employee = null): array
    {
        return $request->validate([
            'user_id' => [
                'nullable',
                'integer',
                'exists:users,id',
                Rule::unique('employees', 'user_id')->ignore($employee?->id),
            ],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'gender' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('employees', 'email')->ignore($employee?->id)],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:2000'],
            'department' => ['nullable', 'string', 'max:150'],
            'designation' => ['nullable', 'string', 'max:150'],
            'employment_type' => ['required', Rule::in(['full_time', 'part_time', 'contract', 'temporary'])],
            'joining_date' => ['required', 'date'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'resigned', 'terminated'])],
            'is_teaching_staff' => ['required', 'boolean'],
            'qualification' => ['nullable', 'string', 'max:255'],
            'experience_years' => ['nullable', 'integer', 'min:0', 'max:100'],
            'emergency_contact_name' => ['nullable', 'string', 'max:150'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:40'],
        ]);
    }
}
