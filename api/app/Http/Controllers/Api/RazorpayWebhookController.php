<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\FeeCharge;
use App\Models\FeeInvoice;
use App\Models\FeePayment;
use App\Models\FeePaymentOrder;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RazorpayWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = config('services.razorpay.webhook_secret');
        $signature = $request->header('X-Razorpay-Signature');
        if (! is_string($secret) || $secret === '' || ! is_string($signature) || $signature === '') {
            return response()->json(['message' => 'Webhook verification is not configured.'], 503);
        }
        $expected = hash_hmac('sha256', $request->getContent(), $secret);
        if (! hash_equals($expected, $signature)) {
            Log::warning('Rejected Razorpay webhook with an invalid signature.');

            return response()->json(['message' => 'Invalid webhook signature.'], 401);
        }

        $event = $request->json('event');
        $payment = $request->json('payload.payment.entity');
        if (! is_array($payment)) {
            return response()->json(['message' => 'Webhook payment data is invalid.'], 422);
        }
        $providerOrderId = $payment['order_id'] ?? null;
        $providerPaymentId = $payment['id'] ?? null;
        if (! is_string($providerOrderId) || ! is_string($providerPaymentId)) {
            return response()->json(['message' => 'Webhook payment identifiers are missing.'], 422);
        }

        $order = FeePaymentOrder::query()->where('provider_order_id', $providerOrderId)->first();
        if (! $order) {
            Log::warning('Razorpay webhook references an unknown order.', ['provider_order_id' => $providerOrderId]);

            return response()->json(['message' => 'Payment order was not found.'], 404);
        }
        if (in_array($event, ['payment.captured', 'order.paid'], true)) {
            $amountCents = (int) round((float) $order->amount * 100);
            if (($payment['currency'] ?? null) !== $order->currency || (int) ($payment['amount'] ?? 0) !== $amountCents) {
                Log::error('Razorpay captured payment amount does not match the recorded order.', [
                    'provider_order_id' => $providerOrderId,
                    'provider_payment_id' => $providerPaymentId,
                ]);

                return response()->json(['message' => 'Payment amount does not match the order.'], 422);
            }

            $captureStatus = DB::transaction(function () use ($order, $providerPaymentId, $amountCents) {
                Student::query()->lockForUpdate()->findOrFail($order->student_id);
                $lockedOrder = FeePaymentOrder::query()->lockForUpdate()->findOrFail($order->id);
                if ($lockedOrder->status === 'paid') {
                    if ($lockedOrder->provider_payment_id !== $providerPaymentId) {
                        Log::warning('Duplicate Razorpay order payment was ignored.', ['provider_order_id' => $lockedOrder->provider_order_id]);
                    }

                    return 'duplicate';
                }
                if ($lockedOrder->status === 'review' && $lockedOrder->provider_payment_id === $providerPaymentId) {
                    return 'review';
                }
                if ($lockedOrder->fee_invoice_id) {
                    $invoice = FeeInvoice::query()->lockForUpdate()->findOrFail($lockedOrder->fee_invoice_id);
                    $invoiceCharges = (int) round((float) $invoice->charges()->where('status', 'posted')->sum('amount') * 100);
                    $invoicePayments = (int) round((float) $invoice->payments()->sum('amount') * 100);
                    $concession = (int) round((float) $invoice->concession_amount * 100);
                    $lateFee = $invoice->lateFeeIsDue()
                        ? (int) round((float) $invoice->late_fee_amount * 100)
                        : 0;
                    $outstandingCents = $invoiceCharges - $invoicePayments - $concession + $lateFee;
                } else {
                    $chargesCents = (int) round((float) FeeCharge::query()
                        ->where('student_id', $lockedOrder->student_id)
                        ->where('status', 'posted')
                        ->sum('amount') * 100);
                    $paymentsCents = (int) round((float) FeePayment::query()
                        ->where('student_id', $lockedOrder->student_id)
                        ->sum('amount') * 100);
                    $invoices = FeeInvoice::query()->where('student_id', $lockedOrder->student_id)->get();
                    $concessionCents = (int) round((float) $invoices->sum('concession_amount') * 100);
                    $lateFeesCents = $invoices->sum(function (FeeInvoice $invoice) {
                        return $invoice->lateFeeIsDue()
                            ? (int) round((float) $invoice->late_fee_amount * 100)
                            : 0;
                    });
                    $outstandingCents = $chargesCents - $paymentsCents - $concessionCents + $lateFeesCents;
                }
                if ($amountCents > $outstandingCents) {
                    $lockedOrder->update([
                        'provider_payment_id' => $providerPaymentId,
                        'status' => 'review',
                    ]);
                    AuditLog::record('fee.online_payment_needs_review', $lockedOrder, [
                        'student_id' => $lockedOrder->student_id,
                        'fee_invoice_id' => $lockedOrder->fee_invoice_id,
                        'amount' => $lockedOrder->amount,
                    ]);
                    Log::error('Captured Razorpay payment exceeds the current outstanding fee balance and needs reconciliation.', [
                        'provider_order_id' => $lockedOrder->provider_order_id,
                        'student_id' => $lockedOrder->student_id,
                    ]);

                    return 'review';
                }
                $lockedOrder->update([
                    'provider_payment_id' => $providerPaymentId,
                    'status' => 'paid',
                    'paid_at' => now(),
                ]);
                $feePayment = new FeePayment([
                    'student_id' => $lockedOrder->student_id,
                    'fee_invoice_id' => $lockedOrder->fee_invoice_id,
                    'amount' => $lockedOrder->amount,
                    'paid_at' => now(),
                    'method' => 'online',
                    'reference' => $providerPaymentId,
                    'remarks' => 'Razorpay online payment',
                    'recorded_by' => null,
                    'receipt_number' => 'RCPT-'.now()->format('Ymd').'-'.Str::upper(Str::random(10)),
                ]);
                $feePayment->school_id = $lockedOrder->school_id;
                $feePayment->save();
                AuditLog::record('fee.online_payment_captured', $lockedOrder, [
                    'student_id' => $lockedOrder->student_id,
                    'fee_payment_id' => $feePayment->id,
                    'amount' => $lockedOrder->amount,
                ]);

                return 'paid';
            });
            if ($captureStatus === 'review') {
                return response()->json(['received' => true, 'status' => 'review']);
            }
        } elseif ($event === 'payment.failed' && $order->status !== 'paid') {
            DB::transaction(function () use ($order) {
                $lockedOrder = FeePaymentOrder::query()->lockForUpdate()->findOrFail($order->id);
                if ($lockedOrder->status !== 'paid') {
                    $lockedOrder->update(['status' => 'failed']);
                }
            });
        }

        return response()->json(['received' => true]);
    }
}
