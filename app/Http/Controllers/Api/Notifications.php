<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\NotificationRequest;
use Illuminate\Http\Request;

class Notifications extends Controller
{
    public function listen(NotificationRequest $request)
    {
        $validated = $request->validated();
        // todo
    }
}
