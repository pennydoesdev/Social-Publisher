# Project rules for Claude

## Git workflow

- **Always push automatically** after every commit. Never wait to be told.
- Push to the active feature branch with `git push -u origin <branch>`.
- After pushing, ensure a draft PR exists for the branch (create one if not).
- Versioning: bump the plugin version by `0.01` on every change. Update both
  the `Version:` header and the `SOCIAL_PUBLISHER_VERSION` constant in
  `social-publisher.php`, then run `bin/build.sh` to refresh
  `dist/social-publisher-v<VERSION>.zip`. Add a CHANGELOG entry.
- Keep historical zips in `dist/` (don't delete prior versions).

## Authorship

- Author / copyright owner: **Penny Constellation**.
