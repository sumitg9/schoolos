<?php

namespace Tests\Feature;

use App\Models\FeeCharge;
use App\Models\FeePayment;
use App\Models\FeePaymentOrder;
use App\Models\Guardian;
use App\Models\InventoryItem;
use App\Models\LibraryBook;
use App\Models\LibraryLoan;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdvancedOperationsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        $schoolId = School::query()->firstOrCreate(
            ['slug' => 'test-school'],
            ['name' => 'Test School', 'status' => 'active'],
        )->id;
        $admin = User::factory()->create(['school_id' => $schoolId]);
        $admin->roles()->sync([Role::query()->where('name', 'super-admin')->value('id')]);
        $this->actingAs($admin);
    }

    public function test_library_circulation_returns_a_book_and_restores_available_stock(): void
    {
        $student = $this->student('LIBRARY-1');
        $bookId = $this->postJson('/api/library/books', [
            'title' => 'The School Library',
            'total_copies' => 1,
        ])->assertCreated()->json('data.id');

        $loanId = $this->postJson('/api/library/loans', [
            'book_id' => $bookId,
            'student_id' => $student->id,
            'issued_on' => '2026-10-30',
            'due_on' => '2026-11-06',
        ])->assertCreated()->json('data.id');

        $this->assertSame(0, LibraryBook::query()->findOrFail($bookId)->available_copies);
        $this->postJson("/api/library/loans/{$loanId}/return")
            ->assertOk()
            ->assertJsonPath('data.status', 'returned');
        $this->assertSame(1, LibraryBook::query()->findOrFail($bookId)->available_copies);
        $this->assertSame('returned', LibraryLoan::query()->findOrFail($loanId)->status);
    }

    public function test_inventory_rejects_issuing_more_than_current_stock(): void
    {
        $itemId = $this->postJson('/api/inventory', [
            'name' => 'Exercise books',
            'unit' => 'pack',
            'quantity' => 4,
            'reorder_level' => 1,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/inventory/{$itemId}/transactions", [
            'type' => 'issued',
            'quantity' => 5,
        ])->assertUnprocessable()->assertJsonValidationErrors(['quantity']);
        $this->postJson("/api/inventory/{$itemId}/transactions", [
            'type' => 'issued',
            'quantity' => 3,
        ])->assertCreated();

        $this->assertSame(1, InventoryItem::query()->findOrFail($itemId)->quantity);
    }

    public function test_transport_respects_vehicle_capacity_and_keeps_ended_history(): void
    {
        $vehicleId = $this->postJson('/api/transport/vehicles', [
            'registration_number' => 'BUS-001',
            'capacity' => 1,
        ])->assertCreated()->json('data.id');
        $routeId = $this->postJson('/api/transport/routes', [
            'name' => 'North route',
            'stops' => 'Main gate, North Road',
            'monthly_fee' => 75,
            'vehicle_id' => $vehicleId,
        ])->assertCreated()->json('data.id');
        $otherRouteId = $this->postJson('/api/transport/routes', [
            'name' => 'South route',
            'stops' => 'Main gate, South Road',
            'monthly_fee' => 75,
            'vehicle_id' => $vehicleId,
        ])->assertCreated()->json('data.id');
        $firstStudent = $this->student('TRANSPORT-1');
        $secondStudent = $this->student('TRANSPORT-2');
        $firstAssignmentId = $this->postJson('/api/transport/assignments', [
            'student_id' => $firstStudent->id,
            'transport_route_id' => $routeId,
            'starts_on' => '2026-11-01',
        ])->assertCreated()->json('data.id');
        $this->postJson('/api/transport/assignments', [
            'student_id' => $secondStudent->id,
            'transport_route_id' => $otherRouteId,
            'starts_on' => '2026-11-01',
        ])->assertUnprocessable()->assertJsonValidationErrors(['transport_route_id']);

        $this->postJson("/api/transport/assignments/{$firstAssignmentId}/end")->assertOk()
            ->assertJsonPath('data.status', 'ended');
        $this->postJson('/api/transport/assignments', [
            'student_id' => $secondStudent->id,
            'transport_route_id' => $otherRouteId,
            'starts_on' => '2026-11-01',
        ])->assertCreated();
    }

    public function test_razorpay_webhook_records_a_verified_capture_once(): void
    {
        config()->set('services.razorpay.webhook_secret', 'test-webhook-secret');
        $student = $this->student('PAYMENT-1');
        $schoolId = $student->school_id;
        $charge = new FeeCharge([
            'student_id' => $student->id,
            'description' => 'Tuition',
            'amount' => 500,
            'due_on' => '2026-11-01',
            'status' => 'posted',
        ]);
        $charge->school_id = $schoolId;
        $charge->save();
        $order = new FeePaymentOrder([
            'student_id' => $student->id,
            'provider' => 'razorpay',
            'provider_order_id' => 'order_test_1',
            'amount' => '500.00',
            'currency' => 'INR',
            'status' => 'created',
        ]);
        $order->school_id = $schoolId;
        $order->save();
        $payload = json_encode([
            'event' => 'payment.captured',
            'payload' => ['payment' => ['entity' => [
                'id' => 'pay_test_1',
                'order_id' => $order->provider_order_id,
                'amount' => 50000,
                'currency' => 'INR',
            ]]],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $this->postSignedWebhook($payload)->assertOk()->assertJsonPath('received', true);
        $this->postSignedWebhook($payload)->assertOk()->assertJsonPath('received', true);

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(1, FeePayment::query()->where('student_id', $student->id)->count());
        $this->assertSame('500.00', FeePayment::query()->where('student_id', $student->id)->value('amount'));

        $duplicateOrder = new FeePaymentOrder([
            'student_id' => $student->id,
            'provider' => 'razorpay',
            'provider_order_id' => 'order_test_2',
            'amount' => '500.00',
            'currency' => 'INR',
            'status' => 'created',
        ]);
        $duplicateOrder->school_id = $schoolId;
        $duplicateOrder->save();
        $overpaymentPayload = json_encode([
            'event' => 'payment.captured',
            'payload' => ['payment' => ['entity' => [
                'id' => 'pay_test_2',
                'order_id' => $duplicateOrder->provider_order_id,
                'amount' => 50000,
                'currency' => 'INR',
            ]]],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->postSignedWebhook($overpaymentPayload)->assertOk()->assertJsonPath('status', 'review');
        $this->assertSame('review', $duplicateOrder->fresh()->status);
        $this->assertSame(1, FeePayment::query()->where('student_id', $student->id)->count());
    }

    public function test_twilio_delivery_is_logged_as_sent(): void
    {
        config()->set('services.twilio.account_sid', 'AC-test');
        config()->set('services.twilio.auth_token', 'test-token');
        config()->set('services.twilio.from_sms', '+15550000000');
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM-test'], 201)]);

        $this->postJson('/api/notifications', [
            'channel' => 'sms',
            'recipient' => '+15551234567',
            'message' => 'School closes at 3 PM.',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.provider_reference', 'SM-test');

        Http::assertSent(fn ($request) => $request->url() === 'https://api.twilio.com/2010-04-01/Accounts/AC-test/Messages.json'
            && $request['To'] === '+15551234567'
            && $request['Body'] === 'School closes at 3 PM.');
    }

    public function test_portal_user_can_create_a_razorpay_order_only_for_a_linked_student(): void
    {
        config()->set('services.razorpay.key_id', 'rzp_test_id');
        config()->set('services.razorpay.key_secret', 'rzp_test_secret');
        Http::fake(['api.razorpay.com/*' => Http::response(['id' => 'order_test_portal'], 200)]);
        $student = $this->student('PORTAL-PAYMENT-1');
        $charge = new FeeCharge([
            'student_id' => $student->id,
            'description' => 'Tuition',
            'amount' => 725,
            'due_on' => '2026-11-01',
            'status' => 'posted',
        ]);
        $charge->school_id = $student->school_id;
        $charge->save();
        $parent = User::factory()->create(['school_id' => $student->school_id]);
        $parent->roles()->sync([Role::query()->where('name', 'parent')->value('id')]);
        $guardian = new Guardian([
            'first_name' => 'Alex',
            'last_name' => 'Parent',
            'phone' => '+15551234567',
            'email' => $parent->email,
        ]);
        $guardian->school_id = $student->school_id;
        $guardian->portal_user_id = $parent->id;
        $guardian->save();
        $student->guardians()->attach($guardian->id, ['relationship' => 'Parent', 'is_primary' => true]);
        $this->assertDatabaseHas('student_guardian', [
            'student_id' => $student->id,
            'guardian_id' => $guardian->id,
            'school_id' => $student->school_id,
        ]);

        $this->actingAs($parent)->postJson('/api/portal/payments/orders', ['student_id' => $student->id])
            ->assertCreated()
            ->assertJsonPath('data.order_id', 'order_test_portal')
            ->assertJsonPath('data.amount', 72500)
            ->assertJsonPath('data.key_id', 'rzp_test_id');
        Http::assertSent(fn ($request) => $request->url() === 'https://api.razorpay.com/v1/orders'
            && $request['amount'] === 72500
            && $request['currency'] === 'INR');

        $unrelatedStudent = $this->student('PORTAL-PAYMENT-OTHER');
        $this->postJson('/api/portal/payments/orders', ['student_id' => $unrelatedStudent->id])->assertNotFound();
    }

    public function test_school_data_is_not_visible_to_another_school_context(): void
    {
        $currentSchoolId = auth()->user()->school_id;
        $otherSchool = School::query()->create([
            'name' => 'Second School',
            'slug' => 'second-school',
            'status' => 'active',
        ]);
        $foreignBook = new LibraryBook(['title' => 'Other school title', 'total_copies' => 1, 'available_copies' => 1]);
        $foreignBook->school_id = $otherSchool->id;
        $foreignBook->save();
        $ownBook = new LibraryBook(['title' => 'Current school title', 'total_copies' => 1, 'available_copies' => 1]);
        $ownBook->school_id = $currentSchoolId;
        $ownBook->save();

        $this->getJson('/api/library')->assertOk()
            ->assertJsonCount(1, 'books')
            ->assertJsonPath('books.0.title', 'Current school title');
        $this->postJson("/api/library/loans/{$foreignBook->id}/return")->assertNotFound();
    }

    private function student(string $admissionNumber): Student
    {
        return Student::query()->forceCreate([
            'school_id' => School::query()->firstOrCreate(
                ['slug' => 'test-school'],
                ['name' => 'Test School', 'status' => 'active'],
            )->id,
            'admission_number' => $admissionNumber,
            'first_name' => 'Jamie',
            'admission_date' => '2026-04-01',
            'status' => 'active',
        ]);
    }

    private function postSignedWebhook(string $payload)
    {
        return $this->call('POST', '/api/webhooks/razorpay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $payload, 'test-webhook-secret'),
        ], $payload);
    }
}
