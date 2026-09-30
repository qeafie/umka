<?php

namespace App\Services\Max;

use App\Models\Incident;
use App\Models\MaxNotification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;

class MaxDeliveryService
{
    public function __construct(private IncidentNotifications $notifications, private MaxBotClient $client) {}

    public function deliver(int $id): void
    {
        if (! config('services.max.bot_token')) {
            return;
        }
        $claim = DB::transaction(function () use ($id): ?array {
            $candidate = MaxNotification::query()->find($id);
            if ($candidate === null) {
                return null;
            }
            $incident = Incident::query()->whereKey($candidate->incident_id)->lockForUpdate()->first();
            $resident = $incident ? $this->notifications->eligible($candidate, $incident) : null;
            $delivery = MaxNotification::query()->whereKey($id)->lockForUpdate()->first();
            if ($delivery === null || $delivery->status !== 'pending' || $delivery->available_at->isFuture()) {
                return null;
            }
            if ($resident === null || ($delivery->kind !== 'status' && $this->notifications->hasFreshAnswer($incident, $resident, $delivery->kind))) {
                $delivery->update(['status' => 'cancelled']);

                return null;
            }
            $delivery->update(['status' => 'sending', 'attempts' => $delivery->attempts + 1]);

            return [$delivery, $resident->max_user_id, $this->body($delivery, $incident)];
        });
        if ($claim === null) {
            return;
        }
        [$delivery, $maxUserId, $body] = $claim;
        // Persist the claim before the network call: a killed worker must not blindly resend.
        try {
            $response = $this->client->post('/messages', ['user_id' => $maxUserId], $body);
        } catch (ConnectionException) {
            MaxNotification::query()->whereKey($id)->where('status', 'sending')->update(['status' => 'uncertain', 'last_error' => 'connection']);

            return;
        }
        if ($response->successful() && is_string($response->json('message.body.mid'))) {
            MaxNotification::query()->whereKey($id)->where('status', 'sending')->update(['status' => 'sent', 'sent_at' => now(), 'last_error' => null]);
        } elseif ($response->status() === 429 || $response->serverError()) {
            MaxNotification::query()->whereKey($id)->where('status', 'sending')->update(['status' => $delivery->attempts >= 5 ? 'failed' : 'pending', 'last_error' => 'http_'.$response->status(), 'available_at' => now()->addSeconds(60 * (2 ** ($delivery->attempts - 1)))]);
        } else {
            MaxNotification::query()->whereKey($id)->where('status', 'sending')->update(['status' => $response->successful() ? 'uncertain' : 'failed', 'last_error' => 'http_'.$response->status()]);
        }
    }

    private function body(MaxNotification $delivery, Incident $incident): array
    {
        $title = 'Умка · '.$incident->house->name.' · проблема №'.$incident->id.' · '.$incident->location;
        if ($delivery->kind === 'status') {
            return ['text' => $title."\nСтатус: в работе.\nСледующий шаг: ".$incident->next_action."\nСледующее обновление: ".$incident->next_update_at?->toIso8601String()];
        }
        $answers = $delivery->kind === 'scope'
            ? ['problem_present' => 'Тоже есть проблема', 'service_working' => 'Всё работает', 'cannot_check' => 'Не могу проверить']
            : ['restored' => 'Восстановилось', 'problem_remains' => 'Проблема осталась', 'cannot_check' => 'Не могу проверить'];
        $question = $delivery->kind === 'scope' ? 'Соседи сообщили о проблеме. Как у вас сейчас?' : 'Диспетчер отметил завершение работ. Услуга восстановилась?';
        $buttons = [];
        foreach ($answers as $answer => $label) {
            $buttons[] = [['type' => 'callback', 'text' => $label, 'payload' => 'umka:'.$delivery->token.':'.$answer]];
        }

        return ['text' => $title."\n".$question."\nОтказаться от уведомлений можно в разделе «Проблемы в доме».", 'attachments' => [['type' => 'inline_keyboard', 'payload' => ['buttons' => $buttons]]]];
    }
}
