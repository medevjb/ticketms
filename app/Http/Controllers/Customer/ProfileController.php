<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\UpdateAvatarRequest;
use App\Http\Requests\Customer\UpdatePasswordRequest;
use App\Http\Requests\Customer\UpdateProfileRequest;
use App\Models\User;
use App\Services\Customer\CustomerProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProfileController extends Controller
{
    public function index(Request $request): View
    {
        $customer = $request->user();

        return view('customer.profile', [
            'customer' => $customer,
            'avatarUrl' => $customer->avatar_path === null ? null : route('customer.profile.avatar'),
        ]);
    }

    public function avatar(Request $request): StreamedResponse
    {
        $avatarPath = $request->user()->avatar_path;

        abort_unless(is_string($avatarPath) && Storage::disk('local')->exists($avatarPath), 404);

        return Storage::disk('local')->response($avatarPath);
    }

    public function update(UpdateProfileRequest $request, CustomerProfileService $profile): RedirectResponse
    {
        /** @var User $customer */
        $customer = $request->user();
        $profile->updateName($customer, $request->validated('name'));

        return to_route('customer.profile')->with('success', 'Your profile was updated.');
    }

    public function updatePassword(UpdatePasswordRequest $request, CustomerProfileService $profile): RedirectResponse
    {
        /** @var User $customer */
        $customer = $request->user();
        $profile->updatePassword($customer, $request->validated('password'));

        return to_route('customer.profile')->with('success', 'Your password was changed.');
    }

    public function updateAvatar(UpdateAvatarRequest $request, CustomerProfileService $profile): RedirectResponse
    {
        /** @var User $customer */
        $customer = $request->user();
        $profile->updateAvatar($customer, $request->file('avatar'));

        return to_route('customer.profile')->with('success', 'Your profile photo was updated.');
    }

    public function destroyAvatar(Request $request, CustomerProfileService $profile): RedirectResponse
    {
        /** @var User $customer */
        $customer = $request->user();
        $profile->removeAvatar($customer);

        return to_route('customer.profile')->with('success', 'Your profile photo was removed.');
    }
}
