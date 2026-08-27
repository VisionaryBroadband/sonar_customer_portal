# Divergence Map: what this fork changed vs. upstream

See [README.md](README.md) for scope/freshness notes. Rendered version (styled,
easier to scan visually): ask in a Claude Code session to reopen the "Divergence
Map" artifact, or regenerate from this file.

**Fork point (confirmed via `git merge-base master upstream-official/master`):** `16d041c15eeeef0d19817ff98a3fc8b9d6b76d45` ("Update README.md")
**Comparison:** `git diff 16d041c1..master` — 101 files changed, 4668 insertions(+), 2955 deletions(-)
**Local remote already configured:** `upstream-official` → `/Users/cworthen/repos/customer_portal_official` (tracks `upstream-official/master`)

---

## TL;DR — distinct customizations

1. **Laravel 5.5 → 6.0 upgrade** (plus PHP 7.0→7.4, related package major bumps) — large mechanical diff underlying almost everything else.
2. **Full authentication rewrite**: local Eloquent `User` model replaced with a Sonar-API-backed custom `UserProvider` (`SonarUserProvider`) and a non-Eloquent `User` DTO; login/register/reset split into new controllers using Laravel's built-in auth scaffolding conventions instead of the old custom ones.
3. **New `app/Actions` + `app/DataTransferObjects` architectural layer** — ticketing (create ticket, create reply, update ticket status), account status updates, payment submission handling. Controllers now delegate to these rather than doing Sonar API calls inline.
4. **New payment→account-reactivation feature**: `PaymentSuccessfullySubmittedEvent` → `ReactivatesAccountOnPaymentListener`, which reactivates a suspended/collections account after a successful online payment and (new) emails a configurable "alerts recipient" when a delinquent/inactive customer pays.
5. **Sonar API client swap**: from an embedded/ad-hoc client to the extracted `seankndy/fluent-sonar-api` package (GraphQL-based), plus `gmostafa/php-graphql-client` and `spatie/data-transfer-object` as new dependencies. `sonarsoftwareinc/customer_portal_framework` repo source changed to `seankndy/customer_portal_framework` fork, `dev-master`.
6. **Language/localization rewrite**: `LanguageServiceProvider`/`LanguageService` removed; replaced by `Localization` middleware (renamed from `Language`) + `SetPortalUserLanguage` action, with per-username language persisted via `UsernameLanguage` (SQLite) rather than the old service.
7. **Docker/deploy modernization**: PHP 7.3→7.4, `phusion/baseimage` bumped, nginx PPA change, Docker Hub image renamed from `sonarsoftware/customerportal` to `seankndy/sonarcustomerportal`, `install.sh` now prompts for a GraphQL API token and defaults to Redis for queue/session.
8. **New `system_settings.mail_alerts_recipient` column** wired into `config('mail.alerts_recipient')`, consumed by the reactivation listener's payment notification.
9. **Misc UX/business-logic tweaks** (from commit log): ticket pagination + Closed tab, show ticket numbers, only show public tickets, don't prorate on account status update, swap routing/account number field order, one query instead of two after login, payment tracker ID in billing controller.
10. **In-progress uncommitted work on top of `master`** (staged, not committed): reverse-proxy/Traefik deployment support — configurable `TRUSTED_PROXIES` env driving `config/trustedproxy.php`, an HTTP-only nginx template + `DISABLE_SSL` toggle in `90_init_nginx.sh`, and a new `docker-compose-proxy.yml` for running behind Traefik with Redis. Also adds this repo's `CLAUDE.md`.
11. **Unmerged branches exist** (see bottom) — out of scope for this analysis, which only covers `master`.

---

## 1. Laravel 5.5 → 6.0 upgrade (foundational, mechanical)

**Files:** `composer.json`, `composer.lock`, `config/app.php`, `config/cache.php`, `config/session.php`, `config/hashing.php` (new), `config/logging.php` (new), `app/Exceptions/Handler.php`, `app/Http/Kernel.php`, `app/Providers/RouteServiceProvider.php`, `webpack.mix.js`, plus most controller/middleware files touch minor Laravel 6 API changes.

**What it is:** Standard incremental Laravel upgrade (per commit log: 5.5→5.6→5.7→5.8→6.0, each its own commit), PHP `>=7.0` → `>=7.4`, `laravelcollective/html` 5.5→6.0, `proengsoft/laravel-jsvalidation` 1.x→4.5, `fideloper/proxy` 3.0→4.0, `phpunit` 6→7. `config/hashing.php` and `config/logging.php` are new because Laravel 6 externalized those from `config/app.php`.

