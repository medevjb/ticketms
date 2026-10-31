# User Management API

This versioned API exposes only the admin user module. It supports customer account listing, creation, detail, edits, status changes, archiving, and restoration. It does not expose Tyro's generic role or privilege endpoints.

## Documentation

- [Authentication and tokens](authentication.md)
- [Endpoint reference](endpoints.md)
- [OpenAPI 3.1 specification](openapi.yaml)
- [Postman import and usage](postman/README.md)
- [Postman collection](postman/User%20Management%20API.postman_collection.json)
- [Postman environment template](postman/User%20Management%20API.postman_environment.json)
- [Realtime ticket and reply events](../../realtime/ticket-message-events.md)

## Base URL

```text
{{baseUrl}}/api/v1
```

The base URL is the application origin, for example `http://localhost:8000` or your configured Herd domain. Do not include `/api/v1` in the `baseUrl` variable.

## API scope

Every user-resource endpoint requires an active administrator, the `users.manage` application permission, and a valid short-lived Tyro/Sanctum bearer token with the `users.manage` token ability. The API creates customer-role accounts only. Role and account-state fields supplied during creation are ignored; use the dedicated status endpoint for activation changes.

List results are paginated. `status` defaults to `active`; supported values are `active`, `inactive`, `archived`, and `all`. Search and page size are bounded by validation.

## Quick start

1. Import the Postman collection and environment.
2. Set `baseUrl` to your application origin.
3. Run **Authentication → Issue user-management token** with an administrator's email and password. The request stores the returned token in the active environment.
4. Run requests in **Users**. The collection sends the bearer token automatically.

The API login is rate limited. Tokens expire after eight hours; issue another token when it expires. See [authentication](authentication.md) for details.
