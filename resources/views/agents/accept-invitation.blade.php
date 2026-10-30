@extends('tyro-login::layouts.auth')

@section('content')
<div class="auth-container {{ $layout }}" @if($layout === 'fullscreen') style="background-image: url('{{ $backgroundImage }}');" @endif @if($layout === 'youtube-video') id="tyro-youtube-container" @endif>
    <div class="form-panel">
        <div class="form-card">
            <div class="logo-container">
                @if($branding['logo'] ?? false)
                    <img src="{{ $branding['logo'] }}" alt="{{ $branding['app_name'] ?? config('app.name') }}">
                @else
                    <div class="app-logo"><span>{{ config('app.name') }}</span></div>
                @endif
            </div>
            <div class="form-header">
                <h2>{{ $pageContent['title'] }}</h2>
                <p>{{ $pageContent['subtitle'] }}</p>
            </div>
            @if($invitationUnavailable)
                <div class="error-list" role="alert">
                    <p>This invitation is invalid, expired, or already used. Ask your administrator to send a new invitation.</p>
                </div>
                <div class="form-footer"><a class="form-link" href="{{ route('tyro-login.login') }}">Return to login</a></div>
            @else
                <div class="email-notice">
                    <p>Agent account</p>
                    <p class="email-address">{{ $email }}</p>
                </div>
                @if($errors->any())
                    <div class="error-list" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
                @endif
                <form method="POST" action="{{ route('agent-invitations.store', $token) }}">
                    @csrf
                    <input type="hidden" name="token" value="{{ $token }}">
                    <div class="form-group">
                        <label class="form-label" for="password">Password</label>
                        <input class="form-input" id="password" name="password" type="password" required autocomplete="new-password" autofocus>
                        @error('password')<span class="error-message">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="password_confirmation">Confirm password</label>
                        <input class="form-input" id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
                    </div>
                    <button class="btn btn-primary" type="submit">Set password and activate account</button>
                </form>
                <div class="form-footer"><a class="form-link" href="{{ route('tyro-login.login') }}">Return to login</a></div>
            @endif
        </div>
    </div>
</div>
@include('tyro-login::partials.backgrounds')
@endsection
