<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Support\SafeRedirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Notification::class);

        return view('notifications.index', [
            'notifications' => $request->user()->hrNotifications()->latest('id')->paginate(30),
        ]);
    }

    /**
     * Mark one as read and follow its link, if it has one.
     */
    public function read(Notification $notification): RedirectResponse
    {
        Gate::authorize('update', $notification);

        $notification->forceFill(['read_at' => now()])->save();

        return redirect($notification->link ? SafeRedirect::path($notification->link) : route('notifications.index'));
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->hrNotifications()->whereNull('read_at')->update(['read_at' => now()]);

        return redirect()->route('notifications.index');
    }
}
