<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Updates the authenticated user's password, including clearing the Admin bootstrapping
 * account's must_change_password flag after its first forced password change.
 */
class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        // The Admin bootstrapping account starts with an empty password, so its
        // current_password field is legitimately blank on the very first change.
        $currentPasswordRule = $request->user()->must_change_password ? 'nullable' : 'required';

        $validated = $request->validateWithBag('updatePassword', [
            'current_password' => [$currentPasswordRule, 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $request->user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        if ($request->user()->must_change_password) {
            $request->user()->must_change_password = false;
            $request->user()->save();
        }

        return back()->with('status', 'password-updated');
    }
}
