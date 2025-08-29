<?php

namespace App\Http\Controllers\Api;

use App\Actions\CheckNotificationAndDispatchJobs;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\NotificationRequest;

class Notifications extends Controller
{
    public function listen(NotificationRequest $request)
    {
        $validated = $request->validated();

        (new CheckNotificationAndDispatchJobs())->handle($validated);
        // check which notification it is and activate the correct job bus
    }
}
