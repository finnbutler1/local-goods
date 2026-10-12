# 0007. Laravel Fortify for login, and one role column instead of a permissions package

**Status:** Accepted, implemented.

## Context

The demo pages were open to anyone, and every approve/reject decision was
recorded as a made-up "Sam Taylor (demo)". We need real logins for six kinds
of user (platform admin, BID admin, employer admin, employee, merchant,
judge), with accounts created by an admin only.

## Decision

- **Laravel Fortify** provides login, logout, password reset and login rate
  limiting. It is Laravel's first-party backend for adding login to an
  *existing* app: it ships no pages, so the login screens use our own design
  system. Laravel 13's starter kits (React, Vue, Livewire) are built for new
  apps and would replace our layouts; Breeze is no longer updated.
- **No registration.** Fortify's registration feature is simply left off, so
  `/register` does not exist. Demo logins are created by
  `php artisan demo:seed-accounts` from `DEMO_PASSWORD_*` environment settings.
- **Logins are limited** to five attempts a minute per email and IP address.
- **One `users.role` column** (a PHP enum, `App\Enums\Role`), a `role:` route
  middleware and `RedemptionPolicy` (only BID admins and the platform admin
  may approve or reject). No permissions package: six fixed roles don't need
  one.
- **No email yet.** Production has no mail service, so when the mailer is
  `log` or unset the login page says "Contact the admin to reset your
  password" instead of offering a reset link that would never arrive.

## Consequences

- Each decision on a held redemption records the signed-in person's name and
  account (`redemptions.reviewed_by_user_id`).
- Judges can be given an account that can look but never decide.
- Adding a mail service (a paid service) would turn on self-service password
  reset with no code change.
