# Framekeep API v1

The website and future mobile apps use `/api/v1`. Use HTTPS in hosted environments and send `Accept: application/json`. The browser uses an HTTP-only session cookie and a CSRF token. Mobile clients use `Authorization: Bearer <token>` and store the token in the OS keychain. Tokens expire after 30 days.

| Method and path | Request and result |
| --- | --- |
| `GET /catalog` | Annual plan price in cents and configured providers |
| `POST /tokens/register` or `POST /tokens` | `{email,password,device}` returns `token`, `token_type`, `expires_at`, and `user` |
| `DELETE /tokens/current` | Revokes the bearer token used for the request |
| `GET /me` | Account data and the `admin`, `pro` and `full_access` flags |
| `GET /instagram/connection` | Connected professional username and expiration, if active |
| `POST /imports` | `{platform,source}` queues an import and returns 202 and an import ID |
| `GET /imports` | Paginated imports owned by the account |
| `GET /imports/{id}` | Import `status`, `pages` processed and any provider message |
| `GET /imports/{id}/items` | Paginated media and downloadable qualities. Optional `type=photo|video|reel|story` |
| `POST /media/{id}/download` | `{quality:0}` streams the chosen variant as an attachment |
| `POST /exports` | `{import_id}` or `{items:[{id,quality}]}` queues a ZIP and returns 202 and an export ID |
| `GET /exports/{id}` | ZIP status, expiration and any skipped-file message |
| `GET /exports/{id}/download` | Streams the completed ZIP or redirects to a short-lived S3 link |
| `POST /checkout` | Accepts only `{plan:"pro-annual"}` and returns 503 while payments are disabled |

Send `cursor=<next_cursor>` to advance lists. A null cursor indicates the final page. Imports and exports report `queued`, `running`, `completed`, or `failed`. Poll while an operation is active and back off on 429. A YouTube item has a permalink and an empty `qualities` list. Use the variant IDs from the most recent item response. HTTP errors include `message`, with `errors` keyed by field for validation responses.

The browser authorizes Instagram through `POST /session/instagram/connect` and Meta's HTTPS callback. After the owner connects, web and mobile clients can create imports with `platform=instagram_pro` and `source=media` or `source=stories`. Disconnecting removes the stored provider token and preserves prior library entries.
