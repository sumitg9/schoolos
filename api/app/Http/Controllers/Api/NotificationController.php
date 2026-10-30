<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\NotificationDelivery;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => NotificationDelivery::query()->latest()->paginate(max(1, min($request->integer('per_page', 50), 100))),
        ]);
    }

    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'channel' => ['required', Rule::in(['email', 'sms', 'whatsapp'])],
            'recipient' => ['required', 'string', 'max:255'],
            'subject' => ['nullable', 'required_if:channel,email', 'string', 'max:180'],
            'message' => ['required', 'string', 'max:5000'],
        ]);
        if ($data['channel'] === 'email' && ! filter_var($data['recipient'], FILTER_VALIDATE_EMAIL)) {
            return response()->json(['message' => 'Enter a valid email address.'], 422);
        }
        if ($data['channel'] !== 'email' && ! preg_match('/^\+[1-9]\d{7,14}$/', $data['recipient'])) {
            return response()->json(['message' => 'Enter the phone number in international E.164 format, for example +15551234567.'], 422);
        }

        $delivery = NotificationDelivery::query()->create([
            'channel' => $data['channel'],
            'recipient' => $data['recipient'],
            'subject' => $data['subject'] ?? null,
            'message' => $data['message'],
            'status' => 'pending',
            'sent_by' => $request->user()->id,
        ]);
        try {
            if ($data['channel'] === 'email') {
                Mail::raw($data['message'], function ($mail) use ($data) {
                    $mail->to($data['recipient'])->subject($data['subject']);
                });
                $providerReference = null;
            } else {
                $sid = config('services.twilio.account_sid');
                $token = config('services.twilio.auth_token');
                $from = config('services.twilio.'.($data['channel'] === 'whatsapp' ? 'from_whatsapp' : 'from_sms'));
                if (! $sid || ! $token || ! $from) {
                    $delivery->update(['status' => 'failed', 'failure_reason' => 'Twilio credentials or sender number are not configured.']);

                    return response()->json(['message' => 'This notification channel is not configured. Contact the system administrator.'], 503);
                }
                $to = $data['channel'] === 'whatsapp' ? 'whatsapp:'.$data['recipient'] : $data['recipient'];
                $fromAddress = $data['channel'] === 'whatsapp' && ! str_starts_with($from, 'whatsapp:')
                    ? 'whatsapp:'.$from
                    : $from;
                $response = Http::asForm()
                    ->withBasicAuth($sid, $token)
                    ->timeout(15)
                    ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                        'To' => $to,
                        'From' => $fromAddress,
                        'Body' => $data['message'],
                    ]);
                $response->throw();
                $providerReference = $response->json('sid');
            }
        } catch (ConnectionException|RequestException|TransportExceptionInterface $exception) {
            Log::warning('Notification provider delivery failed.', [
                'channel' => $delivery->channel,
                'delivery_id' => $delivery->id,
                'exception' => $exception->getMessage(),
            ]);
            $delivery->update(['status' => 'failed', 'failure_reason' => 'The configured provider could not deliver this notification.']);
            AuditLog::record('notification.delivery_failed', $delivery, ['channel' => $delivery->channel]);

            return response()->json(['message' => 'The notification provider could not deliver this message. Check provider configuration and try again.'], 502);
        }

        $delivery->update([
            'status' => 'sent',
            'provider_reference' => $providerReference,
            'sent_at' => now(),
        ]);
        AuditLog::record('notification.sent', $delivery, ['channel' => $delivery->channel]);

        return response()->json(['data' => $delivery], 201);
    }
}
