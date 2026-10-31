# Endpoint reference

All paths are relative to `/api/v1`. Except for token issuance, send a valid bearer token. Successful resource responses use a `data` object; list responses include Laravel pagination `links` and `meta`.

## Routes

| Method | Path | Purpose | Success |
| --- | --- | --- | --- |
| `POST` | `/auth/token` | Issue an eight-hour bearer token | `200` |
| `DELETE` | `/auth/token` | Revoke the current token | `200` |
| `GET` | `/users` | Search and paginate users | `200` |
| `POST` | `/users` | Create a customer account | `201` |
| `GET` | `/users/{account}` | Read user details and ticket counts | `200` |
| `PATCH` | `/users/{account}` | Update name and/or email | `200` |
| `PATCH` | `/users/{account}/status` | Activate or deactivate an account | `200` |
| `DELETE` | `/users/{account}` | Archive an account | `200` |
| `POST` | `/users/{account}/restore` | Restore an archived account | `200` |

## List users

`GET /users?search=lee&status=active&per_page=25&page=1`

- `search`: optional text, up to 150 characters; matches name or email.
- `status`: `active` (default), `inactive`, `archived`, or `all`.
- `per_page`: optional integer from 1 to 100; defaults to 25.
- `page`: Laravel paginator page number.

Each user record returns `id`, `name`, `email`, `is_active`, `is_archived`, `email_verified`, `roles`, and timestamps. Passwords, token fields, and internal authorization state are never serialized.

## Create customer

`POST /users`

```json
{
  "name": "Morgan Lee",
  "email": "morgan@example.com",
  "password": "A-long-password-123!",
  "password_confirmation": "A-long-password-123!"
}
```

Passwords must be at least 12 characters and confirmed. New accounts are active, unverified customer accounts. The server assigns the `customer` role and records the creating administrator. Client-supplied `role`, `is_active`, and other unsupported fields do not change those rules.

## Read user

`GET /users/{account}` returns the user record and `ticket_count` / `active_ticket_count`. Archived users may be read by ID.

## Update user

`PATCH /users/{account}` accepts one or both fields:

```json
{
  "name": "Morgan Lee",
  "email": "morgan.lee@example.com"
}
```

An email change clears email verification and the remember token. At least one supported field is required. Archived accounts cannot be updated until restored.

## Change active status

`PATCH /users/{account}/status`

```json
{
  "is_active": false
}
```

Deactivating an account applies the existing ticket reassignment and administrator safety rules. This operation cannot disable the acting administrator or remove the final active administrator.

## Archive and restore

`DELETE /users/{account}` archives the account. The existing lifecycle service prevents self-archiving, protects the final administrator, and safely unassigns work as needed.

`POST /users/{account}/restore` restores an archived account in the inactive state. Use the status endpoint separately if it should be reactivated.

## Errors

| Status | Meaning |
| --- | --- |
| `401` | Missing, invalid, expired, or revoked bearer token; token issuance credentials are invalid |
| `403` | Token lacks the `users.manage` ability, or current role/permission is insufficient |
| `404` | User ID does not exist or is unavailable for that operation |
| `409` | A lifecycle operation conflicts with a protected account or active-ticket rule |
| `422` | Request validation failed; Laravel returns an `errors` object keyed by field |
| `429` | Token issuance rate limit reached |

Validation example:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": ["The email field is required."]
  }
}
```
