<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthController extends Controller
{
    /**
     * Display the Admin login form.
     */
    public function showLoginForm(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        return view('auth.login', [
            'siteName' => SystemSetting::getValue('site_name', 'Solar Panel Monitoring IoT'),
            'publicDashboardEnabled' => (bool) SystemSetting::getValue('public_dashboard_enabled', true),
        ]);
    }

    /**
     * Authenticate an Admin using either email or username.
     */
    public function login(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'login' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string'],
        ], [
            'login.required' => 'Email atau username wajib diisi.',
            'password.required' => 'Password wajib diisi.',
        ]);

        $loginInput = trim($validated['login']);
        $field = filter_var($loginInput, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
        $remember = $request->boolean('remember');

        $user = User::query()->where($field, $loginInput)->first();

        if ($user && ! $user->is_active) {
            return back()
                ->withErrors(['login' => 'Akun ini sedang dinonaktifkan oleh administrator.'])
                ->withInput($request->only('login', 'remember'));
        }

        if (! Auth::attempt([$field => $loginInput, 'password' => $validated['password'], 'is_active' => true], $remember)) {
            return back()
                ->withErrors(['login' => 'Email/username atau password tidak sesuai.'])
                ->withInput($request->only('login', 'remember'));
        }

        $request->session()->regenerate();

        $authenticatedUser = Auth::user();
        if ($authenticatedUser instanceof User) {
            $authenticatedUser->forceFill(['last_login_at' => now()])->save();
        }

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Log the user out and invalidate the session.
     */
    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('login')
            ->with('status', 'Anda telah berhasil keluar dari sesi.');
    }
}
