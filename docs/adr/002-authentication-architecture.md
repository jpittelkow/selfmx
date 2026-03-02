# ADR-002: Authentication Architecture

## Status

Accepted

## Date

2026-01-24

## Context

selfmx needs a robust authentication system that supports:
- Traditional email/password authentication
- Session-based security for SPA applications
- Integration with SSO providers
- Two-factor authentication
- Self-hosted deployments with optional features

The system must be secure, flexible, and suitable for both single-user self-hosted instances and multi-user enterprise deployments.

## Decision

We will use **Laravel Sanctum** with session-based authentication for the SPA frontend.

### Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    Authentication Flow                       │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  1. User submits credentials (email/password or SSO)        │
│                          │                                   │
│                          ▼                                   │
│  2. Laravel validates credentials                           │
│                          │                                   │
│                          ▼                                   │
│  3. Check if 2FA is enabled                                 │
│         │                                                    │
│    ┌────┴────┐                                              │
│    │ No 2FA  │─────────────────────┐                        │
│    └─────────┘                     │                        │
│    │ Has 2FA │                     │                        │
│    └────┬────┘                     │                        │
│         ▼                          │                        │
│  4. Verify TOTP/Recovery code      │                        │
│                          │         │                        │
│                          ▼         ▼                        │
│  5. Create session, set cookie                              │
│                          │                                   │
│                          ▼                                   │
│  6. Return user data + CSRF token                           │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

### Session-Based vs Token-Based

We chose session-based authentication because:
- Simpler to implement and maintain
- Better CSRF protection with Laravel's built-in middleware
- Tokens stored server-side (more secure)
- Automatic session expiration
- Works seamlessly with Laravel's auth guards

### Key Components

1. **AuthController** - Login, logout, registration, password reset
2. **SSOController** - OAuth2/OIDC provider callbacks
3. **TwoFactorController** - 2FA setup and verification
4. **ProfileController** - User profile management

### API Endpoints

```
POST /api/auth/register      - User registration
POST /api/auth/login         - Login with email/password
POST /api/auth/logout        - Session termination
POST /api/auth/forgot        - Password reset request
POST /api/auth/reset         - Password reset confirmation
POST /api/auth/verify        - Email verification
GET  /api/auth/user          - Current user info
POST /api/auth/2fa/verify    - 2FA code verification
```

### Security Measures

- Password hashing with bcrypt (cost factor 12)
- Rate limiting on login attempts (5/minute)
- CSRF protection on all mutating endpoints
- Secure session cookies (HttpOnly, SameSite=Lax)
- Remember me tokens with 30-day expiration

## Consequences

### Positive

- Sanctum is well-maintained and Laravel-native
- Session-based auth is simpler to understand and debug
- Automatic CSRF protection
- Easy to add new authentication methods
- Works with Laravel's authorization policies

### Negative

- Sessions require server-side storage
- Not suitable for pure mobile app backends (would need tokens)
- Cross-domain authentication requires additional configuration

### Neutral

- Frontend must include CSRF token in requests
- Session cookie must be sent with every request

## Related Decisions

- [ADR-001: Technology Stack](./001-technology-stack.md)
- [ADR-003: SSO Provider Integration](./003-sso-provider-integration.md)
- [ADR-004: Two-Factor Authentication](./004-two-factor-authentication.md)

## Notes

Laravel Sanctum's session authentication works by:
1. Setting the `SANCTUM_STATEFUL_DOMAINS` config to include the frontend domain
2. Using the `EnsureFrontendRequestsAreStateful` middleware
3. The frontend calls `/sanctum/csrf-cookie` before authentication
4. All subsequent requests include the XSRF-TOKEN cookie
