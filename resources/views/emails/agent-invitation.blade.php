<x-mail::message>
# Set up your agent account

Hello {{ $agentName }},

An administrator created an agent account for you. Use the button below to choose your password and activate your account.

<x-mail::button :url="$acceptUrl">
Set password
</x-mail::button>

This one-time link expires {{ $expiresAt }}. If you were not expecting this invitation, you can ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
