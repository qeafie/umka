<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use App\Models\MaxNotification;
use App\Models\User;
use App\Services\IncidentResponses;
use App\Services\Max\IncidentNotifications;
use App\Services\Max\MaxBotClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MaxWebhookController extends Controller
{
    public function __invoke(Request $request, IncidentNotifications $notifications, IncidentResponses $responses, MaxBotClient $client): JsonResponse
    {
        $secret = config('services.max.webhook_secret');
        abort_unless(is_string($secret) && $secret !== '' && hash_equals($secret, (string) $request->header('X-Max-Bot-Api-Secret')), 401);
        if ($request->input('update_type') === 'bot_stopped') {
            $maxId = $request->input('user.user_id');
            if (is_int($maxId) || (is_string($maxId) && ctype_digit($maxId))) {
                $user = User::query()->where('max_user_id', (string) $maxId)->first();
                if ($user !== null) {
                    DB::transaction(function () use ($user): void {
                        DB::table('house_memberships')->where('user_id', $user->id)->update(['notifications_enabled' => false]);
                        MaxNotification::query()->where('user_id', $user->id)->whereIn('status', ['pending', 'sent', 'sending', 'uncertain'])->update(['status' => 'cancelled']);
                    });
                }
            }

            return response()->json(['ok' => true]);
        }
        if ($request->input('update_type') !== 'message_callback') {
            return response()->json(['ok' => true]);
        }
        $callbackId = $request->input('callback.callback_id');
        $maxId = $request->input('callback.user.user_id');
        $payload = $request->input('callback.payload');
        if (! is_string($callbackId) || $callbackId === '' || strlen($callbackId) > 256 || ! (is_int($maxId) || (is_string($maxId) && ctype_digit($maxId))) || ! is_string($payload) || ! preg_match('/^umka:([a-f0-9]{32}):(problem_present|service_working|cannot_check|restored|problem_remains)$/', $payload, $match)) {
            return response()->json(['ok' => true]);
        }
        $delivery = MaxNotification::query()->where('token', $match[1])->first();
        if ($delivery === null) {
            return response()->json(['ok' => true]);
        }
        $result = DB::transaction(function () use ($delivery, $callbackId, $maxId, $match, $notifications, $responses): string {
            $incident = Incident::query()->whereKey($delivery->incident_id)->lockForUpdate()->firstOrFail();
            $resident = $notifications->eligible($delivery, $incident);
            $delivery = MaxNotification::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            $recipient = User::query()->find($delivery->user_id);
            if ($recipient === null || (string) $recipient->max_user_id !== (string) $maxId) {
                return 'invalid';
            }
            $receiptId = hash('sha256', $callbackId);
            $previous = DB::table('max_callback_receipts')->where('id', $receiptId)->value('result');
            if ($previous !== null) {
                return $previous;
            }
            $answers = $delivery->kind === 'scope' ? ['problem_present', 'service_working', 'cannot_check'] : ['restored', 'problem_remains', 'cannot_check'];
            $valid = $resident !== null && in_array($delivery->status, ['sent', 'sending', 'uncertain'], true) && in_array($delivery->kind, ['scope', 'recovery'], true) && in_array($match[2], $answers, true);
            if ($valid) {
                $responses->record($incident->house, $incident, $resident, $delivery->kind, $match[2], $delivery->round);
                $delivery->update(['status' => 'sent', 'sent_at' => $delivery->sent_at ?? now(), 'last_error' => null]);
            }
            $result = $valid ? 'saved' : 'expired';
            DB::table('max_callback_receipts')->insert(['id' => $receiptId, 'result' => $result, 'created_at' => now(), 'updated_at' => now()]);

            return $result;
        });
        if ($result === 'invalid') {
            return response()->json(['ok' => true]);
        }
        $text = $result === 'saved' ? 'Ответ сохранён. Спасибо! Изменить ответ можно в Умке.' : 'Этот опрос больше недоступен. Откройте Умку для актуальной информации.';
        try {
            $response = $client->post('/answers', ['callback_id' => $callbackId], ['message' => ['text' => $text, 'attachments' => []]]);
            if (! $response->successful() || $response->json('success') !== true) {
                return response()->json(['ok' => false], 503);
            }
        } catch (ConnectionException) {
            return response()->json(['ok' => false], 503);
        }

        return response()->json(['ok' => true]);
    }
}
