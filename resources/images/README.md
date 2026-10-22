# Report images

`doh-seal.png` — the Department of Health seal printed on the left of the Daily Blood Stock
Inventory PDF (`config('blood_center.stock_report.seal_path')`). A 400 × 400 PNG rendered from
Wikimedia Commons' "Department of Health (DOH) PHL.svg" (2026-10-22). Replace it with the
DOH's own file if the centre has one; the client keeps the same image at
`RedAgos_client/public/images/doh-seal.png`. Without the file the report prints without it.

A facility's own logo is not kept here. A supervisor uploads it in the app (Settings), and it is
stored on the private `local` disk.

Embedding either image in a PDF needs PHP's GD extension (`extension=gd` in `php.ini`). Without
GD the report still renders, with no images.
