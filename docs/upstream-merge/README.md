# Upstream merge reference

This directory catalogs how this fork (`master`) differs from upstream
[`SonarSoftwareInc/customer_portal`](https://github.com/SonarSoftwareInc/customer_portal),
to speed up planning and executing a merge of upstream's changes.

- **[divergence-map.md](divergence-map.md)** — what *this fork* changed or added
  relative to the fork point. Eleven customization categories, each with the files
  touched, what it does, and a merge-risk rating.
- **[collision-report.md](collision-report.md)** — what *upstream* did on its own
  `master` since that same fork point (257 commits / 901 files as of the analysis
  date below), cross-referenced against the eleven categories above to flag which
  ones actually collide with upstream work vs. which are safe to carry forward as-is.

## Provenance / freshness

- **Fork point:** `16d041c15eeeef0d19817ff98a3fc8b9d6b76d45` ("Update README.md"),
  confirmed via `git merge-base master upstream-official/master` — this is the exact
  last commit shared by this fork and upstream.
- **Analysis date:** 2026-08-27. `collision-report.md` reflects upstream `origin/master`
  at commit `799454c6044adc6fba1d340e5e3a1b9b9e14ab08` (2026-06-11, "Updating framework
  version to 2.0.6") — fetched fresh at analysis time.
- **Both documents are snapshots**, not living state. Upstream keeps moving; before
  relying on `collision-report.md` for merge planning, re-fetch upstream and check
  whether `origin/master` has advanced past `799454c6` — if it has, the "current
  upstream state" claims in that document (Laravel/PHP version, which controllers
  have new commits, etc.) may be stale and worth re-deriving rather than trusted
  outright. `divergence-map.md` (this fork's own changes vs. the fixed fork point)
  does not go stale the same way, since the fork point never moves.
- A local clone of upstream, already wired as a remote, lives at
  `/Users/cworthen/repos/customer_portal_official` (`git remote add upstream-official
  /Users/cworthen/repos/customer_portal_official` in this repo) — `git -C
  /Users/cworthen/repos/customer_portal_official fetch origin` refreshes it against
  the real `git@github.com:SonarSoftwareInc/customer_portal.git`.

## The one thing to act on regardless of merge timing

This fork's authentication rewrite (divergence-map.md §2) deleted the files that
later received upstream's password-policy-enforcement fix
(`371968f`, for a bug titled "password complexity is not being enforced") and a
login-throttle hash upgrade (md5→sha256, `5bd3634`). Confirmed by search: **this
fork currently has no `PasswordPolicy` class at all.** See collision-report.md §2
for what to port into `SonarUserProvider` / `RegistrationController`.
