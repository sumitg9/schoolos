<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\AuditLog;
use App\Models\FeeCharge;
use App\Models\FeeInvoice;
use App\Models\FeePayment;
use App\Models\FeePaymentOrder;
use App\Models\FeeStructure;
use App\Models\FeeStructureItem;
use App\Models\Guardian;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FeeController extends Controller
{
    public function targets(): JsonResponse
    {
        return response()->json([
            'sessions' => AcademicSession::query()->orderByDesc('starts_on')->get(['id', 'name']),
            'classes' => SchoolClass::query()->orderBy('name')->get(['id', 'name', 'academic_session_id']),
        ]);
    }

    public function index(): JsonResponse
    {
        $students = Student::query()
            ->where('status', 'active')
            ->with(['enrollments' => fn ($query) => $query->where('status', 'active')
                ->with(['schoolClass:id,name', 'section:id,name'])])
            ->withSum(['feeCharges as total_charges' => fn ($query) => $query->where('status', 'posted')], 'amount')
            ->withSum('feePayments as total_payments', 'amount')
            ->orderBy('first_name')
            ->limit(1000)
            ->get(['id', 'admission_number', 'first_name', 'last_name']);

        return response()->json([
            'students' => $students,
            'charges' => FeeCharge::query()->with('student:id,admission_number,first_name,last_name')->latest()->limit(100)->get(),
            'payments' => FeePayment::query()->with('student:id,admission_number,first_name,last_name')->latest('paid_at')->limit(100)->get(),
            'payment_orders' => FeePaymentOrder::query()->with('student:id,admission_number,first_name,last_name')->latest()->limit(100)->get(),
            'structures' => FeeStructure::query()->with(['academicSession:id,name', 'schoolClass:id,name', 'items'])->latest()->limit(100)->get(),
            'invoices' => $this->invoiceRows(FeeInvoice::query()
                ->with(['student:id,admission_number,first_name,last_name', 'charges:id,fee_invoice_id,description,amount,due_on', 'payments:id,fee_invoice_id,amount'])
                ->latest()
                ->limit(200)
                ->get()),
        ]);
    }

    public function createStructure(Request $request): JsonResponse
    {
        $data = $request->validate([
            'academic_session_id' => ['required', 'integer', 'exists:academic_sessions,id'],
            'school_class_id' => ['required', 'integer', 'exists:school_classes,id'],
            'name' => ['required', 'string', 'max:180'],
            'late_fee_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.description' => ['required', 'string', 'max:180'],
            'items.*.amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99'],
        ]);
        $schoolClass = SchoolClass::query()->findOrFail($data['school_class_id']);
        if ((int) $schoolClass->academic_session_id !== (int) $data['academic_session_id']) {
            throw ValidationException::withMessages([
                'school_class_id' => ['The selected class does not belong to this academic session.'],
            ]);
        }

        $structure = DB::transaction(function () use ($data, $request, $schoolClass) {
            $structure = new FeeStructure([
                'academic_session_id' => $data['academic_session_id'],
                'school_class_id' => $data['school_class_id'],
                'name' => $data['name'],
                'late_fee_amount' => $data['late_fee_amount'] ?? 0,
                'created_by' => $request->user()->id,
            ]);
            $structure->school_id = $schoolClass->school_id ?? DB::table('schools')->value('id');
            $structure->save();
            foreach ($data['items'] as $item) {
                $structureItem = new FeeStructureItem($item);
                $structureItem->school_id = $structure->school_id;
                $structure->items()->save($structureItem);
            }

            return $structure->load(['academicSession:id,name', 'schoolClass:id,name', 'items']);
        });
        AuditLog::record('fee.structure_created', $structure, ['item_count' => count($data['items'])]);

        return response()->json(['data' => $structure], 201);
    }

    public function issueInvoices(Request $request, FeeStructure $structure): JsonResponse
    {
        $data = $request->validate(['due_on' => ['required', 'date']]);
        $structure->load('items');
        if ($structure->status !== 'active' || $structure->items->isEmpty()) {
            throw ValidationException::withMessages(['structure' => ['Choose an active fee structure with at least one item.']]);
        }

        $issued = DB::transaction(function () use ($data, $request, $structure) {
            $enrollments = StudentEnrollment::query()
                ->where('academic_session_id', $structure->academic_session_id)
                ->where('school_class_id', $structure->school_class_id)
                ->where('status', 'active')
                ->whereHas('student', fn ($query) => $query->where('status', 'active'))
                ->whereDoesntHave('student.feeInvoices', fn ($query) => $query->where('fee_structure_id', $structure->id))
                ->with('student:id')
                ->get();

            foreach ($enrollments as $enrollment) {
                $invoice = new FeeInvoice([
                    'invoice_number' => $this->referenceNumber('INV'),
                    'student_id' => $enrollment->student_id,
                    'student_enrollment_id' => $enrollment->id,
                    'fee_structure_id' => $structure->id,
                    'due_on' => $data['due_on'],
                    'late_fee_amount' => $structure->late_fee_amount,
                    'created_by' => $request->user()->id,
                ]);
                $invoice->school_id = $enrollment->school_id ?? $structure->school_id;
                $invoice->save();
                foreach ($structure->items as $item) {
                    $charge = new FeeCharge([
                        'student_id' => $enrollment->student_id,
                        'description' => $item->description,
                        'amount' => $item->amount,
                        'due_on' => $data['due_on'],
                        'status' => 'posted',
                        'created_by' => $request->user()->id,
                    ]);
                    $charge->school_id = $invoice->school_id;
                    $invoice->charges()->save($charge);
                }
            }

            return $enrollments->count();
        });
        AuditLog::record('fee.invoices_issued', $structure, ['invoice_count' => $issued, 'due_on' => $data['due_on']]);

        return response()->json(['message' => "{$issued} invoice(s) issued.", 'issued' => $issued], 201);
    }

    public function applyConcession(Request $request, FeeInvoice $invoice): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'reason' => ['nullable', 'string', 'max:180'],
        ]);

        DB::transaction(function () use ($data, $invoice) {
            $lockedInvoice = FeeInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $subtotalCents = (int) round((float) $lockedInvoice->charges()->where('status', 'posted')->sum('amount') * 100);
            $paidCents = (int) round((float) $lockedInvoice->payments()->sum('amount') * 100);
            $concessionCents = (int) round((float) $data['amount'] * 100);
            if ($concessionCents > $subtotalCents - $paidCents) {
                throw ValidationException::withMessages(['amount' => ['A concession cannot exceed the unpaid invoice balance.']]);
            }
            $lockedInvoice->update([
                'concession_amount' => number_format($concessionCents / 100, 2, '.', ''),
                'concession_reason' => $data['reason'] ?? null,
            ]);
        });
        AuditLog::record('fee.invoice_concession_applied', $invoice, [
            'amount' => $data['amount'],
            'reason' => $data['reason'] ?? null,
        ]);

        return response()->json([
            'data' => $this->invoiceRows(collect([
                FeeInvoice::query()->with(['student:id,admission_number,first_name,last_name', 'charges:id,fee_invoice_id,description,amount,due_on', 'payments:id,fee_invoice_id,amount'])->findOrFail($invoice->id),
            ]))->first(),
        ]);
    }

    public function reports(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $payments = FeePayment::query()
            ->when($data['from'] ?? null, fn ($query, $from) => $query->whereDate('paid_at', '>=', $from))
            ->when($data['to'] ?? null, fn ($query, $to) => $query->whereDate('paid_at', '<=', $to));
        $collected = (float) (clone $payments)->sum('amount');
        $paymentCount = (clone $payments)->count();
        $students = Student::query()
            ->where('status', 'active')
            ->withSum(['feeCharges as total_charges' => fn ($query) => $query->where('status', 'posted')], 'amount')
            ->withSum('feePayments as total_payments', 'amount')
            ->with(['feeInvoices.charges' => fn ($query) => $query->where('status', 'posted'), 'feeInvoices.payments'])
            ->orderBy('first_name')
            ->limit(1000)
            ->get(['id', 'admission_number', 'first_name', 'last_name']);
        $dues = $students->map(function (Student $student) {
            $baseBalanceCents = (int) round(((float) ($student->total_charges ?? 0) - (float) ($student->total_payments ?? 0)) * 100);
            $lateFeesCents = 0;
            foreach ($student->feeInvoices as $invoice) {
                if ($invoice->lateFeeIsDue()) {
                    $lateFeesCents += (int) round((float) $invoice->late_fee_amount * 100);
                }
            }
            $concessionsCents = (int) round((float) $student->feeInvoices->sum('concession_amount') * 100);
            $balanceCents = max(0, $baseBalanceCents - $concessionsCents + $lateFeesCents);

            return [
                'student' => $student,
                'outstanding' => number_format($balanceCents / 100, 2, '.', ''),
            ];
        })->filter(fn (array $row) => (float) $row['outstanding'] > 0)->values();

        return response()->json([
            'period' => ['from' => $data['from'] ?? null, 'to' => $data['to'] ?? null],
            'collected' => number_format($collected, 2, '.', ''),
            'payment_count' => $paymentCount,
            'outstanding_total' => number_format((float) $dues->sum(fn (array $row) => (float) $row['outstanding']), 2, '.', ''),
            'students_with_dues' => $dues->count(),
            'dues' => $dues,
        ]);
    }

    public function receipt(FeePayment $payment): JsonResponse
    {
        return response()->json([
            'data' => $payment->load([
                'student:id,admission_number,first_name,last_name',
                'feeInvoice:id,invoice_number,due_on,concession_amount,concession_reason',
            ]),
        ]);
    }

    public function paymentOrders(Request $request): JsonResponse
    {
        return response()->json([
            'data' => FeePaymentOrder::query()
                ->with('student:id,admission_number,first_name,last_name')
                ->latest()
                ->paginate(max(1, min($request->integer('per_page', 50), 100))),
        ]);
    }

    public function createCharge(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'description' => ['required', 'string', 'max:180'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99'],
            'due_on' => ['required', 'date'],
        ]);
        if (Student::query()->whereKey($data['student_id'])->where('status', 'active')->doesntExist()) {
            throw ValidationException::withMessages(['student_id' => ['Fees can only be charged to an active student.']]);
        }

        $charge = FeeCharge::query()->create($data + ['created_by' => $request->user()->id]);
        AuditLog::record('fee.charge_created', $charge, ['student_id' => $charge->student_id, 'amount' => $charge->amount]);

        return response()->json(['data' => $charge->load('student:id,admission_number,first_name,last_name')], 201);
    }

    public function createPayment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'fee_invoice_id' => ['nullable', 'integer', 'exists:fee_invoices,id'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99'],
            'paid_at' => ['required', 'date'],
            'method' => ['required', Rule::in(['cash', 'bank_transfer', 'card', 'cheque', 'online'])],
            'reference' => ['nullable', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $payment = DB::transaction(function () use ($data, $request) {
            $student = Student::query()->lockForUpdate()->findOrFail($data['student_id']);
            if ($student->status !== 'active') {
                throw ValidationException::withMessages(['student_id' => ['Payments can only be recorded for an active student.']]);
            }
            if (! empty($data['fee_invoice_id'])) {
                $invoice = FeeInvoice::query()->lockForUpdate()->findOrFail($data['fee_invoice_id']);
                if ($invoice->student_id !== $student->id) {
                    throw ValidationException::withMessages(['fee_invoice_id' => ['The invoice does not belong to this student.']]);
                }
                $outstandingCents = $this->invoiceBalanceCents($invoice);
            } else {
                $chargesCents = (int) round((float) FeeCharge::query()->where('student_id', $student->id)->where('status', 'posted')->sum('amount') * 100);
                $paymentsCents = (int) round((float) FeePayment::query()->where('student_id', $student->id)->sum('amount') * 100);
                $lateFeesCents = FeeInvoice::query()->where('student_id', $student->id)->get()
                    ->sum(fn (FeeInvoice $invoice) => $this->lateFeeDueCents($invoice));
                $concessionsCents = (int) round((float) FeeInvoice::query()->where('student_id', $student->id)->sum('concession_amount') * 100);
                $outstandingCents = $chargesCents - $paymentsCents - $concessionsCents + $lateFeesCents;
            }
            $paymentCents = (int) round((float) $data['amount'] * 100);
            if ($paymentCents > $outstandingCents) {
                throw ValidationException::withMessages(['amount' => ['Payment cannot exceed the student’s outstanding balance.']]);
            }

            $paymentData = $data;
            $paymentData['fee_invoice_id'] = $data['fee_invoice_id'] ?? null;
            unset($paymentData['invoice_id']);

            $payment = new FeePayment($paymentData + [
                'receipt_number' => $this->referenceNumber('RCPT'),
                'recorded_by' => $request->user()->id,
            ]);
            $payment->school_id = $student->school_id;
            $payment->save();

            return $payment;
        });
        AuditLog::record('fee.payment_recorded', $payment, [
            'student_id' => $payment->student_id,
            'amount' => $payment->amount,
            'method' => $payment->method,
        ]);

        return response()->json(['data' => $payment->load('student:id,admission_number,first_name,last_name')], 201);
    }

    public function createCheckoutOrder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'invoice_id' => ['nullable', 'integer', 'exists:fee_invoices,id'],
        ]);
        $student = $this->portalStudent($request->user(), $data['student_id']);
        $keyId = config('services.razorpay.key_id');
        $keySecret = config('services.razorpay.key_secret');
        if (! $keyId || ! $keySecret) {
            return response()->json(['message' => 'Online fee payment is not configured. Contact the school office.'], 503);
        }

        $invoice = null;
        if (! empty($data['invoice_id'])) {
            $invoice = FeeInvoice::query()->where('student_id', $student->id)->findOrFail($data['invoice_id']);
            $outstandingCents = $this->invoiceBalanceCents($invoice);
        } else {
            $chargesCents = (int) round((float) FeeCharge::query()->where('student_id', $student->id)->where('status', 'posted')->sum('amount') * 100);
            $paymentsCents = (int) round((float) FeePayment::query()->where('student_id', $student->id)->sum('amount') * 100);
            $lateFeesCents = FeeInvoice::query()->where('student_id', $student->id)->get()
                ->sum(fn (FeeInvoice $item) => $this->lateFeeDueCents($item));
            $concessionsCents = (int) round((float) FeeInvoice::query()->where('student_id', $student->id)->sum('concession_amount') * 100);
            $outstandingCents = $chargesCents - $paymentsCents - $concessionsCents + $lateFeesCents;
        }
        if ($outstandingCents <= 0) {
            throw ValidationException::withMessages(['student_id' => ['There is no outstanding fee balance for this student.']]);
        }

        try {
            $response = Http::withBasicAuth($keyId, $keySecret)
                ->acceptJson()
                ->timeout(15)
                ->post('https://api.razorpay.com/v1/orders', [
                    'amount' => $outstandingCents,
                    'currency' => 'INR',
                    'receipt' => 'fee-'.$student->id.'-'.bin2hex(random_bytes(6)),
                    'notes' => [
                        'student_id' => (string) $student->id,
                        'school_id' => (string) $student->school_id,
                    ],
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('Razorpay order creation could not reach the provider.', [
                'student_id' => $student->id,
                'exception' => $exception->getMessage(),
            ]);

            return response()->json(['message' => 'The payment provider is unavailable. Please try again later.'], 502);
        }
        if (! $response->successful()) {
            Log::error('Razorpay order creation failed.', [
                'student_id' => $student->id,
                'status' => $response->status(),
            ]);

            return response()->json(['message' => 'The payment provider could not create an order. Please try again later.'], 502);
        }

        $providerOrderId = $response->json('id');
        if (! is_string($providerOrderId) || $providerOrderId === '') {
            Log::error('Razorpay returned an invalid order response.', ['student_id' => $student->id]);

            return response()->json(['message' => 'The payment provider returned an invalid order. Please try again later.'], 502);
        }
        $order = new FeePaymentOrder([
            'student_id' => $student->id,
            'fee_invoice_id' => $invoice?->id,
            'provider' => 'razorpay',
            'provider_order_id' => $providerOrderId,
            'amount' => number_format($outstandingCents / 100, 2, '.', ''),
            'currency' => 'INR',
            'status' => 'created',
            'created_by' => $request->user()->id,
        ]);
        $order->school_id = $invoice?->school_id ?? $student->school_id ?? DB::table('schools')->value('id');
        $order->save();
        AuditLog::record('fee.online_order_created', $order, ['student_id' => $student->id, 'amount' => $order->amount]);

        return response()->json([
            'data' => [
                'order_id' => $order->provider_order_id,
                'amount' => $outstandingCents,
                'currency' => $order->currency,
                'key_id' => $keyId,
                'student_name' => trim($student->first_name.' '.($student->last_name ?? '')),
                'invoice_number' => $invoice?->invoice_number,
            ],
        ], 201);
    }

    private function invoiceRows($invoices)
    {
        return $invoices->map(function (FeeInvoice $invoice) {
            $subtotalCents = (int) round((float) $invoice->charges->sum('amount') * 100);
            $paidCents = (int) round((float) $invoice->payments->sum('amount') * 100);
            $concessionCents = (int) round((float) $invoice->concession_amount * 100);
            $lateFeeCents = $this->lateFeeDueCents($invoice);
            $totalCents = $subtotalCents - $concessionCents + $lateFeeCents;
            $balanceCents = max(0, $totalCents - $paidCents);

            return [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'student' => $invoice->student,
                'fee_structure_id' => $invoice->fee_structure_id,
                'due_on' => $invoice->due_on->toDateString(),
                'charges' => $invoice->charges,
                'subtotal' => number_format($subtotalCents / 100, 2, '.', ''),
                'concession_amount' => number_format($concessionCents / 100, 2, '.', ''),
                'concession_reason' => $invoice->concession_reason,
                'late_fee_amount' => number_format($lateFeeCents / 100, 2, '.', ''),
                'total' => number_format($totalCents / 100, 2, '.', ''),
                'paid' => number_format($paidCents / 100, 2, '.', ''),
                'balance' => number_format($balanceCents / 100, 2, '.', ''),
                'status' => $balanceCents === 0 ? 'paid' : ($paidCents > 0 ? 'partial' : ($invoice->due_on->isBefore(today()) ? 'overdue' : 'unpaid')),
            ];
        });
    }

    private function invoiceBalanceCents(FeeInvoice $invoice): int
    {
        $invoice->loadMissing(['charges' => fn ($query) => $query->where('status', 'posted'), 'payments']);
        $chargesCents = (int) round((float) $invoice->charges->sum('amount') * 100);
        $paymentsCents = (int) round((float) $invoice->payments->sum('amount') * 100);
        $concessionCents = (int) round((float) $invoice->concession_amount * 100);

        return max(0, $chargesCents - $paymentsCents - $concessionCents + $this->lateFeeDueCents($invoice));
    }

    private function lateFeeDueCents(FeeInvoice $invoice): int
    {
        if (! $invoice->lateFeeIsDue()) {
            return 0;
        }
        $chargesCents = (int) round((float) $invoice->charges()->where('status', 'posted')->sum('amount') * 100);
        $paymentsCents = (int) round((float) $invoice->payments()->sum('amount') * 100);
        $concessionCents = (int) round((float) $invoice->concession_amount * 100);

        return $chargesCents - $paymentsCents - $concessionCents > 0
            ? (int) round((float) $invoice->late_fee_amount * 100)
            : 0;
    }

    private function referenceNumber(string $prefix): string
    {
        return $prefix.'-'.now()->format('Ymd').'-'.Str::upper(Str::random(10));
    }

    private function portalStudent(User $user, int $studentId): Student
    {
        $isStudent = $user->roles()->where('name', 'student')->exists();
        $isParent = $user->roles()->where('name', 'parent')->exists();
        abort_unless($isStudent || $isParent, 403);
        $query = Student::query()->whereKey($studentId);
        if ($isStudent) {
            $query->where('portal_user_id', $user->id);
        } else {
            $guardianId = Guardian::query()->where('portal_user_id', $user->id)->value('id');
            $query->whereHas('guardians', fn ($guardians) => $guardians->whereKey($guardianId ?? 0));
        }

        return $query->firstOrFail();
    }
}
