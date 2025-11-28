<?php

namespace App\Providers;

use App\Filament\Resources\Users\Pages\EditUser;
use App\Jobs\CheckIncommingNotification;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
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
        $this->app->bindMethod([CheckIncommingNotification::class, 'handle'], fn ($job) => $job->handle(openzaak: app(Openzaak::class)));
    }
}
