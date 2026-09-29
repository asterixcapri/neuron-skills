# PHP check results

The script reports facts about the PHP CLI process used to run it. All checks
pass when these four requirements are met:

- **PHP 8.1 or newer:** compare `PHP_VERSION` with 8.1. A failure means the
  `php` executable on the command path must be updated or changed.
- **curl extension:** `CURL_EXTENSION` must be `loaded` for HTTP clients that
  use cURL. Enable it for this PHP CLI installation if it is missing.
- **json extension:** `JSON_EXTENSION` must be `loaded` for JSON operations.
  Enable it for this PHP CLI installation if it is missing.
- **proc_open available:** `PROC_OPEN` must be `available` to launch external
  commands. If the command runner itself depends on `proc_open`, this script
  may not start when the function is unavailable.

These checks do not verify application configuration, network access, or
whether an external API request succeeds.
