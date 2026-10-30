@extends('customer.layouts.app')
@section('title', 'Your profile')
@section('content')
<header class="customer-page-heading">
    <div><span class="customer-kicker" style="color:#0e7490">Your account</span><h1>Profile & settings</h1><p>Manage your personal details and keep your account secure.</p></div>
</header>

<div class="customer-profile-grid">
    <section class="customer-panel">
        <div class="customer-panel-heading"><div><h2>Personal information</h2><p>Update how support sees your account.</p></div></div>
        <div class="customer-panel-body">
            <div class="customer-profile-identity">
                <div class="customer-profile-avatar">
                    @if($avatarUrl)<img src="{{ $avatarUrl }}" alt="Profile photo for {{ $customer->name }}">@else{{ str($customer->name)->substr(0, 1)->upper() }}@endif
                </div>
                <div><strong>{{ $customer->name }}</strong><span>{{ $customer->email }}</span><small>Email is your sign-in address and cannot be changed here.</small></div>
            </div>

            <form method="POST" action="{{ route('customer.profile.update') }}" class="customer-form customer-profile-form">
                @csrf
                @method('PUT')
                <div class="customer-field-wrap">
                    <label class="customer-field-label" for="name">Full name</label>
                    <input class="customer-field" id="name" name="name" type="text" value="{{ old('name', $customer->name) }}" autocomplete="name" maxlength="255" required>
                    @error('name')<p class="customer-field-error">{{ $message }}</p>@enderror
                </div>
                <button type="submit" class="customer-button">Save profile</button>
            </form>
        </div>
    </section>

    <section class="customer-panel">
        <div class="customer-panel-heading"><div><h2>Profile photo</h2><p>Choose a JPG, PNG, or WebP image up to 2 MB.</p></div></div>
        <div class="customer-panel-body">
            <form method="POST" action="{{ route('customer.profile.avatar.update') }}" enctype="multipart/form-data" class="customer-form">
                @csrf
                <div class="customer-field-wrap">
                    <label class="customer-field-label" for="avatar">Upload a photo</label>
                    <input class="customer-field" id="avatar" name="avatar" type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" required>
                    @error('avatar')<p class="customer-field-error">{{ $message }}</p>@enderror
                </div>
                <button type="submit" class="customer-button">Upload photo</button>
            </form>
            @if($avatarUrl)
                <form method="POST" action="{{ route('customer.profile.avatar.destroy') }}" class="customer-profile-remove">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="customer-button customer-button-danger">Remove current photo</button>
                </form>
            @endif
        </div>
    </section>

    <section class="customer-panel customer-profile-password">
        <div class="customer-panel-heading"><div><h2>Change password</h2><p>Confirm your current password before setting a new one.</p></div></div>
        <div class="customer-panel-body">
            <form method="POST" action="{{ route('customer.profile.password') }}" class="customer-form">
                @csrf
                @method('PUT')
                <div class="customer-profile-password-fields">
                    <div class="customer-field-wrap">
                        <label class="customer-field-label" for="current_password">Current password</label>
                        <input class="customer-field" id="current_password" name="current_password" type="password" autocomplete="current-password" required>
                        @error('current_password')<p class="customer-field-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="customer-field-wrap">
                        <label class="customer-field-label" for="password">New password</label>
                        <input class="customer-field" id="password" name="password" type="password" autocomplete="new-password" required>
                        @error('password')<p class="customer-field-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="customer-field-wrap">
                        <label class="customer-field-label" for="password_confirmation">Confirm new password</label>
                        <input class="customer-field" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
                    </div>
                </div>
                <button type="submit" class="customer-button">Change password</button>
            </form>
        </div>
    </section>
</div>
@endsection
