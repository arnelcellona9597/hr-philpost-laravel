<?php

namespace App\Http\Controllers;

use App\Models\NotificationSetting;
use App\Services\MailOutbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MailQueueController extends Controller
{
    public function __invoke(Request $request, MailOutbox $outbox): JsonResponse
    {
        $key = NotificationSetting::current()->queue_key;

        abort_unless(filled($key) && hash_equals($key, (string) $request->header('X-Queue-Key')), 403);

        return response()->json($outbox->drain());
    }
}
