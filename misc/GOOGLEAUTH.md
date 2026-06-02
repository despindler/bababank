# Google Sign-In Integration Pattern From Grass Land

This document captures how Grass Land implemented Google sign-in so Codex can port the same approach into another PHP/API-backed web project.

## High-Level Architecture

Grass Land uses Google Identity Services on the frontend only to obtain an ID token credential. The backend verifies that token server-side before creating or linking a local account.

Flow:

1. Frontend calls `GET /api/auth-config`.
2. Backend returns `google_client_id` only when `GOOGLE_CLIENT_ID` is configured.
3. Frontend dynamically loads `https://accounts.google.com/gsi/client`.
4. Frontend renders the Google sign-in button.
5. Google returns an ID token credential to the browser callback.
6. Frontend posts `{ credential }` to `POST /api/google-login`.
7. Backend verifies the token signature and claims.
8. Backend finds, links, or creates a local user.
9. Backend logs the user in using the same app session mechanism as normal login.

The browser never decides who the user is. It only passes the Google ID token to the backend.

## Environment Configuration

Add these config values:

```env
GOOGLE_CLIENT_ID=
GOOGLE_JWKS_URL=https://www.googleapis.com/oauth2/v3/certs
```

`GOOGLE_CLIENT_ID` is the OAuth client ID from Google Cloud.

`GOOGLE_JWKS_URL` is where the backend fetches Google's public keys for token signature verification. Grass Land made this configurable for tests and future flexibility.

If `GOOGLE_CLIENT_ID` is empty, Google login is disabled and the frontend does not render the button.

## Database Schema

Grass Land extended the local `users` table with Google identity fields:

```sql
ALTER TABLE users
    ADD COLUMN google_sub VARCHAR(64) NULL,
    ADD COLUMN email VARCHAR(255) NULL,
    ADD COLUMN display_name VARCHAR(255) NULL,
    ADD UNIQUE KEY users_google_sub_unique (google_sub),
    ADD UNIQUE KEY users_email_unique (email);
```

Important decisions:

- `google_sub` is the stable Google account identifier and is the primary link key.
- `email` is unique and used to link a Google identity to an existing local account.
- `display_name` stores the Google profile name.
- Local accounts and Google accounts share the same `users` table.
- Google users still get a local `username`, `role`, and session.

## Backend Routes

Grass Land added two auth-related endpoints.

### `GET /api/auth-config`

Returns public auth configuration:

```json
{
  "ok": true,
  "google_client_id": "client-id.apps.googleusercontent.com"
}
```

If Google login is disabled:

```json
{
  "ok": true,
  "google_client_id": null
}
```

This lets the frontend decide whether to load Google Identity Services.

### `POST /api/google-login`

Request:

```json
{
  "credential": "google-id-token"
}
```

Success response:

```json
{
  "ok": true,
  "user": {
    "id": 123,
    "username": "google_player",
    "role": "user"
  }
}
```

Failure examples:

```json
{
  "ok": false,
  "error_code": "GOOGLE_CREDENTIAL_REQUIRED",
  "message": "Google credential is required."
}
```

```json
{
  "ok": false,
  "error_code": "INVALID_GOOGLE_TOKEN",
  "message": "Google credential is invalid."
}
```

## Backend Verification

Grass Land uses a dedicated interface:

```php
interface GoogleTokenVerifier
{
    /**
     * @return array{sub: string, email: string, email_verified: bool, name: string}
     */
    public function verify(string $credential): array;
}
```

The production implementation is `GoogleIdTokenVerifier`.

It verifies:

- `GOOGLE_CLIENT_ID` is configured.
- The credential is a JWT with exactly three parts.
- Header uses `alg = RS256`.
- Header contains a `kid`.
- Payload `aud` equals the configured `GOOGLE_CLIENT_ID`.
- Payload `iss` is either `accounts.google.com` or `https://accounts.google.com`.
- Payload `exp` has not expired.
- Payload `email_verified` is true.
- JWT signature is valid using Google's JWKS public keys.

