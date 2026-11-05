# AskMyDocs app identity

Final artwork: `../src-tauri/icons/icon-master.png` (1024×1024 RGB PNG,
no alpha). Generated with the built-in image generator, then resized with
macOS sips and passed through the Tauri icon generator. The 1024px iOS
marketing slot uses the RGB master directly to avoid an alpha channel.

The approved direction is two rounded open panels with a central hinge,
subtly recalling an unfolded iPhone Duo while remaining a knowledge book.
The final user-requested palette is neutral: graphite, ivory and stone gray.
No Apple logo or device interface is used.

Final edit prompt:

> Change only the colors of this final AskMyDocs app icon to an entirely neutral palette, preserving the exact two-panel book/folding-device silhouette, compact landscape proportions, rounded panels, subtle central fold, placement, scale, paper layers, lighting, and crisp geometry. Replace the green background with deep neutral graphite charcoal (#292A2C), replace the sage central fold with soft neutral stone gray (#A6A39C), and retain warm ivory-white (#F2F0EB) panels with very restrained warm-gray shadows. Absolutely no green or other chromatic accent anywhere. Keep the quiet premium minimal editorial style. The background must be opaque and full-bleed square to all edges, no rounded outer icon corners. No text, no letters, no logos, no objects added, no mockup. One final icon.

Regenerate app resources and the frontend mark with `npm run icons` from
`desktop/`. On an initialized Apple project, the command also replaces the
marketing icon with the opaque master.
