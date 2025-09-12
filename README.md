# wplms-to-learndash

Utilities for migrating WPLMS content into LearnDash.

## Importer

The importer skips orphan certificates during partial imports by default. Use the WP-CLI flag
`--import-orphan-certificates` or the "Import orphan certificates" checkbox in the admin UI to
force importing them.

## Shortcodes

### `[ld_product_price]`

Outputs the formatted price of the WooCommerce product linked to a LearnDash course.

```
[ld_product_price course_id="123"]
```

If `course_id` is omitted the current post ID is used. The shortcode only renders a value when the
linked product is published and has a numeric price. Shortcodes can also be used in LearnDash course
templates as the plugin applies `do_shortcode` to the `learndash_payment_buttons` filter.