The verified identity returned to the auth controller contains:

```php
[
    'sub' => (string) ($payload['sub'] ?? ''),
    'email' => (string) ($payload['email'] ?? ''),
    'email_verified' => true,
    'name' => (string) ($payload['name'] ?? ''),
]
```

## Signature Verification

Grass Land verifies the JWT signature server-side.

Process:

1. Decode JWT header and payload using base64url decoding.
2. Read `kid` from the JWT header.
3. Fetch JWKS from `GOOGLE_JWKS_URL`.
4. Find the matching key by `kid`.
5. Convert the JWK/certificate to a PEM public key.
6. Run `openssl_verify()` against `base64url(header) + "." + base64url(payload)` using the decoded JWT signature and `OPENSSL_ALGO_SHA256`.

If verification fails, return a stable auth error such as:

```text
INVALID_GOOGLE_SIGNATURE
GOOGLE_KEYS_UNAVAILABLE
GOOGLE_OPENSSL_UNAVAILABLE
```

## User Linking And Creation

After the token is verified, Grass Land uses this order:

1. Find a user by `google_sub`.
2. If none exists, find a user by verified email.
3. If a local user with that email exists, link the Google identity to it.
4. If no user exists, create a new local user.
5. Log the user in through the normal session mechanism.

Pseudo-code:

```php
$identity = $googleTokenVerifier->verify($credential);

$user = $users->findByGoogleSub($identity['sub']);

if ($user === null && $identity['email'] !== '') {
    $user = $users->findByEmail($identity['email']);

    if ($user !== null) {
        $users->linkGoogleIdentity(
            (int) $user['id'],
            $identity['sub'],
            $identity['email'],
            $identity['name']
        );

        $user = $users->findById((int) $user['id']);
    }
}

if ($user === null) {
    $user = $users->createGoogleUser(
        $identity['sub'],
        $identity['email'],
        $identity['name']
    );
}

$currentUser->login((int) $user['id']);
```

## Google Username Generation

For newly created Google users, Grass Land derives a local username from the email prefix.

Example:

```text
google.player@example.com -> google_player
```

Rules:

- Lowercase.
- Replace non-`[a-z0-9_]` characters with `_`.
- Trim leading/trailing underscores.
- Fallback to `google_user`.
- Limit base length to 24 chars.
- If taken, append `_1`, `_2`, and so on.

Pseudo-code:

```php
$base = strtolower((string) preg_replace('/[^a-z0-9_]+/', '_', explode('@', $email)[0] ?? 'google_user'));
$base = trim($base, '_') ?: 'google_user';
$base = substr($base, 0, 24);

$candidate = $base;
$suffix = 1;

while ($users->findByUsername($candidate) !== null) {
    $candidate = substr($base, 0, 24) . '_' . $suffix;
    $suffix++;
}
```

Grass Land stores a placeholder password hash for Google-created users:

```php
'google:' . hash('sha256', $googleSub)
```

This is not used for password login; it only satisfies the existing schema requirement.

## Frontend Integration

HTML includes a hidden placeholder:

```html
<div id="google-login" class="google-login is-hidden"></div>
```

The frontend loads Google sign-in only if the backend exposes a client ID:

```js
async function loadGoogleSignIn() {
  const payload = await api('/api/auth-config', {
    headers: { Accept: 'application/json' },
  });

  if (!payload.google_client_id) {
    return;
  }

  await loadScript('https://accounts.google.com/gsi/client');

  window.google.accounts.id.initialize({
    client_id: payload.google_client_id,
    callback: async (response) => {
      try {
        const login = await api('/api/google-login', {
          method: 'POST',
          body: JSON.stringify({ credential: response.credential }),
        });

        renderUser(login.user);
        setMessage('Logged in with Google.', false);
      } catch (error) {
        setMessage(error.message, true);
      }
    },
  });

  googleLogin.classList.remove('is-hidden');

  window.google.accounts.id.renderButton(googleLogin, {
    theme: 'outline',
    size: 'large',
    width: 260,
  });
}
```

