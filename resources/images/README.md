# Report images

`doh-seal.png` — the Department of Health seal printed on the left of the Daily Blood Stock
Inventory PDF (`config('blood_center.stock_report.seal_path')`). Supplied with the deployment,
not uploaded; until it is placed here, the report prints without it.

A facility's own logo is not kept here. A supervisor uploads it in the app (Settings), and it is
stored on the private `local` disk.

Embedding either image in a PDF needs PHP's GD extension (`extension=gd` in `php.ini`). Without
GD the report still renders, with no images.
