<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Settings > System users: who can sign in to the dashboard, and what they can do.
 * Administrators only. You can't delete yourself, and the last administrator can't
 * be deleted or given a lower role (someone must always be able to manage the system).
 */
class SystemUserController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validateWithBag('newAccount', $this->rules(null), $this->messages());
        $account = User::create($data);

        return $this->back("{$account->name} added as {$account->roleLabel()}. They can sign in with {$account->email}.");
    }

    public function update(Request $request, User $account)
    {
        $data = $request->validateWithBag('account'.$account->id, $this->rules($account), $this->messages());
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        if ($account->isAdmin() && $data['role'] !== 'admin' && $this->lastAdmin($account)) {
            return $this->back("{$account->name} is the only administrator. Make someone else an administrator first.", 'error');
        }
        if ($account->is($request->user()) && $data['role'] !== 'admin') {
            return $this->back('You can\'t lower your own role. Ask another administrator.', 'error');
        }

        $account->update($data);

        return $this->back("{$account->name} saved".(isset($data['password']) ? ', with the new password' : '').'.');
    }

    public function destroy(Request $request, User $account)
    {
        if ($account->is($request->user())) {
            return $this->back('You can\'t delete your own account while signed in with it.', 'error');
        }
        if ($account->isAdmin() && $this->lastAdmin($account)) {
            return $this->back("{$account->name} is the only administrator and can't be deleted.", 'error');
        }

        $account->delete();
        // Signed in elsewhere? Their session ends on the next request (the account is gone).

        return $this->back("{$account->name} deleted. They can no longer sign in.");
    }

    private function rules(?User $account): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:100', "regex:/^[\\p{L}][\\p{L}\\p{M} .,'\\-]*$/u"],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($account?->id)],
            'position' => ['required', 'string', 'max:100'],
            'department' => ['required', 'string', 'max:120'],
            'contact' => ['required', 'string', 'max:100', function ($attr, $value, $fail) {
                $v = trim((string) $value);
                $phone = preg_match('/^\+?[0-9][0-9 ()\-]{6,19}$/', $v);
                if (! $phone && ! filter_var($v, FILTER_VALIDATE_EMAIL)) {
                    $fail('Enter a mobile or phone number (e.g. 0917 123 4567) or an email address.');
                }
            }],
            'role' => ['required', Rule::in(array_keys(User::ROLES))],
            'password' => [$account ? 'nullable' : 'required', 'confirmed', Password::min(12)],
        ];
    }

    private function messages(): array
    {
        return [
            'name.regex' => 'Use letters only for the full name.',
            'email.unique' => 'Another account already uses this email.',
            'password.confirmed' => 'The two passwords do not match.',
        ];
    }

    private function lastAdmin(User $account): bool
    {
        return User::query()->where('role', 'admin')->whereKeyNot($account->id)->doesntExist();
    }

    private function back(string $message, string $key = 'status')
    {
        return redirect()->to(route('settings').'#accounts')->with($key, $message);
    }
}