The shared API helper sends cookies so Google login establishes the same app session as normal login:

```js
async function api(path, options = {}) {
  const response = await fetch(path, {
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
    },
    ...options,
  });

  const payload = await response.json();

  if (!response.ok || payload.ok === false) {
    const error = new Error(payload.message || 'Request failed.');
    error.code = payload.error_code || '';
    throw error;
  }

  return payload;
}
```

## Error Handling

Grass Land keeps Google auth failures explicit and stable.

Examples:

```text
GOOGLE_LOGIN_NOT_CONFIGURED
GOOGLE_CREDENTIAL_REQUIRED
INVALID_GOOGLE_TOKEN
GOOGLE_AUDIENCE_MISMATCH
INVALID_GOOGLE_ISSUER
GOOGLE_TOKEN_EXPIRED
GOOGLE_EMAIL_NOT_VERIFIED
GOOGLE_KEYS_UNAVAILABLE
GOOGLE_OPENSSL_UNAVAILABLE
INVALID_GOOGLE_SIGNATURE
AUTH_STORAGE_FAILED
DB_CONFIG_MISSING
```

The verifier throws a `GoogleAuthFailedException` carrying an `errorCode`.

The controller converts that to a JSON `401` response.

## Dependency Injection For Testing

Grass Land injects `GoogleTokenVerifier` into the auth controller/application.

Production uses:

```php
new GoogleIdTokenVerifier($config)
```

Tests use a fake verifier:

```php
final class FakeGoogleTokenVerifier implements GoogleTokenVerifier
{
    public function __construct(
        private readonly ?array $identity,
        private readonly ?GoogleAuthFailedException $exception = null
    ) {
    }

    public function verify(string $credential): array
    {
        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->identity ?? [
            'sub' => 'google-sub-1',
            'email' => 'player@example.com',
            'email_verified' => true,
            'name' => 'Google Player',
        ];
    }
}
```

This lets tests cover login behavior without depending on Google or the network.

## Tests To Port

Add tests for:

1. Google login creates a local user and establishes a session.
2. Repeated Google login reuses the linked user.
3. Invalid Google token returns `401` with a stable error code.
4. Verifier reports key-fetch failure as `GOOGLE_KEYS_UNAVAILABLE`.
5. Verifier can convert RSA JWK keys to PEM.
6. Existing local user with the same verified email gets linked instead of duplicated.

## Security Notes

Do:

- Verify ID tokens on the backend.
- Check `aud` against the configured client ID.
- Check `iss`.
- Check `exp`.
- Require verified email.
- Verify the RS256 signature using Google's public keys.
- Use `google_sub` as the stable identity key.
- Keep normal app sessions server-side.
- Return only public user fields to the browser.

Do not:

- Trust decoded browser JWT payloads without backend verification.
- Use email alone as the permanent Google identity.
- Store Google credentials as passwords.
- Expose secrets in frontend config.
- Render the Google button when no client ID is configured.

## Minimal Porting Checklist

1. Add `GOOGLE_CLIENT_ID` and `GOOGLE_JWKS_URL` config.
2. Add `google_sub`, `email`, and `display_name` columns to `users`.
3. Add `GET /api/auth-config`.
4. Add `POST /api/google-login`.
5. Implement `GoogleTokenVerifier`.
6. Implement server-side ID token verification.
7. Add user lookup by `google_sub` and `email`.
8. Add user creation/linking logic.
9. Reuse the existing session login mechanism.
10. Add the hidden frontend Google button container.
11. Load `https://accounts.google.com/gsi/client` dynamically.
12. Render the GIS button only when `google_client_id` is present.
13. Post `response.credential` to the backend.
14. Add fake-verifier tests for auth flow.
15. Add verifier tests for token validation failures.
