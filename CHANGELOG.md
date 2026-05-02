# Changelog

Versions bump by `0.01` per change. Starting line: `1.0`.

## 1.0 — 2026-05-02

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
