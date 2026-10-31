# Postman setup

Import `User Management API.postman_collection.json` and `User Management API.postman_environment.json` into Postman. Select the **TicketMS User Management API** environment and set `baseUrl` to the app origin without `/api/v1`.

Run **Authentication → Issue user-management token** first. It stores the returned token in the collection/environment variable `token`; the user requests use that value as a bearer token automatically. Token issuance needs an active administrator account with the `users.manage` permission.

The **Users** folder includes list, create, show, update, activate/deactivate, archive, and restore requests. `Create customer` saves the returned ID to `userId` for the follow-up requests. The list request demonstrates search, status, and pagination filters.

The collection does not contain a real token or password. The environment template contains only a local example URL; use a separate environment for each deployment, and do not export a populated token.
