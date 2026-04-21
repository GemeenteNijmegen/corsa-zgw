<?php

namespace App\Providers;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

class QueueMonitoringServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(JobFailed::class, function (JobFailed $event) {
            $this->notify("❌ *Failed Job*: `{$event->job->resolveName()}`\n```{$event->exception->getMessage()}```");
        });

        Event::listen(WorkerStopping::class, function (WorkerStopping $event) {
            $this->notify("⚠️ *Queue Worker Stopped* (exit code: {$event->status})");
        });
    }

    private function notify(string $message): void
    {
        if ($url = config('services.slack.webhook_url')) {
            Http::post($url, ['text' => $message]);
        }
    }
}
