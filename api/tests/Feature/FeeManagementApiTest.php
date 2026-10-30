<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\FeeInvoice;
use App\Models\FeePayment;
use App\Models\FeePaymentOrder;
use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FeeManagementApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        $admin = User::factory()->create();
        $admin->roles()->sync([Role::query()->where('name', 'super-admin')->value('id')]);
        $this->actingAs($admin);
    }

    public function test_fee_structure_issues_invoices_with_concessions_late_fees_and_receipts(): void
    {
        [$student, $session, $schoolClass] = $this->enrolledStudent();
        $structure = $this->postJson('/api/fees/structures', [
            'academic_session_id' => $session->id,
            'school_class_id' => $schoolClass->id,
            'name' => 'Term One',
            'late_fee_amount' => 25,
            'items' => [
                ['description' => 'Tuition', 'amount' => 80],
                ['description' => 'Activities', 'amount' => 20],
            ],
        ])->assertCreated()->json('data');

        $dueOn = today()->subDay()->toDateString();
        $this->postJson("/api/fees/structures/{$structure['id']}/invoices", ['due_on' => $dueOn])
            ->assertCreated()
            ->assertJsonPath('issued', 1);
        $invoice = FeeInvoice::query()->firstOrFail();

        $this->postJson("/api/fees/structures/{$structure['id']}/invoices", ['due_on' => $dueOn])
            ->assertCreated()
            ->assertJsonPath('issued', 0);

        $this->patchJson("/api/fees/invoices/{$invoice->id}/concession", [
            'amount' => 20,
            'reason' => 'Sibling discount',
        ])->assertOk()
            ->assertJsonPath('data.subtotal', '100.00')
            ->assertJsonPath('data.late_fee_amount', '25.00')
            ->assertJsonPath('data.total', '105.00');

        $payment = $this->postJson('/api/fees/payments', [
            'student_id' => $student->id,
            'fee_invoice_id' => $invoice->id,
            'amount' => 50,
            'paid_at' => today()->toDateString(),
            'method' => 'cash',
        ])->assertCreated()->json('data');
        $this->assertNotEmpty($payment['receipt_number']);

        $this->postJson('/api/fees/payments', [
            'student_id' => $student->id,
            'fee_invoice_id' => $invoice->id,
            'amount' => 56,
            'paid_at' => today()->toDateString(),
            'method' => 'cash',
        ])->assertUnprocessable()->assertJsonValidationErrors(['amount']);

        $this->getJson('/api/fees/receipts/'.$payment['id'])
            ->assertOk()
            ->assertJsonPath('data.receipt_number', $payment['receipt_number']);

        $this->getJson('/api/fees/reports?from='.today()->toDateString().'&to='.today()->toDateString())
            ->assertOk()
            ->assertJsonPath('collected', '50.00')
            ->assertJsonPath('outstanding_total', '55.00')
            ->assertJsonPath('students_with_dues', 1);

        $this->postJson('/api/fees/payments', [
            'student_id' => $student->id,
            'fee_invoice_id' => $invoice->id,
            'amount' => 55,
            'paid_at' => today()->toDateString(),
            'method' => 'cash',
        ])->assertCreated();

        $portalUser = User::factory()->create();
        $portalUser->roles()->sync([Role::query()->where('name', 'student')->value('id')]);
        $student->forceFill(['portal_user_id' => $portalUser->id])->save();
        $this->actingAs($portalUser)
            ->getJson("/api/portal/students/{$student->id}")
            ->assertOk()
            ->assertJsonPath('data.fees.balance', 0)
            ->assertJsonPath('data.fees.invoices.0.late_fee_amount', 25)
            ->assertJsonPath('data.fees.invoices.0.balance', 0)
            ->assertJsonPath('data.fees.invoices.0.status', 'paid');
        $this->getJson("/api/portal/receipts/{$payment['id']}")
            ->assertOk()
            ->assertJsonPath('data.receipt_number', $payment['receipt_number']);
    }

    public function test_fee_structure_rejects_a_class_from_another_academic_session(): void
    {
        [, $session, $schoolClass] = $this->enrolledStudent();
        $otherSession = AcademicSession::query()->create([
            'name' => '2025-2026',
            'starts_on' => '2025-04-01',
            'ends_on' => '2026-03-31',
            'is_active' => false,
        ]);

        $this->postJson('/api/fees/structures', [
            'academic_session_id' => $otherSession->id,
            'school_class_id' => $schoolClass->id,
            'name' => 'Invalid plan',
            'items' => [['description' => 'Tuition', 'amount' => 100]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['school_class_id']);

        $this->assertDatabaseCount('fee_structures', 0);
    }

    public function test_invoice_checkout_capture_records_an_invoice_linked_payment_and_receipt(): void
    {
        config()->set('services.razorpay.key_id', 'rzp_test_id');
        config()->set('services.razorpay.key_secret', 'rzp_test_secret');
        config()->set('services.razorpay.webhook_secret', 'test-webhook-secret');
        Http::fake(['api.razorpay.com/*' => Http::response(['id' => 'order_fee_invoice'], 200)]);
        [$student, $session, $schoolClass] = $this->enrolledStudent();
        $structure = $this->postJson('/api/fees/structures', [
            'academic_session_id' => $session->id,
            'school_class_id' => $schoolClass->id,
            'name' => 'Term One',
            'items' => [['description' => 'Tuition', 'amount' => 100]],
        ])->assertCreated()->json('data');
        $this->postJson("/api/fees/structures/{$structure['id']}/invoices", [
            'due_on' => today()->addMonth()->toDateString(),
        ])->assertCreated();
        $invoice = FeeInvoice::query()->firstOrFail();
        $portalUser = User::factory()->create(['school_id' => $student->school_id]);
        $portalUser->roles()->sync([Role::query()->where('name', 'student')->value('id')]);
        $student->forceFill(['portal_user_id' => $portalUser->id])->save();

        $this->actingAs($portalUser)
            ->postJson('/api/portal/payments/orders', [
                'student_id' => $student->id,
                'invoice_id' => $invoice->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.order_id', 'order_fee_invoice')
            ->assertJsonPath('data.amount', 10000);
        $order = FeePaymentOrder::query()->where('provider_order_id', 'order_fee_invoice')->firstOrFail();
        $this->assertSame($invoice->id, $order->fee_invoice_id);

        $payload = json_encode([
            'event' => 'payment.captured',
            'payload' => ['payment' => ['entity' => [
                'id' => 'pay_fee_invoice',
                'order_id' => $order->provider_order_id,
                'amount' => 10000,
                'currency' => 'INR',
            ]]],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $payload, 'test-webhook-secret');
        $this->call('POST', '/api/webhooks/razorpay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => $signature,
        ], $payload)->assertOk()->assertJsonPath('received', true);

        $payment = FeePayment::query()->where('fee_invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame('100.00', $payment->amount);
        $this->assertNotEmpty($payment->receipt_number);
    }

    private function enrolledStudent(): array
    {
        $session = AcademicSession::query()->create([
            'name' => '2026-2027',
            'starts_on' => '2026-04-01',
            'ends_on' => '2027-03-31',
            'is_active' => true,
        ]);
        $schoolClass = SchoolClass::query()->create([
            'academic_session_id' => $session->id,
            'name' => 'Class 4',
        ]);
        $section = Section::query()->create(['school_class_id' => $schoolClass->id, 'name' => 'A']);
        $student = Student::query()->forceCreate([
            'admission_number' => 'ADM-FEE-MGMT-1',
            'first_name' => 'Alex',
            'admission_date' => '2026-04-01',
            'status' => 'active',
        ]);
        StudentEnrollment::query()->create([
            'student_id' => $student->id,
            'academic_session_id' => $session->id,
            'school_class_id' => $schoolClass->id,
            'section_id' => $section->id,
            'enrolled_on' => '2026-04-01',
            'status' => 'active',
        ]);

        return [$student, $session, $schoolClass];
    }
}
