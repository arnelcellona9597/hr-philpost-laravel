<?php

namespace App\View\Composers;

use App\Models\SiteSetting;
use App\Support\Navigation;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Binds what the signed-in app shell shows around every page.
 */
class ShellComposer
{
    public function __construct(protected Request $request) {}

    /**
     * Bind data to the view.
     */
    public function compose(View $view): void
    {
        $user = $this->request->user();
        $settings = SiteSetting::current();

        $view->with([
            'user' => $user,
            'company' => $settings->company_name,
            'logoId' => $settings->logo_id,
            'path' => '/'.ltrim($this->request->path(), '/'),
            'groups' => Navigation::groups($user),
            'unreadNotifications' => $user?->hrNotifications()->whereNull('read_at')->count() ?? 0,
            'prefs' => [
                'collapsed' => $this->request->cookie('hr-sidebar') === 'collapsed',
                'right' => $this->request->cookie('hr-sidebar-side') === 'right',
                'compact' => $this->request->cookie('hr-header') === 'compact',
            ],
        ]);
    }
}
