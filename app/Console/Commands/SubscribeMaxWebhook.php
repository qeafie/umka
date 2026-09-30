<?php

namespace App\Console\Commands;

use App\Services\Max\MaxBotClient;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;

class SubscribeMaxWebhook extends Command
{
    protected $signature = 'max:subscribe-webhook';

    protected $description = 'Register the configured HTTPS webhook with MAX';

    public function handle(MaxBotClient $client): int
    {
        $url = config('services.max.webhook_url');
        $secret = config('services.max.webhook_secret');
        if (! config('services.max.bot_token') || ! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https' || (parse_url($url, PHP_URL_PORT) ?? 443) !== 443 || ! is_string($secret) || ! preg_match('/^[a-zA-Z0-9_-]{5,256}$/', $secret)) {
            $this->error('Configure MAX_BOT_TOKEN, MAX_WEBHOOK_URL (HTTPS port 443) and MAX_WEBHOOK_SECRET.');

            return self::FAILURE;
        }
        try {
            $response = $client->post('/subscriptions', [], ['url' => $url, 'secret' => $secret, 'update_types' => ['message_callback', 'bot_stopped']]);
            if ($response->successful() && $response->json('success') === true) {
                $this->info('MAX webhook registered.');

                return self::SUCCESS;
            }
        } catch (ConnectionException) {
            $this->error('Could not connect to MAX.');

            return self::FAILURE;
        }
        $this->error('MAX rejected the subscription. Check configuration and TLS.');

        return self::FAILURE;
    }
}
