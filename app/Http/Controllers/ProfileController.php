<?php

namespace App\Http\Controllers;

use App\Exceptions\UserFacingException;
use App\Rules\HrPassword;
use App\Services\AccountService;
use App\Services\MediaStore;
use App\Services\SignInService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('profile', ['account' => $request->user()]);
    }

    public function updateName(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);

        $request->user()->update(['name' => trim($data['name'])]);

        return $this->done('Name updated.');
    }

    public function updateAvatar(Request $request, MediaStore $media): RedirectResponse
    {
        $request->validate(['avatar' => ['required', 'file']], ['avatar.*' => 'Choose a picture to upload.']);

        try {
            $media->setAvatar($request->user(), $request->file('avatar'));
        } catch (UserFacingException $exception) {
            return back()->withErrors(['avatar' => $exception->getMessage()]);
        }

        return $this->done('Profile picture updated.');
    }

    public function destroyAvatar(Request $request, MediaStore $media): RedirectResponse
    {
        $media->setAvatar($request->user(), null);

        return $this->done('Profile picture removed.');
    }

    /**
     * The next sign-in uses the new password and a fresh email code, on every device.
     */
    public function updatePassword(Request $request, SignInService $signIn, AccountService $accounts): RedirectResponse
    {
        $data = $request->validate(
            ['current' => ['required', 'string'], 'password' => ['required', 'confirmed', new HrPassword]],
            ['password.confirmed' => 'New passwords do not match.'],
        );

        $user = $request->user();

        if (! $signIn->passwordMatches($user, $data['current'])) {
            return back()->withErrors(['current' => 'Your current password is incorrect.']);
        }

        $user->forceFill(['password' => $data['password']])->save();

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $accounts->endAllSessions($user);

        return redirect()->route('login', ['notice' => 'password']);
    }

    private function done(string $message): RedirectResponse
    {
        return redirect()->route('profile')->with('flash', ['type' => 'success', 'message' => $message]);
    }
}
