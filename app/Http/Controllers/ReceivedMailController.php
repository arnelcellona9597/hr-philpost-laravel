<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsToForms;
use App\Models\ReceivedMail;
use App\Models\SiteSetting;
use App\Services\ReceivedMailsWorkbook;
use App\Support\HrDate;
use App\Support\PageSize;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReceivedMailController extends Controller
{
    use RespondsToForms;

    private const PER_PAGE = 50;

    private const MAX_ROWS_PER_ENTRY = 100;

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', ReceivedMail::class);

        $month = $this->month($request);
        $search = Str::limit(trim((string) $request->query('q')), 100, '');
        $office = trim((string) $request->query('post_office'));
        $inMonth = fn (): Builder => ReceivedMail::query()->when($month !== 'all', fn (Builder $query) => $this->withinMonth($query, $month));

        return view('received-mails.index', [
            'mails' => $inMonth()
                ->when($search !== '', function (Builder $query) use ($search): void {
                    $like = '%'.addcslashes($search, '%_\\').'%';

                    $query->where(fn (Builder $any) => $any->where('tracking_number', 'like', $like)->orWhere('post_office', 'like', $like)->orWhere('description', 'like', $like)->orWhere('remarks', 'like', $like));
                })
                ->when($office !== '', fn (Builder $query) => $query->where('post_office', $office))
                ->orderBy('received_on')
                ->orderBy('id')
                ->paginate(PageSize::resolve($request, self::PER_PAGE))
                ->withQueryString(),
            'month' => $month,
            'months' => $this->months(),
            'offices' => $inMonth()->distinct()->orderBy('post_office')->pluck('post_office'),
            'monthTotal' => $inMonth()->count(),
            'daysWithMail' => $inMonth()->distinct()->count('received_on'),
            'search' => $search,
            'office' => $office,
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', ReceivedMail::class);

        return view('received-mails.create', [
            'date' => old('received_on', HrDate::today()),
            'receivedBy' => old('received_by', $request->user()->name),
            'rows' => old('rows', array_fill(0, 5, [])),
            'offices' => $this->offices(),
        ]);
    }

    /**
     * Log several pieces of mail that arrived on one day.
     */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', ReceivedMail::class);

        $date = trim((string) $request->input('received_on'));
        $rows = collect((array) $request->input('rows'))
            ->map(fn (mixed $row): array => array_map(fn (mixed $value): string => trim((string) $value), Arr::only((array) $row, ['tracking_number', 'post_office', 'description', 'remarks'])))
            ->filter(fn (array $row): bool => implode('', $row) !== '');

        $problem = match (true) {
            ! HrDate::isValid($date) => 'Enter the date the mail arrived.',
            $rows->isEmpty() => 'Add at least one piece of mail.',
            $rows->count() > self::MAX_ROWS_PER_ENTRY => 'Add at most '.self::MAX_ROWS_PER_ENTRY.' pieces of mail at a time.',
            default => $rows->map(fn (array $row, int|string $index): ?string => $this->rowProblem($row, (int) $index))->filter()->take(4)->implode(' ') ?: null,
        };

        if ($problem !== null) {
            return $this->refused($problem);
        }

        $receivedBy = $this->text($request, 'received_by', 120);

        DB::transaction(function () use ($rows, $date, $receivedBy, $request): void {
            foreach ($rows as $row) {
                $mail = new ReceivedMail([...$row, 'remarks' => $row['remarks'] ?: null, 'received_on' => $date, 'received_by' => $receivedBy]);
                $mail->created_by = $request->user()->id;
                $mail->save();
            }
        });

        return $this->flash(redirect()->route('received-mails.index', ['month' => substr($date, 0, 7)]), $rows->count().' piece'.($rows->count() === 1 ? '' : 's').' of mail logged for '.HrDate::format($date).'.');
    }

    public function edit(ReceivedMail $mail): View
    {
        Gate::authorize('update', $mail);

        return view('received-mails.edit', ['mail' => $mail, 'offices' => $this->offices()]);
    }

    public function update(Request $request, ReceivedMail $mail): RedirectResponse
    {
        Gate::authorize('update', $mail);

        $date = trim((string) $request->input('received_on'));
        $row = array_map(fn (mixed $value): string => trim((string) $value), $request->only(['tracking_number', 'post_office', 'description', 'remarks']));
        $problem = ! HrDate::isValid($date) ? 'Enter the date the mail arrived.' : $this->rowProblem($row, null);

        if ($problem !== null) {
            return $this->refused($problem);
        }

        $mail->update([...$row, 'remarks' => $row['remarks'] ?: null, 'received_on' => $date, 'received_by' => $this->text($request, 'received_by', 120)]);

        return $this->flash(redirect()->route('received-mails.index', ['month' => substr($date, 0, 7)]), 'The mail was updated.');
    }

    public function destroy(ReceivedMail $mail): RedirectResponse
    {
        Gate::authorize('delete', $mail);

        $mail->delete();

        return $this->flash(redirect()->route('received-mails.index', ['month' => $mail->received_on->format('Y-m')]), "Mail {$mail->tracking_number} was deleted.");
    }

    /**
     * One month as a workbook in the office's layout, or every month on file.
     */
    public function export(Request $request, ReceivedMailsWorkbook $workbook): StreamedResponse
    {
        Gate::authorize('viewAny', ReceivedMail::class);

        $month = $this->month($request);
        $months = $month === 'all' ? array_reverse($this->months()) : [$month];
        $spreadsheet = $workbook->export($months);

        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $workbook->filename($month), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function print(Request $request): View
    {
        Gate::authorize('viewAny', ReceivedMail::class);

        $month = $this->month($request);
        abort_if($month === 'all', 404);

        return view('received-mails.print', [
            'month' => $month,
            'label' => mb_strtoupper(Carbon::parse("{$month}-01")->format('F Y')),
            'mails' => $this->withinMonth(ReceivedMail::query(), $month)->orderBy('received_on')->orderBy('id')->get(),
            'site' => SiteSetting::current(),
        ]);
    }

    /**
     * @param  array<string, string>  $row
     */
    private function rowProblem(array $row, ?int $index): ?string
    {
        $where = $index === null ? '' : 'Row '.($index + 1).': ';
        $limits = ['tracking_number' => [60, 'tracking number'], 'post_office' => [120, 'post office'], 'description' => [2000, 'description'], 'remarks' => [1000, 'remarks']];

        foreach (['tracking_number', 'post_office', 'description'] as $required) {
            if (($row[$required] ?? '') === '') {
                return "{$where}enter the {$limits[$required][1]}.";
            }
        }

        foreach ($limits as $field => [$max, $label]) {
            if (mb_strlen($row[$field] ?? '') > $max) {
                return "{$where}the {$label} is too long ({$max} characters at most).";
            }
        }

        return null;
    }

    /**
     * The month asked for (YYYY-MM), "all", or this month.
     */
    private function month(Request $request): string
    {
        $value = (string) $request->query('month', ReceivedMailsWorkbook::currentMonth());

        return $value === 'all' || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) ? $value : ReceivedMailsWorkbook::currentMonth();
    }

    /**
     * @param  Builder<ReceivedMail>  $query
     * @return Builder<ReceivedMail>
     */
    private function withinMonth(Builder $query, string $month): Builder
    {
        return $query->whereBetween('received_on', ["{$month}-01", $month.'-'.HrDate::lastDayOf((int) substr($month, 0, 4), (int) substr($month, 5, 2))]);
    }

    /**
     * Months with mail on file, newest first, always including this month.
     *
     * @return list<string>
     */
    private function months(): array
    {
        $months = ReceivedMail::query()->toBase()->selectRaw("DATE_FORMAT(received_on, '%Y-%m') AS month")->distinct()->pluck('month')->all();

        return collect([...$months, ReceivedMailsWorkbook::currentMonth()])->unique()->sortDesc()->values()->all();
    }

    /**
     * Post offices used before, offered as suggestions while typing.
     *
     * @return list<string>
     */
    private function offices(): array
    {
        return ReceivedMail::query()->distinct()->orderBy('post_office')->limit(500)->pluck('post_office')->all();
    }

    private function text(Request $request, string $name, int $max): ?string
    {
        return mb_substr(trim((string) $request->input($name)), 0, $max) ?: null;
    }
}
