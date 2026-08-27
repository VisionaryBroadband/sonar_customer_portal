# Collision Report: what upstream did, cross-referenced against this fork

Companion to [divergence-map.md](divergence-map.md) — see [README.md](README.md)
for scope/freshness notes. **This document's "current upstream state" claims are
a snapshot as of the analysis date in README.md — re-verify against a fresh
`git fetch` before relying on them for merge decisions.**

**Scope:** what `SonarSoftwareInc/customer_portal` (upstream) did on its own `master` between the fork point `16d041c15e…` (2021-10-25) and its current `master`, `799454c` (2026-06-11) — 257 commits, 901 files, +55,784/−20,764 lines — cross-referenced against the 11 customization categories in the Divergence Map to flag where the two histories collide.

---

## TL;DR

- **Upstream is on Laravel `^10.0` / PHP `^8.2`** — four major Laravel versions and two PHP minors ahead of this fork's Laravel 6 / PHP 7.4. This resolves the open question in the Divergence Map's §1 callout, and it's *not* the safe outcome hoped for there: this fork needs its own further upgrade, not just conflict resolution.
- **Upstream never rewrote auth.** It kept the exact architecture this fork deleted (`AuthenticationController` + `AuthMiddleware` + `PortalAuth`, session-flag based, no Laravel guard/`UserProvider` involved) — and used it to ship a **password-policy enforcement fix** and a **throttle-hash security fix (md5→sha256)** that this fork's replacement never received. Verified: this fork currently has **no `PasswordPolicy` class at all**. This is a live gap, independent of the merge.
- **Upstream never adopted the GraphQL/fluent Sonar client.** It's still on the *official* `sonarsoftwareinc/customer_portal_framework`, tagged `^2.0.6`, via the official GitHub org — not `seankndy`'s fork on `dev-master`. Every Sonar-calling code path is written in a structurally different style on each side; there is no line-level merge for this, only re-implementation.
- **Frontend stack did not change.** Still Blade + jQuery + Bootstrap Sass + Laravel Mix; Vue sits at an ancient `2.1.10` with no `resources/js` app directory — not a real framework adoption.
- **`BillingController` saw 34 upstream commits** and is nearly a rewrite (999+/863− lines) — Canada bank-routing logic, XCD currency support, FCC Broadband Facts labels, card-processor refactor, billing-summary UI, autopay UX. This is real collision territory with this fork's own billing tweaks.
- **`TicketController` had exactly 1 upstream commit**, and it's a mechanical dependency-update, not a feature change — lower collision risk than the Divergence Map's "needs careful merge" implied.
- Of the 11 Divergence Map categories: **5 are direct collisions** (§1 Laravel baseline, §2 auth, §5 Sonar client, §8 billing, §9 deploy), **2 have no upstream equivalent to collide with** (§3 Actions/DTO layer, §4 payment reactivation — both fork-only), **3 are likely compatible** (§6 language, §7 ticketing, §11 misc), **1 is out of scope** (§10, uncommitted WIP with nothing upstream to compare against).

---

## Part A — What upstream actually did, 2021→2026

901 files and 257 commits is too much to read individually; here's the shape, by theme, drawn from commit-message scanning, `--dirstat`, and the current state of the key stack-identity files.

### A1. Continued framework upgrades (mechanical, but a long way further than this fork went)
Laravel moved 6→10 in stages (an `UPGRADE.md` was added to document the process), PHP 7.4→8.2, base Docker image `phusion/baseimage:focal` → `jammy-1.0.1` (Ubuntu 22.04 — this fork is still on the Focal/20.04-era bump from its own §9). Frontend JS libraries were bumped too: highlight.js, tinymce, list.js, select2, chart.js, bootstrap, npm deps generally — commit `8269446` "Upgrade npm dependencies" and siblings.

### A2. A deliberate security-hardening pass
PR #164, "customer_portal_security_upgrade" (`ab22153`), plus companions: CSP fixes for updated dependencies, JS moved to nonce-based inline handlers (`a781da2 extract onclick method to nonced function`), a security-headers pass (`6c3e118 Ensure security headers are added to responses`, tied to a CWE-693 finding, PR #195), a login-throttle hash upgrade from md5 to sha256 (`5bd3634`), and — the one that matters most for this fork — `371968f Add password policy checks`, tied to a bug ticket literally titled *"password complexity is not being enforced."*

### A3. Terminology change: "Contract" → "Agreement"
`b601eac`/`f423e24 replace contract with agreement`, PR #211 — a portal-wide rename of the Contracts feature to "Agreements." This fork's `ContractController`/contract views were modified (per the Divergence Map's file list, `app/Http/Controllers/ContractController.php` is a "M" file) but not renamed — worth checking whether this fork's contract-related code and upstream's renamed agreement-related code are talking about the same feature under different names.