**Risk for merge:** **Needs-careful-merge, but it's the safe kind of conflict** — if upstream has since done its own Laravel upgrade to the same or later version, this whole category may already be reconciled upstream and this diff shrinks a lot. If upstream is still on 5.5, every file in this fork that touches Laravel-version-specific code will conflict. Worth checking upstream's current Laravel version first — it materially changes the whole merge strategy.

> **Resolved by collision-report.md:** upstream is on Laravel `^10.0` / PHP `^8.2` — four majors ahead, not already reconciled. See collision-report.md §1.

---

## 2. Authentication rewrite (HIGH RISK — genuine behavioral change, not a rename)

**Deleted:** `app/Http/Controllers/AuthenticationController.php`, `app/Http/Controllers/Auth/ForgotPasswordController.php`, `RegisterController.php`, `ResetPasswordController.php`, `app/Http/Middleware/AuthMiddleware.php`, `app/Http/Middleware/PortalAuth.php`.
**Added:** `app/Extensions/SonarUserProvider.php`, `app/Http/Middleware/Authenticate.php`, `app/Http/Controllers/Auth/RegistrationController.php`, `app/Http/Controllers/Auth/PasswordResetController.php`, `app/Listeners/CacheAuthenticatedUser.php`.
**Modified:** `app/User.php`, `app/Providers/AuthServiceProvider.php`, `config/auth.php`, `app/Http/Controllers/Auth/LoginController.php`.

**What changed, concretely:**
- `App\User` used to `extend Illuminate\Foundation\Auth\User` (Eloquent). It's now a **plain class implementing `Authenticatable` directly** (not Eloquent at all), built via `User::fromSonarContactResource(Contact $contact)` from a Sonar GraphQL `Contact` resource. `getAuthPassword()`, `getRememberToken()` etc. are stubbed/no-op since there's no local password or remember-token storage.
- New `App\Extensions\SonarUserProvider implements UserProvider`: `retrieveById`/`retrieveByCredentials` query Sonar for a `Contact` where `username = ...` and `contactable_type = Account`, caching under the `users` cache tag; `validateCredentials` shells out to `AccountAuthenticationController::authenticateUser()` from `customer_portal_framework` (GraphQL doesn't support credential validation directly — noted in the code).
- `config/auth.php`: the `users` provider driver changed from `eloquent` (backed by `App\User` model / DB) to a custom `sonar` driver, registered in `AuthServiceProvider::boot()` via `Auth::provider('sonar', fn() => app(SonarUserProvider::class))`.
- New `CacheAuthenticatedUser` listener on Laravel's built-in `Illuminate\Auth\Events\Login` event, caching the authenticated user for 15 minutes — this is what makes the "one query instead of two after login" optimization (commit `8390ae1`) work.
- Controllers were reorganized to Laravel's stock `Auth\LoginController` / `RegistrationController` / `PasswordResetController` convention (using Laravel's built-in `AuthenticatesUsers`/`RegistersUsers` traits) rather than the old bespoke `AuthenticationController` + `AuthMiddleware`/`PortalAuth` combo.
- `2226d65 split login/logout, register, and password reset into separate controllers` and `07eba43 user authentication now uses laravel's auth framework` are the pivotal commits — this was a deliberate move from an entirely custom auth stack to Laravel's standard `Auth` facade/guard system, just backed by a non-Eloquent, Sonar-backed provider instead of the database.

