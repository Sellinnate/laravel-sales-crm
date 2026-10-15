<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;

/*
| A minimal login for the workbench app (demo users are seeded with the password "password").
*/

Route::get('/', fn () => redirect('/crm'));

Route::get('/login', fn () => Blade::render(<<<'BLADE'
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Sign in · Sales CRM workbench</title><x-sales-crm::styles /></head>
<body style="margin:0">
<div class="sales-crm flex min-h-screen items-center justify-center p-6">
    <form method="POST" action="/login" class="crm-card crm-card-body flex w-full max-w-sm flex-col gap-3">
        @csrf
        <h1 class="crm-page-title">Sales CRM workbench</h1>
        <p class="text-[13px] text-muted">Demo users: admin@example.test, sales@example.test · password «password».</p>
        <label class="crm-label" for="email">Email</label>
        <input id="email" name="email" type="email" class="crm-input" value="{{ old('email', 'admin@example.test') }}" required>
        <label class="crm-label" for="password">Password</label>
        <input id="password" name="password" type="password" class="crm-input" required>
        @error('email')<p class="crm-error">{{ $message }}</p>@enderror
        <button type="submit" class="crm-btn crm-btn-primary">Sign in</button>
    </form>
</div>
</body>
</html>
BLADE))->middleware('guest')->name('login');

Route::post('/login', function (Request $request) {
    $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required']]);

    if (! Auth::attempt($credentials, true)) {
        return back()->withErrors(['email' => 'These credentials do not match our records.'])->onlyInput('email');
    }

    $request->session()->regenerate();

    return redirect()->intended('/crm');
})->middleware('guest');

Route::post('/logout', function (Request $request) {
    Auth::logout();
    $request->session()->invalidate();

    return redirect('/login');
})->name('logout');
