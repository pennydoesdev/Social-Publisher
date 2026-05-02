# Social Publisher

WordPress plugin that auto-generates platform-specific **drafts in Publer** for
every published post. Auto-discovers public post types and connected Publer
accounts, generates per-platform copy with OpenAI (single JSON-mode call), and
creates one Publer draft per provider — Facebook, Instagram, Threads, X,
LinkedIn, TikTok, YouTube. Optional Short.io shortlinking; falls back to the
permalink.

> **Drafts only.** Nothing is ever auto-published to a social network. Every
> Publer post is created with `state: draft` so you can review before sending.

## Features

- Hooks `transition_post_status` and runs asynchronously via WP-Cron one-shot
  (default 60 s after publish; configurable).
- Auto-discovers post types via `get_post_types( [ 'public' => true ] )`.
- Auto-discovers Publer accounts via
  `GET /v1/workspaces/{workspace_id}/accounts` (cached 1 h).
- Single OpenAI Chat Completions JSON call returns per-platform copy.
- Creates one **draft** Publer post per connected, enabled provider.
- Optional Short.io shortlink (`POST https://api.short.io/links`) with daily
  cache; falls back to permalink on any error.
- Settings page under **Settings → Social Publisher**.
- **Per-post skip flag** (classic meta box and Gutenberg sidebar toggle).
- **Gutenberg sidebar panel** with a *Generate drafts now* button — works on
  any post, including past / already-published ones.
- REST endpoints for status / regenerate / skip.
- Secrets can be hard-locked via `wp-config.php` constants.

## Requirements

- WordPress 6.2+
- PHP 7.4+
- A Publer workspace + API key
- An OpenAI API key
- (Optional) A Short.io API key + branded domain

## Install

1. Copy this plugin to `wp-content/plugins/social-publisher/`.
2. Activate **Social Publisher** in *Plugins*.
3. Visit **Settings → Social Publisher** and add credentials.

## Configuration

### Settings page

- **OpenAI**: API key, model (default `gpt-4o-mini`).
- **Publer**: API key, workspace ID. Connected accounts preview + cache flush.
- **Short.io** (optional): API key, branded domain (e.g. `links.example.com`).
- **Run delay**: seconds between publish and async run (0–3600, default 60).
- **Platforms**: enable/disable any of the 7 supported providers.
- **Post types**: scope to specific types, or leave all unchecked to include
  every public type automatically.

### Hard-locking secrets via `wp-config.php`

```php
define( 'SOCIAL_PUBLISHER_OPENAI_KEY',  'sk-...' );
define( 'SOCIAL_PUBLISHER_PUBLER_KEY',  'publer-...' );
define( 'SOCIAL_PUBLISHER_SHORTIO_KEY', 'shortio-...' );
```

When a constant is defined the corresponding settings field becomes read-only
and the constant value is always used at runtime.

## REST API

All endpoints require `edit_post` capability on the target post.

| Method | Path                                              | Purpose                                  |
| ------ | ------------------------------------------------- | ---------------------------------------- |
| POST   | `/wp-json/social-publisher/v1/regenerate/{id}`    | Run the pipeline now and create drafts.  |
| GET    | `/wp-json/social-publisher/v1/status/{id}`        | Last-run summary + skip flag.            |
| POST   | `/wp-json/social-publisher/v1/skip/{id}`          | Body `{ "skip": true\|false }`.          |

## How a post is processed

1. Build a payload (title, excerpt, plain content, permalink, tags, categories).
2. If Short.io is configured, shorten the permalink (cached 24 h); else use the
   permalink.
3. Fetch connected Publer accounts (cached 1 h) and group by provider.
4. Call OpenAI once with `response_format: json_object` to produce per-platform
   copy.
5. For each connected account whose provider is enabled, create a Publer draft
   (`state: draft`) and record the result on the post.

## Filters

| Filter                                        | Purpose                                          |
| --------------------------------------------- | ------------------------------------------------ |
| `social_publisher_is_eligible`                | Override per-post eligibility.                   |
| `social_publisher_post_types`                 | Modify the auto-discovered post type list.       |
| `social_publisher_enabled_post_types`         | Final post-type allowlist.                       |
| `social_publisher_enabled_platforms`          | Final platform allowlist.                        |
| `social_publisher_provider_aliases`           | Map Publer `provider` strings to platform keys.  |
| `social_publisher_media_urls`                 | Choose which media URLs to attach.               |
| `social_publisher_append_link`                | Whether to inline the link in copy per platform. |
| `social_publisher_publer_accounts_path`       | Override the accounts endpoint path.             |
| `social_publisher_publer_create_path`         | Override the create-post endpoint path.          |
| `social_publisher_publer_post_body`           | Mutate the Publer post body before send.         |
| `social_publisher_openai_request`             | Mutate the OpenAI request body before send.      |

## File layout

```
social-publisher.php           # bootstrap
uninstall.php                  # cleanup
includes/
  class-plugin.php             # singleton + lifecycle
  class-settings.php           # Settings → Social Publisher
  class-publisher.php          # transition + cron pipeline
  class-publer-client.php      # Publer HTTP client (drafts only)
  class-openai-client.php      # OpenAI JSON-mode client
  class-shortio-client.php     # optional Short.io client
  class-meta-box.php           # classic meta box + Gutenberg sidebar
  class-rest.php               # /social-publisher/v1/* routes
  class-logger.php             # rolling activity log
assets/
  sidebar.js                   # Gutenberg PluginDocumentSettingPanel
```

## License

GPL-2.0-or-later.
