<?php

namespace App\Http\Controllers;

use App\Enums\LoginChallengePurpose;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Exceptions\UserFacingException;
use App\Models\User;
use App\Services\AccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', User::class);

        return view('users.index', ['users' => User::query()->orderBy('name')->limit(500)->get()]);
    }

    public function store(Request $request, AccountService $accounts): RedirectResponse
    {
        Gate::authorize('create', User::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:254', Rule::unique('users', 'email')],
            'role' => ['required', Rule::enum(Role::class)],
        ], ['email.unique' => 'That email address already has an account.']);

        $result = $accounts->invite($data['name'], $data['email'], Role::from($data['role']));

        return $this->linkFlash($result, "Account created for {$result['user']->name}.", 'An invitation was', $result['user']->email);
    }

    public function update(Request $request, User $user, AccountService $accounts): RedirectResponse
    {
        Gate::authorize('manageAccess', User::class);

        $data = $request->validate([
            'role' => ['required', Rule::enum(Role::class)],
            'status' => ['required', Rule::in([UserStatus::Approved->value, UserStatus::Disabled->value])],
        ]);

        try {
            $accounts->changeAccess($user, Role::from($data['role']), UserStatus::from($data['status']));
        } catch (UserFacingException $exception) {
            return $this->flash('error', $exception->getMessage());
        }

        return $this->flash('success', "{$user->name} is now {$user->role->label()} ({$user->status->value}).");
    }

    public function unlock(User $user, AccountService $accounts): RedirectResponse
    {
        Gate::authorize('unlock', User::class);

        $accounts->unlock($user);

        return $this->flash('success', "{$user->name} was unlocked.");
    }

    public function sendLink(Request $request, User $user, AccountService $accounts): RedirectResponse
    {
        Gate::authorize('manageAccess', User::class);

        $isInvite = $request->validate(['kind' => ['required', Rule::in(['invite', 'reset'])]])['kind'] === 'invite';
        $result = $accounts->issueLink($user, $isInvite ? LoginChallengePurpose::Invite : LoginChallengePurpose::Reset);

        return $this->linkFlash($result, '', $isInvite ? 'A new invitation was' : 'A password reset link was', $user->email);
    }

    public function destroy(User $user, AccountService $accounts): RedirectResponse
    {
        Gate::authorize('delete', $user);

        try {
            $accounts->delete($user);
        } catch (UserFacingException $exception) {
            return $this->flash('error', $exception->getMessage());
        }

        return $this->flash('success', 'Account deleted.');
    }

    /**
     * When email is down the link is shown to the admin to pass on.
     *
     * @param  array{delivered: bool, link: string}  $result
     */
    private function linkFlash(array $result, string $prefix, string $what, string $email): RedirectResponse
    {
        $message = $result['delivered']
            ? "{$what} emailed to {$email}. The link works once and expires (invites: 24 hours, resets: 1 hour)."
            : "The email server isn't working, so nothing was sent. Give {$email} this single-use link privately: {$result['link']}";

        return $this->flash($result['delivered'] ? 'success' : 'info', trim("{$prefix} {$message}"));
    }

    private function flash(string $type, string $message): RedirectResponse
    {
        return redirect()->route('users.index')->with('flash', ['type' => $type, 'message' => $message]);
    }
}
