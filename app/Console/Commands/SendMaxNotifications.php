<?php

namespace App\Console\Commands;

use App\Models\MaxNotification;
use App\Services\Max\MaxDeliveryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SendMaxNotifications extends Command
{
    protected $signature = 'max:send-notifications {--limit=30 : Maximum messages per run, 1–30}';

    protected $description = 'Send queued MAX notifications with bounded rate and retry handling';

    public function handle(MaxDeliveryService $delivery): int
    {
        if (! config('services.max.bot_token')) {
            $this->error('MAX_BOT_TOKEN is not configured.');

            return self::FAILURE;
        }
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 30) {
            $this->error('Limit must be between 1 and 30.');

            return self::FAILURE;
        }
        $lock = Cache::lock('max-notification-sender', 600);
        if (! $lock->get()) {
            return self::SUCCESS;
        }
        try {
            MaxNotification::query()->where('status', 'sending')->where('updated_at', '<', now()->subMinutes(10))->update(['status' => 'uncertain', 'last_error' => 'worker_interrupted']);
            DB::table('max_callback_receipts')->where('created_at', '<', now()->subDays(7))->delete();
            $ids = MaxNotification::query()->where('status', 'pending')->where('available_at', '<=', now())->orderBy('id')->limit($limit)->pluck('id');
            foreach ($ids as $id) {
                $delivery->deliver($id);
                usleep(1000000);
            }
            $this->info('Processed: '.$ids->count());

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
