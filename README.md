# wordpress-ada-pipeline-plugin

## Requirements

- PHP 7.4 or higher — the floor WordPress will allow activation on (`Requires PHP` in the
  plugin header, mirrored in `composer.json`).
- Actively tested against PHP 8.3 only, matching the current production target. 7.4–8.2 are
  allowed to install but are not verified by CI (see `Dockerfile`) — if you hit a version-specific
  bug on one of those, please report it.