# AskMyDocs button contract

## Anatomy

Every button uses five deliberate layers:

1. **Outer edge** — a one-pixel boundary that stays visible on every product surface.
2. **Inner hairline** — a subtle highlight inset by one pixel; it supplies tactile definition without a glossy effect.
3. **Surface** — a restrained vertical tone change, never a decorative rainbow gradient.
4. **Content** — optically centered label and optional icon with no layout jump between states.
5. **Elevation** — a short ambient shadow at rest, a soft light response on hover, compressed on press.

## Hierarchy

| Variant | Purpose | Constraint |
| --- | --- | --- |
| Primary | The preferred next action | Maximum one per local cluster |
| Secondary | Normal product actions | Default choice for most controls |
| Quiet | Navigation chrome and low-emphasis utilities | Must remain discoverable on hover and focus |
| Danger | Destructive or difficult-to-reverse actions | Never use only to attract attention |

## Geometry

| Size | Height | Radius | Horizontal padding | Typical use |
| --- | ---: | ---: | ---: | --- |
| `sm` | 32 px | 7 px | 12 px | Dense toolbars, title actions |
| `md` | 40 px | 8 px | 17 px | Default application actions |
| `lg` | 46 px | 9 px | 22 px | Dialog confirmations, focused CTAs |

- Icon-only buttons are square and use the selected size as both dimensions.
- The default icon gap is 8 px; compact controls use 7 px.
- Icons normally measure 13–17 px and inherit the label color.
- Preserve at least 8 px between adjacent independent buttons.

## Typography

- Use the product sans font at approximately 610 weight and 13 px for the default size.
- Use sentence case for ordinary actions: `New chat`, `Save changes`, `Review result`.
- Labels should name the action, not the widget: prefer `Delete connection` over `Yes`.
- Uppercase is an exception for short technical/status controls such as `API LIVE`; use tracking and keep labels concise.
- Do not shrink text to fit an overlong label. Rewrite it or let the surrounding layout adapt.

## States

- **Hover:** do not move the control; increase luminance, edge clarity and ambient glow.
- **Pressed:** lower by one pixel and compress the shadow.
- **Focus-visible:** show a two-stage ring that is visible in both themes and does not rely on color alone.
- **Disabled:** remove elevation, reduce saturation and block pointer activation.
- **Busy:** keep the label present, replace the leading icon with the shared spinner, set `aria-busy`, and disable repeat activation.
- **Reduced motion:** remove position transitions and spinner animation when the operating system requests reduced motion.

## Accessibility

- Use a native `<button>` for actions and a link for navigation.
- Always provide an accessible name. Icon-only buttons require `aria-label`.
- Decorative icons stay outside the accessible name.
- Use `aria-pressed` for toggle/group choices and preserve keyboard focus order.
- Do not suppress `focus-visible` styling.

## Audit checklist

- Is there exactly one clearly preferred action where a primary is warranted?
- Are neighboring buttons built from the same geometry and state system?
- Does every icon have a purpose, and does every icon-only action have a name?
- Are the default, hover, press, focus, disabled, and loading states present?
- Does the button remain legible in light and dark themes?
- Does a long translation fit without clipping or moving adjacent controls unpredictably?
- Is destructive styling limited to truly destructive actions?
- Are custom CSS overrides limited to layout rather than restyling the component?
