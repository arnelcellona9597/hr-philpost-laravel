@php
    use App\Support\ActivityFeed;
@endphp

<x-layouts.app title="Audit Log" heading="Audit log" description="Every change to HR records: who, what, when — with the before and after values.">
    <form method="get" class="card mb-4 flex flex-wrap items-end gap-3 p-4" data-autosubmit>
        <div class="min-w-56 flex-1">
            <label class="label" for="q">Search</label>
            <input class="input" id="q" name="q" type="search" value="{{ $search }}" placeholder="Record or user name" />
        </div>
        <div>
            <label class="label" for="collection">Area</label>
            <select class="input" id="collection" name="collection">
                <option value="">All</option>
                @foreach ($collections as $option)
                    <option @selected($option === $collection)>{{ $option }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="action">Action</label>
            <select class="input" id="action" name="action">
                <option value="">All</option>
                @foreach ($actions as $option)
                    <option @selected($option === $action)>{{ $option }}</option>
                @endforeach
            </select>
        </div>
        <button class="btn-primary">Filter</button>
    </form>

    <div class="card overflow-hidden">
        @if ($logs->isEmpty())
            <x-empty-state title="No activity found" />
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Area</th><th>Record</th><th>Changes</th></tr></thead>
                    <tbody>
                        @foreach ($logs as $log)
                            @php
                                $changes = in_array($log->action, ['update', 'import'], true) ? ($log->changes ?? []) : [];
                                $href = ActivityFeed::link($log);
                                $tone = match ($log->action) { 'delete' => 'badge-danger', 'create' => 'badge-success', default => 'badge-neutral' };
                                $show = fn (mixed $value, int $limit): string => mb_substr(is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE), 0, $limit);
                            @endphp
                            <tr>
                                <td class="whitespace-nowrap tabular text-fg-2">{{ $log->created_at?->timezone('Asia/Manila')->format('M j, Y g:i A') }}</td>
                                <td>{{ $log->user_name }}</td>
                                <td><span class="{{ $tone }}">{{ $log->action }}</span></td>
                                <td class="text-fg-2">{{ $log->collection_slug }}</td>
                                <td>
                                    @if ($href)
                                        <a class="hover:text-primary-ink" href="{{ url($href) }}">{{ $log->doc_label ?? $log->doc_id }}</a>
                                    @else
                                        {{ $log->doc_label ?? $log->doc_id }}
                                    @endif
                                </td>
                                <td class="max-w-md text-xs text-fg-2">
                                    @foreach (array_slice($changes, 0, 5, true) as $field => $change)
                                        <div>
                                            <span class="font-medium text-fg">{{ $field }}</span>:
                                            @if (is_array($change) && array_key_exists('to', $change))
                                                {{ $show($change['from'] ?? '—', 40) ?: '—' }} → {{ $show($change['to'] ?? '—', 40) ?: '—' }}
                                            @else
                                                {{ $show($change, 60) }}
                                            @endif
                                        </div>
                                    @endforeach
                                    @if (count($changes) > 5)
                                        <div>+{{ count($changes) - 5 }} more</div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-pagination :paginator="$logs" />
        @endif
    </div>
</x-layouts.app>
