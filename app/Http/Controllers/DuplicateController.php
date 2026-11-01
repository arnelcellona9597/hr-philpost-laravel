<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsToForms;
use App\Models\Application;
use App\Models\Employee;
use App\Services\DuplicateFinder;
use App\Services\MediaStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DuplicateController extends Controller
{
    use RespondsToForms;

    public function index(Request $request, DuplicateFinder $finder): View
    {
        $module = $this->module($request);
        Gate::authorize('viewAny', $this->model($module));

        $groups = $finder->groups($module);

        return view('data.duplicates', [
            'module' => $module,
            'modules' => DuplicateFinder::MODULES,
            'groups' => $groups,
            'records' => $finder->records($module, $groups),
        ]);
    }

    /**
     * Delete the chosen copies. Employees who still have leave, payslips or other history are kept and listed.
     */
    public function destroy(Request $request, DuplicateFinder $finder, MediaStore $media): RedirectResponse
    {
        $module = $this->module($request);
        Gate::authorize('delete', $this->model($module));

        $ids = array_slice(array_values(array_filter(array_map('intval', (array) $request->input('ids')))), 0, 200);
        $back = redirect()->route('data.duplicates', ['module' => $module]);

        if ($ids === []) {
            return $this->flash($back, 'Choose the records to delete.', 'error');
        }

        $result = $finder->delete($module, $ids, $media);
        $message = $result['deleted'].' duplicate '.($result['deleted'] === 1 ? 'record was' : 'records were').' deleted.'
            .($result['blocked'] === [] ? '' : ' Kept because they have linked records: '.implode('; ', $result['blocked']).'.');

        return $this->flash($back, $message, $result['blocked'] === [] || $result['deleted'] > 0 ? 'success' : 'error');
    }

    private function module(Request $request): string
    {
        $module = (string) $request->input('module', $request->query('module', 'employees'));

        return array_key_exists($module, DuplicateFinder::MODULES) ? $module : 'employees';
    }

    /**
     * @return class-string<Employee|Application>
     */
    private function model(string $module): string
    {
        return $module === 'employees' ? Employee::class : Application::class;
    }
}
