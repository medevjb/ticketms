# Authentication

The API uses the Sanctum personal access token support provided by Tyro. Send the token in the standard bearer header:

```http
Authorization: Bearer <access-token>
Accept: application/json
```

## Issue a token

`POST /api/v1/auth/token`

```json
{
  "email": "admin@example.com",
  "password": "your-password",
  "token_name": "Postman"
}
```

`token_name` is optional and limited to 100 characters. A successful response returns the plain token once:

```json
{
  "data": {
    "token_type": "Bearer",
    "access_token": "1|...",
    "expires_at": "2026-11-01T12:00:00+00:00"
  }
}
```

Only active administrators with the current `users.manage` permission can issue a token. Invalid credentials and accounts without that access receive the same `401` response. Requests are limited to 5 per minute per email and 20 per minute per IP address.

Tokens carry only the `users.manage` ability and expire after eight hours. The API checks both the token ability and the current database role and permission on every protected request, so removing access takes effect immediately.

## Revoke the current token

`DELETE /api/v1/auth/token`

Send the same bearer header. The current token is deleted and cannot be used again. Revocation does not affect other tokens belonging to the administrator.

## Reverb channel authentication

The browser-based customer portal uses the existing authenticated web session to authorize private Reverb channels. Channel authorization is separate from API bearer-token authentication and is protected by the web middleware and CSRF token. See the [ticket and reply realtime integration guide](../../realtime/ticket-message-events.md).
