<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ExamSchedule;
use App\Models\FeeInvoice;
use App\Models\FeePayment;
use App\Models\FeePaymentOrder;
use App\Models\Guardian;
use App\Models\PortalActivationCode;
use App\Models\Role;
use App\Models\SchoolNotice;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\StudentExamMark;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PortalController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'min:20', 'max:64'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);
        $codeHash = hash('sha256', Str::upper(trim($data['code'])));

        $user = DB::transaction(function () use ($data, $codeHash) {
            $activation = PortalActivationCode::query()->where('code_hash', $codeHash)->lockForUpdate()->first();
            if (! $activation || $activation->redeemed_at || $activation->expires_at->isPast()) {
                throw ValidationException::withMessages(['code' => ['This activation code is invalid, expired, or already used.']]);
            }

            if ($activation->target_type === 'student') {
                $target = Student::query()->lockForUpdate()->find($activation->target_id);
                $roleName = 'student';
            } elseif ($activation->target_type === 'guardian') {
                $target = Guardian::query()->lockForUpdate()->find($activation->target_id);
                $roleName = 'parent';
            } else {
                $target = null;
                $roleName = '';
            }

            if (! $target || $target->portal_user_id) {
                throw ValidationException::withMessages(['code' => ['This activation code is invalid, expired, or already used.']]);
            }
            if ($activation->target_type === 'student' && $target->status !== 'active') {
                throw ValidationException::withMessages(['code' => ['Only an active student can activate a portal account.']]);
            }
            if ($target->email && strcasecmp($target->email, $data['email']) !== 0) {
                throw ValidationException::withMessages(['email' => ['Use the email address recorded by the school for this account.']]);
            }

            $user = User::query()->create([
                'name' => trim($target->first_name.' '.($target->last_name ?? '')),
                'email' => $data['email'],
                'password' => $data['password'],
                'is_active' => true,
                'school_id' => $target->school_id,
            ]);
            $user->roles()->sync([Role::query()->where('name', $roleName)->firstOrFail()->id]);
            $target->forceFill(['portal_user_id' => $user->id])->save();
            $activation->update(['redeemed_at' => now()]);

            return $user;
        });

        AuditLog::record('portal.account_registered', $user, ['role' => $user->roles()->value('name')]);

        return response()->json(['message' => 'Portal account created. You can now sign in.'], 201);
    }

    public function activationCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'target_type' => ['required', 'in:student,guardian'],
            'target_id' => ['required', 'integer', 'min:1'],
        ]);
        $target = $data['target_type'] === 'student'
            ? Student::query()->findOrFail($data['target_id'])
            : Guardian::query()->findOrFail($data['target_id']);
        if ($target->portal_user_id) {
            throw ValidationException::withMessages(['target_id' => ['This record already has a portal account.']]);
        }
        if ($data['target_type'] === 'student' && ($target->status !== 'active' || ! $target->enrollments()->where('status', 'active')->exists())) {
            throw ValidationException::withMessages(['target_id' => ['Only currently enrolled students can receive an activation code.']]);
        }
        if ($data['target_type'] === 'guardian' && ! $target->students()->where('students.status', 'active')->exists()) {
            throw ValidationException::withMessages(['target_id' => ['The guardian must be linked to an active student.']]);
        }

        $code = Str::upper(Str::random(32));
        $activation = PortalActivationCode::query()->create([
            'target_type' => $data['target_type'],
            'target_id' => $target->id,
            'code_hash' => hash('sha256', $code),
            'created_by' => $request->user()->id,
            'expires_at' => now()->addDays(30),
        ]);
        AuditLog::record('portal.activation_code_created', $activation, [
            'target_type' => $activation->target_type,
            'target_id' => $activation->target_id,
        ]);

        return response()->json([
            'data' => [
                'code' => $code,
                'target_type' => $activation->target_type,
                'expires_at' => $activation->expires_at,
            ],
        ], 201);
    }

    public function children(Request $request): JsonResponse
    {
        $user = $request->user();
        $isStudent = $user->roles()->where('name', 'student')->exists();
        $isParent = $user->roles()->where('name', 'parent')->exists();
        abort_unless($isStudent || $isParent, 403);

        $students = $isStudent
            ? Student::query()->where('portal_user_id', $user->id)->get()
            : $user->portalGuardian?->students()->where('students.status', 'active')->get() ?? collect();

        return response()->json([
            'data' => $students->map(fn (Student $student) => [
                'id' => $student->id,
                'name' => trim($student->first_name.' '.($student->last_name ?? '')),
                'admission_number' => $student->admission_number,
            ])->values(),
        ]);
    }

    public function student(Request $request, int $student): JsonResponse
    {
        $record = $this->accessibleStudent($request->user(), $student);
        $enrollments = StudentEnrollment::query()
            ->where('student_id', $record->id)
            ->with(['academicSession:id,name', 'schoolClass:id,name', 'section:id,name'])
            ->orderByDesc('enrolled_on')
            ->get();
        $attendance = DB::table('student_attendance')
            ->join('student_enrollments', 'student_enrollments.id', '=', 'student_attendance.student_enrollment_id')
            ->where('student_enrollments.student_id', $record->id)
            ->orderByDesc('student_attendance.attendance_date')
            ->limit(300)
            ->get([
                'student_attendance.attendance_date',
                'student_attendance.status',
                'student_attendance.remarks',
            ]);
        $charges = DB::table('fee_charges')->where('student_id', $record->id)->where('status', 'posted')->orderBy('due_on')->get(['id', 'description', 'amount', 'due_on']);
        $payments = DB::table('fee_payments')->where('student_id', $record->id)->orderByDesc('paid_at')->get([
            'id', 'amount', 'paid_at', 'method', 'reference', 'remarks', 'receipt_number', 'fee_invoice_id',
        ]);
        $invoices = FeeInvoice::query()->where('student_id', $record->id)
            ->with(['charges' => fn ($query) => $query->where('status', 'posted'), 'payments'])
            ->orderByDesc('created_at')
            ->get();
        $paymentOrders = FeePaymentOrder::query()->where('student_id', $record->id)->latest()->limit(20)->get([
            'id', 'provider_order_id', 'amount', 'currency', 'status', 'created_at', 'paid_at',
        ]);
        $totalCharges = (float) $charges->sum(fn ($charge) => (float) $charge->amount);
        $totalPayments = (float) $payments->sum(fn ($payment) => (float) $payment->amount);
        $totalConcessions = (float) $invoices->sum(fn (FeeInvoice $invoice) => (float) $invoice->concession_amount);
        $lateFees = (float) $invoices->sum(function (FeeInvoice $invoice) {
            return $invoice->lateFeeIsDue()
                ? (float) $invoice->late_fee_amount
                : 0;
        });
        $invoiceRows = $invoices->map(function (FeeInvoice $invoice) {
            $subtotal = (float) $invoice->charges->sum('amount');
            $paid = (float) $invoice->payments->sum('amount');
            $concession = (float) $invoice->concession_amount;
            $lateFee = $invoice->lateFeeIsDue()
                ? (float) $invoice->late_fee_amount
                : 0;
            $total = $subtotal - $concession + $lateFee;
            $balance = max(0, $total - $paid);

            return [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'due_on' => $invoice->due_on->toDateString(),
                'subtotal' => round($subtotal, 2),
                'concession_amount' => round($concession, 2),
                'late_fee_amount' => round($lateFee, 2),
                'total' => round($total, 2),
                'paid' => round($paid, 2),
                'balance' => round($balance, 2),
                'status' => $balance <= 0 ? 'paid' : ($paid > 0 ? 'partial' : ($invoice->due_on->isBefore(today()) ? 'overdue' : 'unpaid')),
                'charges' => $invoice->charges->map(fn ($charge) => [
                    'description' => $charge->description,
                    'amount' => $charge->amount,
                ]),
            ];
        });
        $reportCards = $this->reportCards($enrollments);
        $audience = $request->user()->roles()->where('name', 'parent')->exists() ? 'parents' : 'students';
        $announcements = SchoolNotice::query()
            ->where('status', 'published')
            ->whereIn('audience', ['all', $audience])
            ->where(fn ($query) => $query->whereNull('publish_at')->orWhere('publish_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest()
            ->limit(50)
            ->get(['id', 'title', 'body', 'publish_at', 'created_at']);

        return response()->json([
            'data' => [
                'student' => [
                    'id' => $record->id,
                    'admission_number' => $record->admission_number,
                    'first_name' => $record->first_name,
                    'last_name' => $record->last_name,
                    'date_of_birth' => $record->date_of_birth,
                    'gender' => $record->gender,
                    'admission_date' => $record->admission_date,
                    'status' => $record->status,
                    'enrollments' => $enrollments,
                ],
                'attendance' => $attendance,
                'fees' => [
                    'total_charges' => round($totalCharges, 2),
                    'total_payments' => round($totalPayments, 2),
                    'balance' => round($totalCharges - $totalPayments - $totalConcessions + $lateFees, 2),
                    'charges' => $charges,
                    'payments' => $payments,
                    'invoices' => $invoiceRows,
                    'payment_orders' => $paymentOrders,
                    'online_payment_enabled' => (bool) (config('services.razorpay.key_id') && config('services.razorpay.key_secret')),
                ],
                'report_cards' => $reportCards,
                'announcements' => $announcements,
            ],
        ]);
    }

    public function receipt(Request $request, int $payment): JsonResponse
    {
        $record = FeePayment::query()->findOrFail($payment);
        $this->accessibleStudent($request->user(), $record->student_id);

        return response()->json([
            'data' => $record->load([
                'student:id,admission_number,first_name,last_name',
                'feeInvoice:id,invoice_number,due_on,concession_amount,concession_reason',
            ]),
        ]);
    }

    private function accessibleStudent(User $user, int $studentId): Student
    {
        $isStudent = $user->roles()->where('name', 'student')->exists();
        $isParent = $user->roles()->where('name', 'parent')->exists();
        abort_unless($isStudent || $isParent, 403);

        $query = Student::query()->whereKey($studentId);
        if ($isStudent) {
            $query->where('portal_user_id', $user->id);
        } else {
            $guardianId = $user->portalGuardian?->id;
            $query->whereHas('guardians', fn (Builder $guardians) => $guardians->whereKey($guardianId ?? 0));
        }

        return $query->firstOrFail();
    }

    private function reportCards($enrollments): array
    {
        $cards = [];
        foreach ($enrollments as $enrollment) {
            $exams = ExamSchedule::query()
                ->where('academic_session_id', $enrollment->academic_session_id)
                ->where('school_class_id', $enrollment->school_class_id)
                ->where('status', 'published')
                ->where('results_status', 'published')
                ->with(['subjects.subject:id,name,code'])
                ->orderByDesc('starts_on')
                ->get();
            foreach ($exams as $exam) {
                $marks = StudentExamMark::query()
                    ->where('student_enrollment_id', $enrollment->id)
                    ->whereIn('exam_schedule_subject_id', $exam->subjects->pluck('id'))
                    ->get()
                    ->keyBy('exam_schedule_subject_id');
                $subjects = $exam->subjects->map(function ($subject) use ($marks) {
                    $mark = $marks->get($subject->id);

                    return [
                        'subject' => $subject->subject,
                        'maximum_marks' => (float) $subject->maximum_marks,
                        'passing_marks' => (float) $subject->passing_marks,
                        'marks_obtained' => $mark ? (float) $mark->marks_obtained : null,
                        'remarks' => $mark?->remarks,
                    ];
                });
                $complete = $exam->subjects->isNotEmpty() && $marks->count() === $exam->subjects->count();
                $maximum = (float) $exam->subjects->sum(fn ($subject) => (float) $subject->maximum_marks);
                $obtained = (float) $marks->sum(fn ($mark) => (float) $mark->marks_obtained);
                $percentage = $complete && $maximum > 0 ? round($obtained / $maximum * 100, 2) : null;
                $passed = $complete && $subjects->every(fn ($subject) => $subject['marks_obtained'] >= $subject['passing_marks']);
                $cards[] = [
                    'exam_id' => $exam->id,
                    'title' => $exam->title,
                    'starts_on' => $exam->starts_on,
                    'academic_session' => $enrollment->academicSession?->name,
                    'school_class' => $enrollment->schoolClass?->name,
                    'section' => $enrollment->section?->name,
                    'subjects' => $subjects,
                    'total_obtained' => $complete ? $obtained : null,
                    'total_maximum' => $maximum,
                    'percentage' => $percentage,
                    'grade' => $percentage === null ? null : $this->grade($percentage, $exam->grading_scale),
                    'result' => ! $complete ? 'PENDING' : ($passed ? 'PASS' : 'NEEDS IMPROVEMENT'),
                    'results_status' => $exam->results_status,
                ];
            }
        }

        return $cards;
    }

    private function grade(float $percentage, ?array $gradingScale): string
    {
        $defaultGradingScale = [
            ['label' => 'A+', 'min_percentage' => 90],
            ['label' => 'A', 'min_percentage' => 80],
            ['label' => 'B', 'min_percentage' => 70],
            ['label' => 'C', 'min_percentage' => 60],
            ['label' => 'D', 'min_percentage' => 50],
            ['label' => 'F', 'min_percentage' => 0],
        ];
        foreach ($gradingScale ?: $defaultGradingScale as $grade) {
            if ($percentage >= (float) $grade['min_percentage']) {
                return $grade['label'];
            }
        }

        return 'F';
    }
}
