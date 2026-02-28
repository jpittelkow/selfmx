# Auth UI Redesign Roadmap

Modernize the login, registration, and related authentication pages with improved visual design and UX.

**Priority**: MEDIUM
**Status**: Completed (Core Done)
**Last Updated**: 2026-02-27

**Remaining Work**: Optional visual polish (illustrations, page transitions, cross-browser testing)

---

## Overview

Update the authentication pages (login, register, forgot password, reset password, verify email) with a modern, polished design that creates a strong first impression and improves usability.

## Current State

- [x] Functional auth pages with form validation
- [x] SSO buttons integration
- [x] Two-factor authentication flow
- [x] Responsive centered card layout
- [x] Modern visual design (shadcn login-03 pattern: `bg-muted` + Card)
- [ ] Engaging imagery/illustrations
- [x] Password strength indicator
- [x] Enhanced form interactions (password toggle, email availability)

## Known Issues

- [x] Page title not showing on login page (fixed via AuthPageLayout + usePageTitle)
- [x] Page title not showing on register page (fixed via AuthPageLayout + usePageTitle)
- [x] Review other auth pages for missing page titles (all auth pages use usePageTitle via AuthPageLayout)

## Auth Pages

| Page | File |
|------|------|
| Login | `frontend/app/(auth)/login/page.tsx` |
| Register | `frontend/app/(auth)/register/page.tsx` |
| Forgot Password | `frontend/app/(auth)/forgot-password/page.tsx` |
| Reset Password | `frontend/app/(auth)/reset-password/page.tsx` |
| Verify Email | `frontend/app/(auth)/verify-email/page.tsx` |
| Layout | `frontend/components/auth/auth-page-layout.tsx` |

## Phase 1: Layout Redesign

Update the `AuthPageLayout` with a modern split-screen or enhanced card design.

### Adopted: shadcn login-03 Pattern

- [x] `bg-muted` full-page surface
- [x] Logo centered above Card
- [x] Card with title/description CardHeader
- [x] Consistent across all auth pages (login, register, forgot-password, reset-password, verify-email, SSO callback)
- [x] `AuthStateCard` updated to match the same pattern
- [x] `AuthDivider` uses `bg-card` for correct rendering inside Card

### Tasks

- [x] Design mockup/decision on layout approach
- [x] Update `AuthPageLayout` component to login-03 pattern
- [x] Update standalone auth pages (forgot-password, reset-password) to match
- [x] Update `AuthStateCard` to use `bg-muted` + logo-above-card layout
- [x] Replace raw checkbox with shadcn `Checkbox` on login page
- [x] Improve spacing and typography

## Phase 2: Form Enhancements

Improve the form UX and visual feedback.

### Tasks

- [x] Password visibility toggle (show/hide password)
- [x] Password strength indicator on registration
  - [x] Visual bar showing weak/medium/strong
  - [x] Requirements checklist (8+ chars, uppercase, number, etc.)
- [ ] Improved input styling with icons (email icon, lock icon)
- [x] Better focus states and transitions
- [x] Inline validation feedback (real-time email availability)
- [x] Loading states with skeleton/shimmer (SSO redirect)

## Phase 3: Visual Polish

Add visual elements that create a memorable experience.

### Tasks

- [ ] Custom illustrations or graphics
  - [ ] Option: Abstract shapes/patterns
  - [ ] Option: Character illustrations
  - [ ] Option: Product screenshots
- [ ] Smooth page transitions between auth pages
- [ ] Micro-interactions (button hover, input focus)
- [ ] Dark mode optimization
- [ ] Brand-consistent color usage

## Phase 4: SSO & Social Login

Enhance the SSO button presentation.

### Tasks

- [x] Improved SSO button styling with provider logos
- [x] Better visual hierarchy (SSO vs email login)
- [x] "Continue with" language consistency
- [x] Loading states for SSO redirects

## Phase 5: Accessibility & Polish

Final accessibility and cross-browser polish.

### Tasks

- [x] ARIA labels and screen reader testing
- [x] Keyboard navigation testing
- [x] Focus management (autofocus, tab order)
- [x] Error announcement for screen readers
- [ ] Cross-browser testing
- [x] Mobile touch target sizes (48x48 min for SSO buttons)

## Design Pattern

Using **shadcn login-03**: Muted background with centered branding above a clean Card. This is the pattern used by most modern SaaS products (GitHub, Linear, Vercel). All auth pages follow this pattern for visual consistency.

## Key Files

| File | Changes |
|------|---------|
| `frontend/components/auth/auth-page-layout.tsx` | login-03 pattern (bg-muted + Card) |
| `frontend/components/auth/auth-state-card.tsx` | Matching bg-muted + logo-above-card layout |
| `frontend/components/auth/sso-buttons.tsx` | Enhanced styling |
| `frontend/components/auth/auth-divider.tsx` | bg-card (renders inside Card) |
| `frontend/components/ui/password-input.tsx` | New: password toggle |
| `frontend/components/ui/password-strength.tsx` | New: strength indicator |
| `frontend/app/(auth)/login/page.tsx` | Use new components |
| `frontend/app/(auth)/register/page.tsx` | Use new components |
| `frontend/app/(auth)/forgot-password/page.tsx` | Visual updates |
| `frontend/app/(auth)/reset-password/page.tsx` | Visual updates |
| `frontend/app/(auth)/verify-email/page.tsx` | Visual updates |

## Dependencies

- None (uses existing shadcn/ui components)
- Optional: Illustration library or custom graphics

## Success Criteria

- [ ] Lighthouse accessibility score 90+
- [ ] Consistent with overall app branding
- [ ] Mobile-first responsive design
- [ ] Positive user feedback on visual design
- [ ] No regression in form functionality
