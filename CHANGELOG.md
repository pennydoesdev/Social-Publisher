# Changelog

Versions bump by `0.01` per change. Starting line: `1.00`. Distributable zips
are versioned: `dist/social-publisher-v<VERSION>.zip`.

Copyright © 2026 Penny Constellation.

## 1.02 — 2026-05-02

Fix: Publer drafts were never actually being created even though the API
returned 200. `POST /v1/posts/schedule` is asynchronous — it returns a
`job_id` and the real work happens in the background, where it can still
fail per-account.

- After scheduling, the client now polls `GET /v1/job_status/{job_id}`
  until the job leaves the `working` state (default 12 × 1 s).
- Treats per-account `payload.failures` as a real error and surfaces the
  Publer message (e.g. provider name + reason).
- Returns explicit errors for `failed`, unknown statuses, and timeouts so
  the orchestrator records them and the run summary reflects reality.
- Adds a **Send test draft** button on the settings page that posts a
  short message to the first connected account so you can verify the
  full pipeline (auth → schedule → job poll → draft) without publishing
  a real WordPress post. Result is shown inline as a success or error
  notice, and logged to the activity log.
- New filters: `social_publisher_publer_job_status_path`,
  `social_publisher_publer_job_max_polls`, `social_publisher_publer_job_poll_us`.

## 1.01 — 2026-05-02

Fix: Publer integration was hitting the wrong host and wrong endpoints, so
the **Connected Accounts** preview always showed "No connected Publer
accounts found" even with valid credentials.

- Host corrected: `app.publer.io` → `app.publer.com`.
- Accounts endpoint corrected: `GET /v1/workspaces/{id}/accounts` →
  `GET /v1/accounts` (workspace passed via `Publer-Workspace-Id` header).
- Create-post endpoint corrected: `POST /v1/posts/schedule/publish` →
  `POST /v1/posts/schedule`.
- Create-post body restructured to Publer's `bulk` schema:
  `{ bulk: { state: "draft", posts: [{ networks: { <provider>: { type:"status", text } }, accounts: [{ id }] }] } }`.
- Failed account fetches are no longer cached for 1 hour — only successful,
  non-empty results are cached. Fixing creds takes effect immediately.
- Settings page surfaces the real Publer error (HTTP status, response body,
  request URL) instead of silently showing "no accounts found", with hints
  for 401 / 403 / 404.
- Settings page force-refreshes accounts on each visit.
- Inactive / disconnected accounts are filtered out client-side.
- Provider-aware network-key normalization (`x` → `twitter`, `facebook_page` →
  `facebook`, etc.).
- Removed unused `resolve_media` and `maybe_append_link` helpers; link is now
  inlined inside the Publer client.

## 1.00 — 2026-05-02

- Initial release.
- `transition_post_status` → WP-Cron one-shot pipeline (default 60 s).
- Auto-discovers public post types and Publer accounts.
- Single OpenAI JSON-mode call for per-platform copy (FB / IG / Threads / X /
  LinkedIn / TikTok / YouTube).
- Creates Publer posts with `state: draft` only — never auto-publishes.
- Optional Short.io shortlinking, falls back to permalink.
- Settings page (Settings → Social Publisher) with connected-accounts preview,
  cache flush, platform / post-type toggles, run delay, activity log.
- Per-post skip flag (classic meta box + Gutenberg sidebar toggle).
- Gutenberg sidebar **Generate drafts now** button — works on past posts too.
- REST routes: `regenerate/{id}`, `status/{id}`, `skip/{id}`.
- Secrets hard-lockable via `wp-config.php` constants.