**Why this matters for merging:** if upstream has *also* since modernized its auth (e.g. moved to Laravel's standard guard system, or made its own Sonar-backed provider), reconciling business logic (which Sonar fields map to which User properties, cache TTL, credential validation error handling) needs careful side-by-side review, not a blind merge — auth is exactly where silently losing a check (e.g. dropping the `contactableType !== 'Account'` guard in `User::fromSonarContactResource`) is a security regression. This is the single highest-risk area in the whole diff.

> **Resolved by collision-report.md:** upstream did NOT modernize auth — it kept the exact deleted architecture and used it to ship a password-policy fix and a throttle-hash fix this fork never received. See collision-report.md §2 — this is the most important finding in the whole merge-planning effort.

---

## 3. New Actions / DataTransferObjects architecture (introduced by this fork)

**Added:**
- `app/Actions/CreateAccountTicketAction.php`, `CreateTicketReplyAction.php`, `UpdateTicketStatusAction.php`, `UpdateAccountStatusAction.php`, `SetPortalUserLanguage.php`
- `app/DataTransferObjects/AccountTicketData.php`, `TicketReplyData.php`, `PaymentSubmission.php`

**What it is:** Confirmed via `git diff` these did not exist upstream at the fork point. This is a deliberate pattern (matches this repo's current `CLAUDE.md`, which documents "controllers delegate to `app/Actions/*`" as house style) introduced entirely by this fork on top of the upstream architecture, using `spatie/data-transfer-object` (a new dependency) for typed request payloads. Example: `CreateAccountTicketAction::__invoke(AccountTicketData $ticketData)` builds and runs a Sonar GraphQL mutation via the new fluent Sonar API client's `InputBuilder`.

**Risk for merge:** **Additive-safe** in isolation (new files, no upstream equivalent to conflict with) — but the *controllers* that now call into these Actions (`TicketController`, `BillingController`) are modified files, so if upstream has changed the same controller methods, expect conflicts there. The Action/DTO classes themselves are low risk since upstream has nothing to conflict against.

---

## 4. Payment → account reactivation feature (new, end-to-end)

**Added:** `app/Events/PaymentSuccessfullySubmittedEvent.php`, `app/Listeners/ReactivatesAccountOnPaymentListener.php`, `app/Mail/CustomerPayedBill.php`, `resources/views/emails/customer_payed_bill.blade.php`, `app/DataTransferObjects/PaymentSubmission.php`, `app/Actions/UpdateAccountStatusAction.php`.
**Modified:** `app/Providers/EventServiceProvider.php` (registers the listener), `app/Providers/AppServiceProvider.php` (maps `mail_alerts_recipient` setting to `config('mail.alerts_recipient')`), `database/migrations/2022_04_18_223325_modify_system_settings_add_mail_alerts_recipient.php` (new nullable `mail_alerts_recipient` string column on `system_settings`).

**What it does:** When a payment is submitted successfully, `PaymentSuccessfullySubmittedEvent` fires. The queued listener (`ShouldQueue`) checks Sonar for remaining delinquent invoices on the account; if none, and the account's current status is `Inactive / Collections` or `On Hold`, it reactivates the account to status ID `1` via `UpdateAccountStatusAction`. If the account was `Inactive` or `Inactive / Collections` specifically, it also emails the ISP's configured "alerts recipient" (a new `/settings`-editable field) via `CustomerPayedBill` mailable, so billing admins get notified a delinquent customer paid online. Commit log shows this went through several iterations (`c5b2a64` initial, `8f434ef` register listener, `d866e1c`/`fc617d1`/`b9197d4` progressively better logging, `bafcd29` added the email-alert feature) — it's mature, exercised code, not a first draft.

**Risk for merge:** **Additive-safe.** All new files; only shared touch points are `EventServiceProvider` and `AppServiceProvider`, both small, well-isolated additions. Low conflict risk unless upstream added its own reactivation logic in the same spot.

---

## 5. Sonar API client: embedded code → `seankndy/fluent-sonar-api` package

**Evidence:** Commit log has ~15 commits (`dfdd9b2` through `7e3fe38`, `b07f21c`, `e5fade3`, etc.) building out a fluent, GraphQL-based Sonar API client (querybuilder, resource interfaces, typed inputs) *inside this repo*, then `ee8580a moved sonar api client to seankndy/fluent-sonar-api` extracted it to its own Composer package, followed by `077e2a2 upgraded seankndy/fluent-sonar-api to v2`. None of that client code remains in-tree at `master` (not in the 101-file diff) — it now lives purely as a `composer.json` dependency (`"seankndy/fluent-sonar-api": "^2.0"`).

**composer.json new/changed dependencies (not just version bumps):**
- `seankndy/fluent-sonar-api` (new — the GraphQL client)
- `gmostafa/php-graphql-client` (new — underlying GraphQL transport)
- `spatie/data-transfer-object` (new — used by the Actions/DTO layer above)
- `sonarsoftwareinc/customer_portal_framework` changed from `^1.0.23` pinned to a Sonar-owned VCS repo, to `dev-master` pointed at `https://github.com/seankndy/customer_portal_framework` (a fork) — **this is a meaningful supply-chain change**, not just a version bump: the fork now tracks a fork-of-a-fork's `dev-master` branch rather than a tagged release of the official package.
- `laravel/framework` `5.5.*` → `^6.0`, `laravelcollective/html` `^5.5`→`^6.0`, `proengsoft/laravel-jsvalidation` `^1.6.0-dev`→`^4.5`, `fideloper/proxy` `~3.0`→`^4.0`, `phpunit/phpunit` `~6.0`→`^7.0` — version bumps tied to the Laravel 6 upgrade (section 1).

**Risk for merge:** **Needs-careful-merge.** Every controller/action that talks to Sonar now uses this fork's client library and its API shape (`$sonarClient->tickets()->where(...)->get()` style), which is architecturally different from whatever upstream is using. If upstream has stayed on the older client or the official `customer_portal_framework`, business-logic reconciliation (not just line conflicts) will be needed anywhere both sides touched Sonar-calling code. **Also worth flagging to the user directly: the `customer_portal_framework` dependency now points at a third-party fork on `dev-master` (a moving, non-tagged branch) instead of the official Sonar-maintained package** — that's a decision worth confirming is still intentional before an upstream merge, independent of the merge mechanics.

> **Resolved by collision-report.md:** upstream still uses the official `customer_portal_framework` at a tagged `^2.0.6` release — confirming this is a standing, deliberate identity fork, not a temporary state. See collision-report.md §5.

---

## 6. Language/localization rewrite

**Deleted:** `app/Providers/LanguageServiceProvider.php`, `app/Services/LanguageService.php`.
**Renamed with rewrite:** `app/Http/Middleware/Language.php` → `Localization.php` (R063 — 63% similar, so substantial changes beyond the rename).
**Added:** `app/Actions/SetPortalUserLanguage.php`.
**Modified:** `app/Helpers/functions.php` (this is where `utrans()` lives, per this repo's `CLAUDE.md`).

**What changed:** The old `LanguageServiceProvider`/`LanguageService` pair is gone entirely, replaced by the `Localization` middleware plus a `SetPortalUserLanguage` action. Per `CLAUDE.md`, the current model is: user language resolves from Sonar contact preference → cookie → app default, persisted per-username in the local `username_languages` SQLite table. Commit log shows this took a few passes (`afb6f62 wip`, `e74f809 refactor user language handling`, `c56fcdc`/`b69f0a3 few more tweaks it localization`) — iterative, not a single clean rewrite.

**Risk for merge:** **Needs-careful-merge** if upstream also touched language handling (likely, since `resources/lang/{en,fr}` files are modified on both the fork's commits and probably upstream's ongoing translation updates) — but the *code* (middleware/service/action) side is more isolated and should merge cleanly as an additive replacement unless upstream renamed/changed the same provider.

> **Resolved by collision-report.md:** upstream's language files each received only one commit since the fork point — low drift. See collision-report.md §6.

---

## 7. Ticketing feature/UX tweaks

**Files:** `app/Http/Controllers/TicketController.php`, `app/Http/Requests/TicketRequest.php`, `resources/views/pages/tickets/{create,index,show}.blade.php`, `resources/lang/{en,fr}/tickets.php`, plus the `CreateAccountTicketAction`/`CreateTicketReplyAction`/`UpdateTicketStatusAction`/DTOs from section 3.

**What it does (per commit log + CLAUDE.md context):**
- `710227f show ticket numbers in ticketing section` — surfaces the Sonar ticket number in the UI, not just internal ID.
- `5013c24 only show public tickets` — filters the ticket list to exclude internal/non-customer-visible tickets.
- `14e69c7 added pagination and a Closed tab to tickets` — adds a paginated, tabbed (Open/Closed) ticket list.
- `7569bbd added ability to close/reopen tickets` — customer-facing ticket status toggling, backed by `UpdateTicketStatusAction`.
- `ca3dce9 created actions for creating tickets and ticket replies; refactored ticketcontroller to use them` — the Actions-layer refactor from section 3, specifically for ticketing.

**Risk for merge:** **Needs-careful-merge** — `TicketController` and the ticket Blade views are exactly the kind of file upstream is also likely to have touched (ticketing is core portal functionality), so expect real conflicts requiring behavior reconciliation, not just whitespace/import conflicts.

> **Resolved by collision-report.md:** upstream's `TicketController` had exactly one, mechanical commit since the fork point — actual risk is lower than assumed here. See collision-report.md §7.

---

## 8. Billing/account misc changes

**Files:** `app/Http/Controllers/BillingController.php`, `app/Traits/ListsPaymentMethods.php`, `app/Billing/GoCardless.php`, `resources/views/pages/billing/add_bank.blade.php`.

**What changed (per commit log):**
- `3481ce1 swap positions of routing number and account number` — bank-account form field order UX fix.
- `00c1dd0 add payment tracker id to billing controller` — likely threading a Sonar payment-tracking identifier through to the payment submission flow (feeds `PaymentSubmission` DTO / the reactivation listener in section 4).
- `45303d7 dont prorate when updating account status` — business-logic change in `UpdateAccountStatusAction` to skip Sonar's proration behavior on status changes (relevant to the reactivation flow, so a delinquent-account reactivation doesn't trigger an unwanted prorated charge/credit).
- `bae80ba ensure amount passed into PaymentSubmission is integer` — defensive type-correctness fix (Sonar's API likely expects cents as an int; a float/string could cause silent rounding or API errors).

**Risk for merge:** **Needs-careful-merge** for `BillingController` (core, likely touched upstream too); **additive-safe** for the account-status/proration behavior since it lives inside this fork's own `UpdateAccountStatusAction`.

> **Resolved by collision-report.md:** confirmed and worse than expected — upstream's `BillingController` has 34 commits and is nearly a full rewrite (Canada/XCD/FCC-label work). See collision-report.md §8.

---

## 9. Deploy / Docker / infra changes (committed, on `master`)

**Files:** `Dockerfile`, `docker-compose.yml`, `deploy/90_init_fpm.sh`, `deploy/conf/nginx/sonar-customerportal.template`, `deploy/services/php-fpm.sh`, `install.sh`, `prod-deploy-instructions.txt` (new).

**What changed:**
- PHP 7.3 → 7.4 throughout (`Dockerfile` package names, php-fpm socket paths in `90_init_fpm.sh` and the nginx template, `deploy/services/php-fpm.sh`).
- Base image bumped `phusion/baseimage:0.11` → `focal-1.2.0`; nginx PPA switched from `ondrej/nginx-mainline` to `ondrej/nginx`; `PHP_VERSION` promoted to a Dockerfile `ARG` (was hardcoded).
- Docker Hub image identity changed: `sonarsoftware/customerportal:stable` → `seankndy/sonarcustomerportal:stable` in both `docker-compose.yml` and `install.sh` — **this fork publishes/pulls from a different Docker Hub namespace than upstream**, consistent with the composer-package fork noted in section 5.
- `install.sh` now also prompts for and writes a GraphQL `API_TOKEN`/`SONAR_API_KEY` (needed by the new `seankndy/fluent-sonar-api` client), and defaults `QUEUE_DRIVER=redis` / `SESSION_DRIVER=redis` into the generated `.env` (commit `b5c7d93`) — this pairs with `d866e1c ... add scheduled command to run queued work`, i.e. the reactivation listener needs a real queue now, not `sync`.
- `8d78883 bump base image` (most recent commit on master) — latest Docker base image bump, no functional change beyond that.
- `prod-deploy-instructions.txt` is new — operator-facing deployment notes not present upstream at all.

**Risk for merge:** **Needs-careful-merge but low-complexity** — mostly mechanical version-string changes; the main judgment call is the Docker Hub image namespace / GraphQL API token requirement, which are deliberate identity choices for this fork, not things to blindly take from upstream if upstream has its own (different) equivalents.

> **Resolved by collision-report.md:** upstream moved further (Ubuntu 22.04 base) and confirmed it kept the original `sonarsoftware/customerportal:next` Docker Hub image — this fork's rename is a standing decision, not temporary. See collision-report.md §9.

---

## 10. Trusted proxy handling — split across the fork-point diff AND uncommitted work-in-progress

Two separate things are going on here, easy to conflate:

**a) Committed in the fork-point→master diff:** `config/trustedproxy.php` shows as **deleted** (`D`) in that diff. This is because upstream's `config/trustedproxy.php` (from `fideloper/proxy` 3.x, `~3.0`) was removed as part of the `fideloper/proxy` 3.0→4.0 upgrade (section 1) — Laravel 6 + `fideloper/proxy` 4.x moved trusted-proxy config inline into `app/Http/Middleware/TrustProxies.php` (`protected $proxies = '**';`, i.e. trust everything) rather than a separate config file. That's a legitimate part of the Laravel 6 upgrade, not a loss of functionality.

**b) Currently staged/uncommitted, on top of `master` right now:** this is new, in-progress work that *re-adds* `config/trustedproxy.php` — but as a different, better implementation than either upstream's old file or the fork's post-upgrade `'**'` default:
- `app/Http/Middleware/TrustProxies.php`: `protected $proxies` changed from hardcoded `'**'` to `null`, falling back to `config('trustedproxy.proxies')`.
- New `config/trustedproxy.php`: `'proxies' => env('TRUSTED_PROXIES', '**')` (defaults to old trust-everything behavior if unset, so it's backward-compatible), `'headers' => Request::HEADER_X_FORWARDED_ALL`.
- `.env.example`: adds commented-out `TRUSTED_PROXIES=172.20.0.0/16` with an explanatory comment about the security implications of setting it too broadly.
- New `deploy/conf/nginx/sonar-customerportal-http-only.template` + `deploy/90_init_nginx.sh` modified to pick between the TLS template and this HTTP-only one based on a new `DISABLE_SSL` env var.
- New `docker-compose-proxy.yml` — a full alternate compose file for running behind a Traefik reverse proxy (Traefik does TLS termination; app runs HTTP-only behind it), with Redis for queue/session, labeled for Traefik service discovery, plus a `watchtower` auto-updater service.

**What it's for:** supporting a deployment topology where this app sits behind a reverse proxy / load balancer (e.g. Traefik) rather than terminating TLS itself — the trusted-proxies config lets it correctly trust `X-Forwarded-*` headers only from the actual proxy's network instead of either trusting nothing (breaking client-IP/HTTPS detection behind a proxy) or trusting everything (spoofable by anyone who can reach the container directly, which is the old and current `master` default of `'**'`).

**Risk:** This is your **own in-progress, uncommitted work**, not something inherited from upstream — so it's not part of the upstream-merge conflict surface at all, it's just currently sitting in your working tree ahead of a commit. Flagging only so it isn't mistaken for something that needs reconciling against upstream, and so it doesn't get lost/discarded before you commit it.

---

## 11. Other misc / small items

- **`app/Console/Kernel.php` / `app/Console/Commands/GenerateSettingsKey.php`**: modified — likely just the queue-worker cron entry (`queue:work --stop-when-empty` every minute, per `CLAUDE.md`) added to the schedule (ties to the Redis-queue change in section 9), plus settings-key generator adjustments for the new config shape. Low risk, additive.
- **`app/Http/Requests/LookupEmailRequest.php` → `MakeRegistrationTokenRequest.php`** (R090, 90% similar): renamed with minor changes, part of the registration-flow rewrite in section 2.
- **`resources/views/pages/root/index.blade.php` → `login.blade.php`** (R091): renamed, minor changes, part of the auth rewrite (section 2) aligning with Laravel's stock `login` view naming convention.
- **`resources/assets/sass/app.scss` → `resources/sass/app.scss`** (R100, pure rename, no content change): Laravel 6's default asset directory convention moved from `resources/assets/` to `resources/`. Trivial.
- **`public/svg/{403,404,500,503}.svg`** (new) + `resources/views/pages/config/show.blade.php`, `public/assets/css/theme.css` modified: likely custom error pages / settings-page styling tweaks. Low risk, additive/cosmetic.
- **`storage/framework/cache/.gitignore`, `storage/framework/cache/data/.gitignore`**: trivial, framework-scaffolding.

---

## Unmerged branches (out of scope for this analysis)

These exist on `origin` but are **not merged into `master`**, so none of their changes are reflected in this report. Listed only so you're aware they're separate from what's cataloged here:

- `origin/ab5204-customer_portal_updates_to_allow_hiding_package_details`
- `origin/ab7897`
- `origin/dependabot/composer/league/flysystem-1.1.4`
- `origin/feature/3448`
- `origin/feature/4307`
- `origin/feature/ab-3448-bank-account-only-before-ui`
- `origin/gideon`
- `origin/local-dev-fixes`
- `origin/okteto-testing`
- `origin/scott/3330`
- `origin/stripe`
- `origin/update-okteto-host`
