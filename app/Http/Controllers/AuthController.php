<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            ActivityLog::record('sign_in_failed', 'Failed sign-in for '.$credentials['email'], ['type' => 'system user', 'label' => $credentials['email']],
                as: null, name: $credentials['email']);

            return back()
                ->withInput($request->only('email', 'remember'))
                ->withErrors(['email' => 'The email or password is incorrect.']);
        }

        $request->session()->regenerate();
        $request->user()->forceFill(['last_login_at' => now()])->saveQuietly();
        ActivityLog::record('signed_in', 'Signed in', $request->user()->activitySubject());

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request)
    {
        if ($request->user()) {
            ActivityLog::record('signed_out', 'Signed out', $request->user()->activitySubject());
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
