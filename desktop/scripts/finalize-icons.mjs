import { copyFileSync, existsSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const root = new URL('../', import.meta.url);
const master = new URL('src-tauri/icons/icon-master.png', root);
const png = readFileSync(master);
if (png.readUInt32BE(16) !== 1024 || png.readUInt32BE(20) !== 1024 || png[25] !== 2) {
  throw new Error('The App Store icon master must be a 1024×1024 RGB PNG without an alpha channel.');
}
copyFileSync(new URL('src-tauri/icons/128x128@2x.png', root), new URL('public/brand-icon.png', root));
// Tauri emits RGBA files even for an opaque master. Use the RGB original
// for Apple's marketing slot, preserving exactly the same 1024px artwork.
const marketingIcon = new URL('src-tauri/gen/apple/Assets.xcassets/AppIcon.appiconset/AppIcon-512@2x.png', root);
if (existsSync(marketingIcon)) {
  copyFileSync(master, marketingIcon);
  console.log(`Updated opaque iOS marketing icon: ${fileURLToPath(marketingIcon)}`);
}
console.log('Product mark and App Store icon are synchronized.');
