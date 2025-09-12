# wplms-to-learndash

Utilities for migrating WPLMS content into LearnDash.

## Importer

Orphan handling is now determined solely by the export mode in the source
JSON. There is no flag to toggle orphan certificate import.

* `discover_related` &rarr; no orphan units, quizzes, assignments or
  certificates are imported.
* `discover_all` &rarr; all orphan content is imported.

## Shortcodes

### `[ld_product_price]`

Outputs the formatted price of the WooCommerce product linked to a LearnDash course.

```
[ld_product_price course_id="123"]
```

If `course_id` is omitted the current post ID is used. The shortcode only renders a value when the
linked product is published and has a numeric price. Shortcodes can also be used in LearnDash course
templates as the plugin applies `do_shortcode` to the `learndash_payment_buttons` filter.
