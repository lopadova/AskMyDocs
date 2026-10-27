# Website wordmark

The horizontal lockup pairs the existing neutral app icon with the exact
requested lettering **AskMyDOcs**. The symbol artwork is unchanged; Georgia
lettering is converted to vector paths so no font must be installed or fetched
when displaying the exports.

## Assets

| File under `public/brand/` | Use |
| --- | --- |
| `askmydocs-wordmark-light.svg` | Graphite lettering on light backgrounds |
| `askmydocs-wordmark-dark.svg` | Ivory lettering on dark backgrounds |
| `askmydocs-wordmark-light.png` | Transparent 2272×640 export for light backgrounds |
| `askmydocs-wordmark-dark.png` | Transparent 2272×640 export for dark backgrounds |
| `askmydocs-mark.png` | Original app symbol, 256×256 |
| `favicon.png` | Small 32×32 site icon |
| `wordmark.css` | Shared theme selection and responsive sizing |

SVGs have a 568×160 viewBox, outlined lettering and an embedded 256px bitmap
of the app icon. They are self-contained compositions, not fully vector
redrawings of the raster artwork. High-resolution PNG exports use the original
1024px app master. The light/dark comparison is `wordmark-preview.png`.

## Integration

- Public landing header/footer use the theme-appropriate wordmark.
- React sidebar and authentication layouts reuse `BrandWordmark`.
- The authentication artwork always uses ivory lettering; the form header
  and sidebar follow the selected light/dark theme.
- Both Blade shells load the shared CSS and updated favicon. Existing SVG
  logo/favicon aliases also use the app symbol.
- Product copy and document content retain their existing spelling; the
  requested capitalization applies to this logo only.

Regenerate on macOS from the repository root:

```sh
swift -module-cache-path /tmp/askmydocs-wordmark-swift-cache scripts/brand/generate-wordmark.swift
```

## Verification — 27 October 2026

- TypeScript checks and Vite production build passed; nine existing Sidebar tests passed.
- Herd site: `https://askmydocsdev.test`, with its existing HTTPS setup.
- Landing assets load with one visible variant per lockup. Checked light/dark
  switching, sidebar desktop rendering and the mobile navigation drawer.
- Landing viewport matrix: 320×568, 390×844, 430×932, 768×1024, 1280×800.
  The 320px header needed a narrower logo to keep its existing controls inside
  the viewport; it was corrected and rechecked with no horizontal overflow.
- Auth layout source and forced-dark logo selectors reviewed. Runtime guest
  login was not exercised because the existing browser session was already
  authenticated; no user session was cleared.
- This is a website identity update. Tauri functionality and native mobile
  distribution were not changed or certified by these viewport checks.
