<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class StaffManagementService
{
    public function save(?User $user, array $input, User $actor): User
    {
        $this->confirmActor($actor, $input['actor_password'] ?? '');
        $data = Validator::make($input, [
            'security_version' => 'required|integer|min:0', 'name' => 'required|string|max:255',
            'email' => ['required','email','max:255', Rule::unique('users','email')->ignore($user?->id)],
            'roles' => 'present|array|max:30', 'roles.*' => ['integer', Rule::exists('roles','id')->where('guard_name','web')],
            'new_password' => [$user ? 'nullable' : 'required','string','max:72','confirmed',PasswordRule::min(12)->mixedCase()->numbers()->symbols()],
            'disable_staff_access' => 'required|boolean',
            'profile_photo_path' => ['nullable','string','max:255','regex:~^profile-photos/[a-zA-Z0-9_-]+\.(png|jpe?g|webp)$~i'],
        ])->validate();
        return DB::transaction(function () use ($user,$data,$actor) {
            // Serialize last-super-admin decisions before locking individual users.
            Role::where('name','super_admin')->where('guard_name','web')->lockForUpdate()->get();
            if ($user) {
                $user = User::lockForUpdate()->findOrFail($user->id);
                if ($user->security_version !== (int) $data['security_version']) { $this->invalid('This account changed. Reload before saving.','security_version'); }
            } elseif ((int) $data['security_version'] !== 0) { $this->invalid('Invalid new account revision.','security_version'); }
            $roles = Role::whereIn('id',$data['roles'])->where('guard_name','web')->get();
            $wasSuper = $user?->hasRole('super_admin') ?? false;
            $willSuper = $roles->contains('name','super_admin') && !$data['disable_staff_access'];
            if ($user?->id === $actor->id && (!$willSuper || $data['disable_staff_access'])) {
                $this->invalid('You cannot remove your own super-admin access. Use another authorised administrator.','roles');
            }
            if ($wasSuper && !$willSuper && User::whereNull('staff_access_disabled_at')->whereHas('roles',fn ($q) => $q->where('name','super_admin'))->count() <= 1) {
                $this->invalid('At least one active super administrator must remain.','roles');
            }
            $user ??= new User;
            $oldRoles = $user->exists ? $user->roles->pluck('id')->sort()->values()->all() : [];
            $newRoles = $roles->pluck('id')->sort()->values()->all();
            $emailChanged = $user->exists && strcasecmp($user->email,$data['email']) !== 0;
            $credentialChanged = filled($data['new_password'] ?? '') || $emailChanged || $oldRoles !== $newRoles || $data['disable_staff_access'];
            $fields = ['name','email','roles'];
            $user->fill(['name' => trim($data['name']), 'email' => strtolower(trim($data['email']))]);
            if (array_key_exists('profile_photo_path',$data)) { $user->profile_photo_path = $data['profile_photo_path']; }
            if ($emailChanged) { $user->email_verified_at = null; }
            if (filled($data['new_password'] ?? '')) { $user->password = Hash::make($data['new_password']); $fields[] = 'password'; }
            $user->forceFill(['security_version' => (int) $data['security_version'] + 1,
                'staff_access_disabled_at' => $data['disable_staff_access'] ? ($user->staff_access_disabled_at ?? now()) : null]);
            if ($credentialChanged) { $user->remember_token = Str::random(60); }
            $user->save();
            $user->syncRoles($roles);
            if ($credentialChanged) { $this->revokeSessions($user); }
            app(StaffSecurityService::class)->record('staff.account_saved',$actor,$user,['fields' => $fields,'role_ids' => $newRoles]);
            return $user->fresh();
        },3);
    }

    public function sendReset(User $user, string $actorPassword, User $actor): string
    {
        $this->confirmActor($actor,$actorPassword);
        $user = $user->fresh();
        $result = Password::broker()->sendResetLink(['email' => $user->email]);
        app(StaffSecurityService::class)->record('staff.password_reset_requested',$actor,$user,[], $result === Password::RESET_LINK_SENT ? 'smtp_accepted' : 'not_sent');
        return $result;
    }

    public function revokeSessions(User $user): void
    {
        if (Schema::hasTable('sessions')) { DB::table('sessions')->where('user_id',$user->id)->delete(); }
        if (Schema::hasTable('personal_access_tokens')) { $user->tokens()->delete(); }
    }

    private function confirmActor(User $actor, string $password): void
    {
        StaffSecurityService::requireSuperAdmin($actor);
        if (!Hash::check($password,$actor->fresh()->password)) { $this->invalid('Confirm your own current password to manage accounts.','actor_password'); }
    }

    private function invalid(string $message,string $field): never { throw ValidationException::withMessages([$field => $message]); }
}
