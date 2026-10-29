=== My WP Migration ===
Requires at least: 5.3
Requires PHP: 7.0
Stable tag: 1.0.0
License: GPLv2 or later

Backup, export and import (migrate) a complete WordPress site. No size limits, no paid add-ons.

== Description ==

* Export the whole site (database + media + themes + plugins) into one .mwpm file.
* Import a .mwpm file on any WordPress site: URLs and server paths are replaced
  automatically (serialized data safe), the table prefix may differ.
* No upload size limit: files are uploaded in small chunks, so php.ini
  upload_max_filesize does not matter.
* No timeouts: every job runs in short resumable steps, and failed requests are
  retried automatically.
* Backups list: download (resumable), restore and delete.
* Very large files can be copied by FTP into wp-content/mwpm-backups/ and restored
  from the Backups tab.
* No PHP extensions needed (no ZipArchive), uses its own archive format.

== Moving a site ==

1. Old site: install the plugin, go to "WP Migration" → Export → "Export site" and download the file.
2. New site: install a fresh WordPress, install this plugin, go to "WP Migration" → Import
   and drop the file.
3. When it finishes, log in with the username/password of the OLD site and open
   Settings → Permalinks → Save.

== What is not included ==

wp-config.php, the root .htaccess and WordPress core files (the new site already has
its own). Caches and other backup plugins' folders are skipped automatically.

== Developer filters ==

* mwpm_time_budget     – seconds per step (default 10).
* mwpm_chunk_size      – upload chunk size in bytes.
* mwpm_export_exclude  – list of wp-content relative paths to skip.
* mwpm_export_batch    – rows per database query during export.
* mwpm_replace_pairs   – old => new strings replaced in the database on import.