### A4. New regulatory/compliance feature: FCC Broadband Facts labels
PRs #176, #186, #204 — "fcc_labels," "fcc_label_disk_volume," "tie customer_portal display all fcc nutrition labels." A US-specific regulatory requirement (broadband "nutrition labels") implemented as new portal functionality, wired into billing (`8555d76 Show multiple labels for FCC Broadband Facts`). Entirely absent from this fork.

### A5. Payment/billing internationalization and refinement
Canada-specific bank-routing-number logic (`6c595f4`, `55e02b5`), XCD (East Caribbean dollar) added to PayPal/GoCardless currency dropdowns (`fa56388`, PR #149), plus a long tail of `BillingController` UX work — billing summaries on card/bank payment views, autopay description cleanup, credit-card-processor retrieval refactor, dead-code removal (`e730f23`, `a8ba693`, `fadba64`).

### A6. Auth: extended, not replaced
Covered in detail in Part B §2 below — the short version is upstream kept the original bespoke controller/middleware pair and patched it in place.

### A7. Infra/ops churn
43 "Merge pull request" commits total — this is a PR-reviewed, actively maintained upstream, not a slow-moving one. CircleCI buildpack upgrades, Okteto stack config updates (`ab35627-upgrade_okteto_config`), a `[HOTFIX] Fix expired security certificates` (`5e62933`), and — notably — some recent (2026) flux around ticket-group pagination that was added and then reverted twice (`34567af` → `cb2d9be` → `d7e9712`/`5f3ea33` revert both), suggesting upstream is still actively iterating on ticketing UX, just not in the same file this fork already changed.

### A8. What did *not* change
No frontend framework migration (still Blade/jQuery). No move away from the official `customer_portal_framework` package. No adoption of a `UserProvider`/guard-based auth system. No `Actions`/DTO architectural layer — upstream's controllers are still doing direct, inline work.

---

## Part B — Collision cross-reference, by Divergence Map category

### §1 — Laravel 5.5→6.0 upgrade → **Direct collision**
Upstream's current baseline is **Laravel `^10.0`, PHP `^8.2`** (confirmed via `composer.json` on `origin/master`), versus this fork's Laravel 6 / PHP 7.4. `config/app.php`, `config/auth.php`, `config/session.php`, and `composer.json` will all conflict substantially — this isn't "maybe already reconciled," it's a real four-major-version gap. Merging cleanly likely means treating this as its own dedicated Laravel-6→10 upgrade project for this fork, done independently of pulling in other upstream changes, rather than something `git merge` resolves.

### §2 — Authentication rewrite → **Direct collision (highest-value finding)**
Upstream's `app/Http/Controllers/Auth/{Login,Register,ForgotPassword,ResetPassword}Controller.php` are present but are unused Laravel scaffolding — `app/User.php` is the *stock* Laravel default model (`fillable: name, email, password`), not wired to anything real. The actual, live auth path is unchanged in shape from the fork point: `AuthenticationController` (routes `/`, `/register`, `/create/{token}`, `/reset`, `/reset/{token}`, `/logout`) + `AuthMiddleware` (checks `session('authenticated') === true`, no Laravel guard involved) + `PortalAuth` (still the `'auth'` middleware alias in `Kernel.php`) — all three files this fork deleted.

Five commits touched `AuthenticationController.php` upstream since the fork point:
- `371968f Add password policy checks` — **this fork has no `PasswordPolicy` class at all**, confirmed by search. A real, currently-missing control.
- `5bd3634` — throttle hash upgraded md5→sha256 (vulnerability-scan remediation).
- `4f3ddb4`, `5f0eb52` — improved error messaging/reporting on auth failures and password-reset failures.
- `e2a5b67` — mechanical, tied to the `customer_portal_framework` v2 dependency bump.

Since this fork deleted the files these fixes live in, none of them have anywhere to land via merge — each needs to be manually read and ported into the equivalent spot in `SonarUserProvider` / `RegistrationController` / `PasswordResetController` / `Authenticate`. Start with the password-policy check; it's the one with a real security implication.

### §3 — Actions / DataTransferObject layer → **No upstream equivalent**
Upstream has no `app/Actions` or `app/DataTransferObjects` directories and never developed one — this fork's architecture here has nothing to collide with. (The controllers that call into it, `TicketController` and `BillingController`, are addressed separately below.)

### §4 — Payment → account reactivation → **No upstream equivalent**
No `PaymentSuccessfullySubmittedEvent`, `ReactivatesAccountOnPaymentListener`, or `CustomerPayedBill` mailable exist upstream. Purely additive on this fork's side; nothing to reconcile.

### §5 — Sonar API client swap → **Direct collision, structural**
Upstream's `composer.json` still requires `"sonarsoftwareinc/customer_portal_framework": "^2.0.6"` via a `vcs` repository pointed at `https://github.com/sonarsoftwareinc/customer_portal_framework` — the **official** org, at a **tagged release**, not `seankndy`'s fork on `dev-master`. There is no `seankndy/fluent-sonar-api` or `gmostafa/php-graphql-client` anywhere upstream. Every controller/action upstream that talks to Sonar does so through the older framework's API shape directly — this fork's fluent/GraphQL wrapper has no upstream counterpart to merge against. Any upstream commit that touches Sonar-calling code (which is most of them, including the 34 in `BillingController`) will need to be **re-implemented** against this fork's client, not merged line-by-line.

### §6 — Language/localization rewrite → **Likely compatible**
Upstream kept `LanguageServiceProvider.php`, `LanguageService.php`, and `Language.php` (this fork's deleted/renamed files) — but each received only **one commit** since the fork point, so the drift is small. Worth a quick manual read of those three commits before assuming compatibility, but this is nowhere near the stakes of §2's five auth commits.

### §7 — Ticketing UX changes → **Likely compatible (lower risk than the Divergence Map assumed)**
`TicketController.php` upstream has exactly **one** commit since the fork point — `e2a5b67`, the same mechanical `customer_portal_framework` v2 dependency-update commit that touched `AuthenticationController.php` and `TicketController.php` together. It is not a ticketing feature change. `resources/lang/**` overall (across all languages/files) had only one commit touching it in this window. The Divergence Map flagged this as "needs careful merge" on the assumption ticketing is core and actively developed upstream — that assumption doesn't hold; upstream's ticketing code is nearly frozen since 2021. (Upstream did add/revert ticket-group pagination work in 2026 per A7 above, but that's in a different area than what this fork changed.)

### §8 — Billing/account misc changes → **Direct collision, significant**
`BillingController.php` had **34 commits** upstream and is close to a full rewrite (999 insertions / 863 deletions in a diff against a file that size). Real functional work: Canada bank-routing logic, XCD currency support, FCC Broadband Facts label integration, credit-card-processor retrieval refactor, billing-summary UI additions, autopay UX cleanup, dead-code removal. This fork's own `BillingController` changes (field-order swap, payment tracker ID, integer-cents fix, proration skip) sit in the same file and will conflict directly and repeatedly — this is the second-highest-effort reconciliation after auth, and unlike auth it's UI/business-logic conflict rather than security-relevant, so it can be resolved feature-by-feature rather than needing the same level of care.

### §9 — Deploy / Docker / infra → **Direct collision, moderate**
`Dockerfile` (6 commits), `install.sh` (6 commits), `docker-compose.yml` (5 commits) all saw real upstream activity. Upstream's base image moved to `phusion/baseimage:jammy-1.0.1` (Ubuntu 22.04) — further than this fork's own bump. Upstream's Docker Hub image is still `sonarsoftware/customerportal:next` — confirming this fork's `seankndy/sonarcustomerportal` naming is a genuine, standing identity fork, not a temporary state. Expect real conflicts here, but they're mechanical/config-shaped rather than requiring behavioral judgment calls, aside from re-confirming the namespace and base-OS decisions are still intentional.

### §10 — Trusted proxy / uncommitted WIP → **Out of scope**
No upstream file to compare against changed in this window in a way relevant to this — confirmed out of scope, as flagged in the Divergence Map.

### §11 — Misc small renames → **Likely compatible**
Nothing in the upstream commit history suggests overlapping activity in these specific files (asset-path rename, SVG error pages, cache `.gitignore`s). Low risk stands.

---

## What this means for merge sequencing

Given the above, a sensible order of operations is roughly:
1. **Do the Laravel 6→10 / PHP 7.4→8.2 upgrade on this fork first**, as its own project, before attempting to pull in any other upstream change — nearly everything else conflicts less cleanly while that gap exists.
2. **Port the two concrete auth security fixes** (password-policy checks, throttle hash upgrade) into this fork's `SonarUserProvider`/`RegistrationController` regardless of merge timing — they're a real gap today, not just a future conflict.
3. **Treat `BillingController` as a manual feature-reconciliation task**, not a mergeable diff — read upstream's Canada/XCD/FCC-label additions and this fork's own changes side by side and re-implement both against this fork's Sonar client.
4. Ticketing, language, and misc renames can likely be merged with normal conflict resolution and light review — they're not high-stakes.
5. The Actions/DTO layer, payment-reactivation feature, and Sonar-client identity are fork-specific decisions with no upstream equivalent — carry them forward as-is; there's nothing to reconcile, only to re-verify they still work against whatever upstream code they now sit beside.
