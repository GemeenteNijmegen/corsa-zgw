<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->away(config('app.home_redirect_url'));
});
