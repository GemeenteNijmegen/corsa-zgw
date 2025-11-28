<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\NotificationRequest;
use App\Jobs\CheckIncommingNotification;
use App\ValueObjects\OpenNotification;

class Notifications extends Controller
{
    public function listen(NotificationRequest $request)
    {
        $validated = $request->validated();

        CheckIncommingNotification::dispatch(opennotification: new OpenNotification(...$validated));
    }
}
