<?php

namespace App\Providers;

use App\Filament\Resources\Users\Pages\EditUser;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;

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
        URL::forceScheme('https');
    }
}
