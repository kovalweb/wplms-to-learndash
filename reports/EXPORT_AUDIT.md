# Export Audit

This report summarizes metrics from three export modes (`strict`, `discover_all`, `discover_related`) based on JSON files in `full-export/`.

## A. Metrics per Export Mode

| Mode | Courses | Unique units | Unit status distribution | Courses with SKU | Courses without SKU | Product status distribution | Courses with certificate ref | Warnings |
|---|---|---|---|---|---|---|---|---|
| strict | 185 | 1559 | publish: 1559 | 145 | 40 | publish:127, private:5, draft:13, none:40 | 0 | 0 |
| discover_all | 185 | 1559 | publish: 1559 | 145 | 40 | publish:127, private:5, draft:13, none:40 | 0 | 0 |
| discover_related | 185 | 1559 | publish: 1559 | 145 | 40 | publish:127, private:5, draft:13, none:40 | 0 | 0 |

Notes:
- All three exports contain the same set of 1559 unique unit IDs, although export metadata reported 1560 units due to a duplicate (`old_id` 104143).
- 40 courses lack product SKUs; details are listed in `csv/courses_without_sku_<MODE>.csv`.
- No course carries a certificate reference and no warnings were produced.

## B. Comparison with WP data

The live WPLMS instance reports **2382** published units. Exports include only **1559** unique units, leaving **823** units absent from the JSONs. Detailed lists of missing units could not be generated because direct access to the WP database/CLI was unavailable in this environment. Placeholder CSV files are present under `csv/` for future reconciliation.

## C. Venn Analysis of Unit Sets

All three export modes yielded identical unit ID sets:

- Units common to all modes: 1559
- Units unique to `strict`: 0
- Units unique to `discover_all`: 0
- Units unique to `discover_related`: 0

## D. Commerce Audit

- `product_status` values observed: `publish`, `draft`, `private`, and `null` for courses without products. No unexpected statuses were found.
- Coverage of `product_sku`: 145/185 courses (78%) include a SKU. Remaining 40 courses are detailed in CSV outputs with reasons (`no_product_link` or `product_has_no_sku`).

## E. Certificates

No course in any export contained a certificate reference. Without access to the WP source, potential gaps between WP and exports could not be enumerated.

## Next Steps

- Obtain direct WP access to identify which of the 823 units are missing and why (e.g., status filtering, lack of course association, or type mismatches).
- Verify whether any courses in WP have certificates that are absent from exports.
- Ensure the exporter deduplicates units correctly and clarifies unit counts in metadata.

