<?php

use App\Http\Controllers\Api\Notifications;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::post('notifications', [Notifications::class, 'listen']);
});
