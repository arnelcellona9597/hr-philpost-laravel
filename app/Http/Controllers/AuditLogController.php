<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Support\ActivityFeed;
use App\Support\PageSize;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    private const ACTIONS = ['create', 'update', 'delete', 'import'];

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', AuditLog::class);

        $search = mb_substr(trim((string) $request->query('q')), 0, 100);
        $collection = (string) $request->query('collection');
        $action = in_array($request->query('action'), self::ACTIONS, true) ? $request->query('action') : '';
        $user = $request->user();

        return view('audit.index', [
            'logs' => AuditLog::query()
                ->visibleTo($user)
                ->when($collection !== '', fn ($query) => $query->where('collection_slug', $collection))
                ->when($action !== '', fn ($query) => $query->where('action', $action))
                ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                    ->where('doc_label', 'like', "%{$search}%")
                    ->orWhere('user_name', 'like', "%{$search}%")))
                ->latest('id')
                ->paginate(PageSize::resolve($request, 50))
                ->withQueryString(),
            'collections' => array_values(array_diff(ActivityFeed::slugs(), $user->isSystemAdmin() ? [] : AuditLog::ADMIN_ONLY_COLLECTIONS)),
            'actions' => self::ACTIONS,
            'search' => $search,
            'collection' => $collection,
            'action' => $action,
        ]);
    }
}
