<?php

namespace App\Providers;

use App\Filament\Resources\Users\Pages\EditUser;
use App\Jobs\CheckIncomingNotification;
use App\Services\BatchingService;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Woweb\Openzaak\Openzaak;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        FilamentView::registerRenderHook(
            PanelsRenderHook::RESOURCE_RELATION_MANAGER_BEFORE,
            fn (): View => view('filament.sanctum-token'),
            scopes: EditUser::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Queue::failing(function (JobFailed $event) {
            if ($url = config('services.slack.webhook_url')) {
                Http::post($url, [
                    'text' => "❌ *Failed Job*: `{$event->job->resolveName()}`\n```{$event->exception->getMessage()}```",
                ]);
            }
        });

        $this->app->bindMethod([CheckIncomingNotification::class, 'handle'], fn ($job) => $job->handle(openzaak: app(Openzaak::class), batchingService: app(BatchingService::class)));
    }
}
