# Handheld scanner error codes

Reference table for the warehouse handheld scanners. Codes are printed on
the device screen below the red banner.

## Stock errors

- `ERR_STOCK_0417` - the counted quantity in the bin differs from the
  system quantity by more than 2 units. Recount the bin, then ask the shift
  lead to approve the adjustment. Do not pick from the bin until cleared.
- `ERR_STOCK_0420` - the SKU is not assigned to this location. Move the item
  to its home location or scan the location label again.

## Scan errors

- `ERR_SCAN_0102` - the barcode is unreadable. Clean the lens or type the
  code manually.
- `ERR_SCAN_0108` - the barcode belongs to a different order wave.

## Session errors

- `ERR_SESS_0300` - the session has expired. Sign in again with the badge.
  The expiry delay depends on the site, see the site operating guide.

## Printer errors

- `ERR_PRN_0521` - the label printer is offline. Check the cable and the
  media, then restart the printer from the station menu.
