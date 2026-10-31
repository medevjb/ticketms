@extends('tyro-dashboard::layouts.admin')
@section('title', $account ? 'Edit user' : 'Add customer')
@section('breadcrumb')<a href="{{ route('dashboard.users.index') }}">Users</a><span class="breadcrumb-separator">/</span><span>{{ $account ? 'Edit user' : 'Add customer' }}</span>@endsection
@section('content')
<div class="page-header"><h1 class="page-title">{{ $account ? 'Edit user' : 'Add customer' }}</h1><p class="page-description">{{ $account ? 'Update account contact details. Changing the email clears its verification.' : 'Create a customer account. Staff invitations are available from the Agents page.' }}</p></div>
<div class="card" style="max-width:680px"><div class="card-body"><form method="POST" action="{{ $account ? route('dashboard.users.update', $account) : route('dashboard.users.store') }}" style="display:grid;gap:20px">
@csrf
@if($account) @method('PUT') @endif
<div><label for="name" class="form-label">Full name</label><input id="name" name="name" class="form-input" value="{{ old('name', $account?->name) }}" maxlength="255" autocomplete="name" required></div>
<div><label for="email" class="form-label">Email address</label><input id="email" name="email" type="email" class="form-input" value="{{ old('email', $account?->email) }}" maxlength="255" autocomplete="email" required></div>
@if(!$account)
<div><label for="password" class="form-label">Initial password</label><input id="password" name="password" type="password" class="form-input" minlength="12" autocomplete="new-password" required><p class="page-description">At least 12 characters. Share the initial password with the customer securely.</p></div>
<div><label for="password_confirmation" class="form-label">Confirm password</label><input id="password_confirmation" name="password_confirmation" type="password" class="form-input" minlength="12" autocomplete="new-password" required></div>
@endif
<div style="display:flex;gap:12px;flex-wrap:wrap"><button class="btn btn-primary" type="submit">{{ $account ? 'Save changes' : 'Create customer' }}</button><a class="btn btn-ghost" href="{{ route('dashboard.users.index') }}">Cancel</a></div>
</form></div></div>
@endsection
