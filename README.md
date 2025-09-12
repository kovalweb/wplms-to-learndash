# wplms-to-learndash

Utilities for migrating WPLMS content into LearnDash.

## Importer

The importer skips orphan certificates during partial imports by default. Use the WP-CLI flag
`--import-orphan-certificates` or the "Import orphan certificates" checkbox in the admin UI to
force importing them.
