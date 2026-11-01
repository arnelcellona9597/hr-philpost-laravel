<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsToForms;
use App\Models\ReceivedMail;
use App\Services\PendingImports;
use App\Services\ReceivedMailsWorkbook;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ReceivedMailImportController extends Controller
{
    use RespondsToForms;

    private const MAX_BYTES = 10 * 1024 * 1024;

    private const HINT = 'mails';

    /**
     * Check an uploaded workbook. Nothing is saved until the person confirms the result.
     */
    public function preview(Request $request, ReceivedMailsWorkbook $workbook, PendingImports $imports): View|RedirectResponse
    {
        Gate::authorize('create', ReceivedMail::class);

        $file = $request->file('file');
        $index = redirect()->route('received-mails.index');

        if (! $file instanceof UploadedFile || ! $file->isValid() || ! preg_match('/\.xlsx$/i', $file->getClientOriginalName())) {
            return $this->flash($index, 'Upload an .xlsx workbook.', 'error');
        }

        if ($file->getSize() > self::MAX_BYTES) {
            return $this->flash($index, 'The file is larger than 10 MB.', 'error');
        }

        $plan = $workbook->analyze($file->getRealPath());
        $token = $imports->stage((string) file_get_contents($file->getRealPath()), $request->user()->id, $file->getClientOriginalName(), self::HINT);

        return view('received-mails.import', ['plan' => $plan, 'token' => $token, 'fileName' => $file->getClientOriginalName()]);
    }

    public function commit(Request $request, ReceivedMailsWorkbook $workbook, PendingImports $imports): View|RedirectResponse
    {
        Gate::authorize('create', ReceivedMail::class);

        $token = (string) $request->input('token');
        $staged = $imports->claim($token, $request->user()->id, self::HINT);

        if ($staged === null) {
            return $this->flash(redirect()->route('received-mails.index'), 'This upload expired or was already imported. Upload the file again.', 'error');
        }

        $fileName = $staged['meta']['file_name'];
        $plan = $workbook->analyze($staged['path']);

        if ($plan['errors'] !== []) {
            $imports->release($token);

            return view('received-mails.import', ['plan' => $plan, 'token' => $token, 'fileName' => $fileName]);
        }

        $result = $workbook->commit($plan, $request->user(), $fileName);
        $imports->finish($token);

        return $this->flash(redirect()->route('received-mails.index', ['month' => 'all']), "Imported {$fileName}: {$result['created']} new mail(s); {$result['existing']} were already logged.");
    }
}
