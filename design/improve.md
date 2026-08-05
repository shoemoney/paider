# Design Jury — Round 1

**Reviewers:** 5 · **stop votes:** 0

## Deduped findings

### general

| Votes | Agree? | Item |
|---|---|---|
| 1 | REVIEW | Add subtle border-radius and padding to the alpha pill for better visual weight and alignment with code blocks. |
| 1 | REVIEW | Increase line-height on body copy from 1.65 to 1.75 for improved readability on longer paragraphs. |
| 1 | REVIEW | Use consistent 1.5rem bottom margin on all h2 headings to tighten vertical rhythm. |
| 1 | REVIEW | Make the warning box background use a very light tint of --accent at 4% opacity in light mode (and equivalent in dark) for better containment without new colors. |
| 1 | REVIEW | Widen the main max-width from 46rem to 52rem to give the pre/code blocks more breathing room on larger viewports. |
| 1 | REVIEW | Inject user-select:none CSS pseudo-elements (::before) for command prompts inside code blocks so terminal commands copy cleanly without prompt symbols. |
| 1 | YES | Structure install options into visual code card containers with subtle background contrast and clear section labels (Laravel App, Global CLI, cURL). |
| 1 | REVIEW | Transform source and package links into tactile badge components with subtle borders and hover affordance. |
| 1 | REVIEW | Add a pure CSS terminal output snippet showing a sample cost ledger reconciliation event to visually communicate the core value proposition. |
| 1 | YES | Refine dark mode color palette variables by elevating container background contrast (--code) and subtle borders (--line) for multi-layer depth. |
| 1 | REVIEW | Split the install commands into three labeled blocks—“Laravel app,” “Standalone CLI,” and “Installer”—and change the installer example to the explicit `https://paider.dev/install` URL. |
| 1 | REVIEW | Reformat “What it does” as five bordered feature rows with a short bold label and supporting sentence; use a two-column label/detail grid on wide screens and one column on mobile. |
| 1 | REVIEW | Promote PHP 8.4+, Composer, and the extension check into a compact prerequisites line directly beneath the Install heading instead of leaving them inside secondary prose. |
| 1 | REVIEW | Add strong `:focus-visible` treatment to every link, preserve visible underlines, increase link hit area in the Source row, and avoid relying on purple alone to communicate interactivity. |
| 1 | REVIEW | Replace all body-level `style` attributes with named classes in the head stylesheet, and add reusable `.meta`, `.compact`, and `.source-links` rules for consistency. |
| 1 | REVIEW | Show the product, not just describe it: add a static `<pre>` sample of real terminal output — an approval prompt plus a ledger line with tokens and dollars — directly under the tagline and above Install. Right now a page about cost transparency never shows a cost. Style it as a framed panel (subtle inner border, dim `$` prompt via a `::before` on a span, accent only on the dollar figure) so it reads as evidence rather than decoration. |
| 1 | REVIEW | Differentiate the code blocks by role. Install commands, the curl line, and the sample output currently share one `pre` treatment. Give install blocks a copy-friendly monospace panel with a tiny uppercase dim label above (`composer`, `curl`, `session output`) using an `h3`-class or `pre[data-label]::before` — labels let a skimmer find the one line they came for in under a second. |
| 1 | REVIEW | Turn 'What it does' into a definition list rather than a bullet list. Each item is already `<strong>lead</strong> — explanation`; a `dl` with `dt` at 0.8rem uppercase tracked and `dd` at normal size, separated by hairline rules, converts five bullets into a scannable spec sheet and kills the dash-clause awkwardness. |
| 1 | REVIEW | Add a compact 'Requires' strip near Install: PHP 8.4+, twelve extensions, Composer, Apache-2.0. Currently that gating info is buried in a small grey paragraph after the curl line, which is exactly where a reader who is about to fail the install will not look. A three-to-four cell CSS grid of label/value pairs at 0.82rem solves it without adding weight. |
| 1 | REVIEW | Give the two negative-space sections a shared visual language distinct from the positives: keep the accent left rule on the warn block, and render the 'No standalone binary / No MCP client / Sessions not built' list with a `·` or `—` marker instead of disc bullets, in `--dim`, so 'not yet' reads visually as a different class of statement than 'it does.' This strengthens locked constraint #1 rather than softening it — the honesty becomes designed, not accidental. |
| 1 | REVIEW | Adopt a fluid type/spacing scale: `font-size: clamp(2.25rem, 6vw + 1rem, 3rem)` for h1, `padding-inline: clamp(1.25rem, 4vw, 2rem)`, and `text-wrap: balance` on headings / `text-wrap: pretty` on body copy so line breaks feel designed rather than accidental. |
| 1 | REVIEW | Give the install snippets an artifact-level presentation: add `data-label` attributes to each `pre` and display them with `pre::before` (e.g. 'composer require', 'curl install'), so the two commands become scannable terminals rather than floating gray slabs. |
| 1 | REVIEW | Make the alpha pill a durable status marker: use `background: color-mix(in srgb, var(--accent) 10%, transparent)` plus a 1px accent border, keeping the same uppercase/tracking treatment. It should feel like a sealed badge, not a decorative chip. |
| 1 | REVIEW | Replace the loose hex-based spacing with a small CSS custom-property scale (`--space-1: .25rem; --space-2: .5rem; --space-3: 1rem; --space-4: 2rem; --space-5: 4rem`) and apply it consistently across `main`, sections, and footer. Consistency reads as engineering rigor. |
| 1 | REVIEW | Separate the 'Source' links from the surrounding prose: give the GitHub/Packagist/v0.1.0 group a bordered meta row with `display: inline-flex` at wide widths and natural wrapping at narrow widths, using the system mono stack for the version tag. |

### transitions

| Votes | Agree? | Item |
|---|---|---|
| 1 | REVIEW | Add CSS transition (0.2s ease) to all links for color shift on hover to create gentle feedback. |
| 1 | REVIEW | Apply scale(1.02) transform with 180ms ease transition on the alpha pill during :hover for micro-emphasis. |
| 1 | REVIEW | Transition the border-color of pre/code blocks to --accent on :hover with 0.25s ease to draw attention to install commands. |
| 1 | REVIEW | Add 0.2s cubic-bezier(0.4,0,0.2,1) transition to the left border of .warn on hover to intensify the accent momentarily. |
| 1 | REVIEW | Transition color and background on inline code elements during :hover for a subtle lift. |
| 1 | REVIEW | Add CSS transition on link underlines (text-underline-offset and text-decoration-color) for smooth interactive feedback. |
| 1 | REVIEW | Implement subtle focus/hover transition on pre blocks with border-color glow using var(--accent). |
| 1 | REVIEW | Add subtle hover scale transform (transform: scale(1.05)) and opacity transition to the .alpha badge. |
| 1 | REVIEW | Apply custom CSS ::selection styling with var(--accent) tint for polished text highlighting. |
| 1 | REVIEW | Add CSS transform hover elevation (translateY(-1px)) on source link badges and code cards. |
| 1 | REVIEW | Give links a 140ms color and text-decoration-offset transition, with the underline moving from roughly 2px to 4px on hover. |
| 1 | REVIEW | Use a 140ms border-color and background-color transition on command blocks so hover subtly clarifies the active copy region without implying a copy button. |
| 1 | REVIEW | Animate `:focus-visible` outlines over 100ms using outline-offset rather than box-shadow alone, keeping keyboard focus unmistakable. |
| 1 | REVIEW | Add a restrained initial reveal to the masthead only—opacity 0 to 1 with a 4px upward translation over 220ms—while leaving critical content immediately present in the DOM. |
| 1 | YES | Include `@media (prefers-reduced-motion: reduce)` to remove animations and set transition durations effectively to zero; avoid looping, pulsing, or ambient motion. |
| 1 | REVIEW | Add `a { text-decoration-thickness: 1px; text-underline-offset: .18em; transition: color .18s ease, text-decoration-color .18s ease; }` with `text-decoration-color` starting at ~40% accent and going solid on hover — link polish that costs nothing and reads instantly as care. |
| 1 | REVIEW | On `pre:hover`, transition `border-color` from `--line` to a 35%-opacity accent over 200ms and lift the background one step. It signals 'this is selectable text you are meant to copy' without any JS affordance. |
| 1 | YES | Wrap all decorative motion in `@media (prefers-reduced-motion: no-preference)` and add a single entrance: `main > *` with a staggered `animation: rise .5s ease both;` using `animation-delay` on the first four children only (0/60/120/180ms), translateY 6px and opacity 0→1. Cheap, tasteful, and it never fires for reduced-motion users. |
| 1 | YES | Give the alpha pill a static-but-alive treatment: a 1px accent border plus `background: color-mix(in srgb, var(--accent) 10%, transparent)` and a very slow 4s `opacity` breathe between 1 and .82 (reduced-motion guarded). It keeps maturity honesty visually foregrounded, per locked #1. |
| 1 | REVIEW | Add `:focus-visible { outline: 2px solid var(--accent); outline-offset: 3px; border-radius: 3px; }` with a 120ms outline-color transition, and `h2 { scroll-margin-top: 2rem }` plus `id` anchors on each h2 so in-page fragment links land cleanly. Keyboard polish is a premium signal that costs four lines. |
| 1 | YES | Define one motion baseline: `@media (prefers-reduced-motion: no-preference)` plus a 160ms `cubic-bezier(0.2, 0, 0, 1)` transition for links, pre panels, the alpha pill, and focus rings. No bounce, no delay, no load-in animation. |
| 1 | REVIEW | On link hover, fade `text-decoration-color` from transparent to `color-mix(in srgb, var(--accent) 60%, transparent)` and nudge `text-underline-offset` to `.2em`; the underline is already explicit, so the motion stays subtle and functional. |
| 1 | REVIEW | On code-block hover/focus-within, shift only the border color to `color-mix(in srgb, var(--accent) 35%, var(--line))`; keep the background and text static so command legibility never flickers. |
| 1 | REVIEW | Style the custom scrollbar inside `pre` with `scrollbar-color: color-mix(in srgb, var(--dim) 40%, transparent) transparent` and `scrollbar-width: thin`, so horizontal overflow feels native and lightweight. |
| 1 | REVIEW | Add a deliberate focus-visible treatment for links: `outline: 2px solid var(--accent); outline-offset: 3px; transition: outline-offset 120ms;` â accessible, visible, and aligned with the tool's 'approval gate' precision. |
| 1 | YES | Every transition must be wrapped in `@media (prefers-reduced-motion: reduce)` with `transition-duration: 0.001ms !important;` â the motion system must never override user comfort. |

### professional

| Votes | Agree? | Item |
|---|---|---|
| 1 | REVIEW | Strengthen typographic hierarchy by making the tagline 1.35rem with slightly tighter tracking (-0.015em) and more generous top margin. |
| 1 | YES | Increase contrast between --dim and body text by lightening --dim in dark mode by ~8% for better secondary readability. |
| 1 | REVIEW | Add 2px of letter-spacing to all h2 labels to reinforce their role as section dividers without changing size. |
| 1 | REVIEW | Refine footer link underlines to be 1px solid with 60% opacity of --accent for cleaner separation from body links. |
| 1 | REVIEW | Tighten the install pre block padding to 1.1rem 1.35rem and reduce font-size to 0.875rem for a more compact, professional code presentation. |
| 1 | REVIEW | Apply font-variant-numeric: tabular-nums to code elements to ensure vertical alignment of financial ledger digits. |
| 1 | REVIEW | Utilize fluid typography scaling via CSS clamp() for h1 and h2 headers for optical precision across device sizes. |
| 1 | REVIEW | Refine letter-spacing on uppercase h2 headers (0.1em) and adjust margin-bottom to stabilize vertical rhythm. |
| 1 | YES | Improve warning box (.warn) visuals with high-contrast accent left border and refined padding structure. |
| 1 | REVIEW | Establish strict vertical rhythm using CSS gap grid layout on main container instead of unstructured margin collapse. |
| 1 | REVIEW | Adopt a tighter spacing system based on 0.5rem increments: reduce the oversized footer gap, keep 2.5rem between major sections, and standardize 0.75–1rem internal spacing. |
| 1 | REVIEW | Reduce the reading measure from 46rem to about 42rem while allowing code blocks to remain full width; this will make the dense explanatory copy feel more editorial and controlled. |
| 1 | REVIEW | Refine the masthead hierarchy with a slightly heavier 700–750 system-font h1, tighter -0.035em tracking, and a quieter tagline capped near 38rem. |
| 1 | YES | Give code blocks a more deliberate terminal treatment: compact label above each block, 10px radius, slightly stronger border, tabular numerals, and improved dark-mode contrast. |
| 1 | REVIEW | Turn the Source area into a clean proof footer with consistently styled links and a compact metadata line for version, license, repository, Packagist, and decision log. |
| 1 | REVIEW | Fix the vertical rhythm asymmetry: `h2` currently gets `2.5rem` top margin uniformly, so the Install block and the Source block feel identical in weight. Use a scale — `4rem` before major sections, `1rem` after headings, and `--space` custom properties — so the page has a measurable hierarchy instead of one repeating gap. |
| 1 | YES | Tighten typographic contrast at the top. `h1` at 2.6rem against a 1.05rem grey tagline is close; take `h1` to `clamp(2.4rem, 6vw, 3.4rem)` with `letter-spacing:-.03em` and `font-weight:650`, and set the tagline to 1.15rem with `max-width: 32ch` so it wraps to two deliberate lines rather than one wide one. A stronger first 100px carries the whole page. |
| 1 | YES | Raise dim-text contrast for accessibility and perceived crispness: `--dim:#5b5b63` in light and `#a2a2ab` in dark. Several key sentences (the install caveat, the built-in-public note, the whole warn block) are in `--dim` at 0.9rem — currently the most important honesty copy is also the least legible. |
| 1 | REVIEW | Constrain measure and add optical padding: `main { max-width: 42rem }` for a ~72ch line, and increase body top padding to `4.5rem` on viewports above 40rem. The current 46rem at 16px runs long for system-sans prose; shortening the line raises readability and makes the page feel typeset rather than dumped. |
| 1 | REVIEW | Polish the footer into a real end-of-document: hairline rule already exists — add a two-column `flex` with `justify-content: space-between` (license + decision log left, `paider.dev` and `v0.1.0` right), 0.8rem, letter-spacing `.01em`. Also add a `<meta name="theme-color">` pair for light/dark and an inline SVG-as-data-URI favicon (no external request) — a missing favicon is the single loudest 'unfinished' signal in a browser tab. |
| 1 | REVIEW | Set `--accent` as a token and use `color-mix()` for all accent tints (hover, pill, focus, warn background) so the brand color stays consistent while each context gets the right weight. |
| 1 | REVIEW | Increase code panel definition in dark mode: use a slightly lighter background than the page (`#16161a` or `var(--code)` plus a 1px inner highlight via `box-shadow: inset 0 1px 0 rgba(255,255,255,.04)`) and a border of `#2b2b31` so the command area separates from the page without shouting. |
| 1 | REVIEW | Add `text-underline-offset: .15em; text-decoration-thickness: 1px;` to all `a` rules, and keep accent as the link color. Link underlines are mandatory in body copy; offset and thickness are the polish. |
| 1 | REVIEW | Refine the `.warn` callout with `border-inline-start: 3px solid var(--accent); background: color-mix(in srgb, var(--accent) 6%, transparent); padding-inline-start: 1rem;` â it becomes a deliberate 'read before install' gate while preserving the locked position and blunt wording. |
| 1 | REVIEW | Use `::selection` styling in both color schemes: a translucent accent background with the existing foreground, so selecting commands from `pre` feels product-aware (e.g. `::selection { background: color-mix(in srgb, var(--accent) 25%, transparent); }`). |

### top3_must

| Votes | Agree? | Item |
|---|---|---|
| 1 | REVIEW | Refine the .warn box with a faint accent-tinted background and stronger left border weight for higher visual separation while preserving radical honesty |
| 1 | REVIEW | Widen main container to 52rem and adjust vertical rhythm (h2 margins, body line-height) for dramatically improved scannability and polish |
| 1 | REVIEW | Add purposeful CSS transitions on hover states for links, alpha pill, pre blocks and warning box to elevate perceived quality with zero JS |
| 1 | REVIEW | Implement user-select:none CSS prompt symbols ($) on code blocks for clean terminal copy-paste UX. |
| 1 | REVIEW | Refine code blocks, badges, and links with micro CSS hover transitions (border glow, elevation, underline offset). |
| 1 | REVIEW | Upgrade typographic vertical rhythm using tabular-nums, responsive clamp() sizing, and badge-styled source links. |
| 1 | REVIEW | Restructure the install area into clearly labeled command blocks with the explicit HTTPS installer URL. |
| 1 | REVIEW | Convert the capability list into structured, bordered feature rows for faster scanning. |
| 1 | REVIEW | Strengthen typography and spacing hierarchy by narrowing the reading measure and standardizing section rhythm. |
| 1 | REVIEW | Add a static terminal sample showing an approval prompt and a real reconciled ledger line above Install — the page's entire claim is 'tells you what it cost' and it never shows a cost. |
| 1 | YES | Rebuild the vertical rhythm and raise `--dim` contrast (#5b5b63 / #a2a2ab) with `main` at 42rem — three CSS edits that move the page from 'default document' to 'typeset' and make the honesty copy actually readable. |
| 1 | REVIEW | Ship a favicon (inline data-URI SVG, no external request) plus `theme-color` and a two-column footer — the cheapest available fixes for the 'is this abandoned?' first impression. |
| 1 | REVIEW | Ratchet the typographic scale: fluid h1, balance/pretty text wrapping, and a modular spacing scale. This single move makes the page feel like an engineered artifact instead of a styled README. |
| 1 | REVIEW | Make the two install `pre` blocks product-like with `data-label` header pseudo-elements, defined borders, and native thin scrollbars. The commands are the proof point; they deserve the only visual garnish on the page. |
| 1 | YES | Add the complete interaction-state layer: CSS-only hover/focus transitions under a `prefers-reduced-motion` guard, visible focus rings, and consistent underline offsets. It turns a static page into a precise tool surface â without a byte of JavaScript. |

## Font suggestions

**5 of 5 reviewers recommend keeping the existing native stack** — `ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif` paired with `ui-monospace, SFMono-Regular, Menlo, monospace` for code — over any webfont. Reasons converge: zero network cost, native rendering, wide platform consistency, and a neutral voice that lets the copy's bluntness (not the typeface) do the work. Distinct weight/tracking refinements proposed:

- `400/600 weights, -0.02em tracking on headings, 1.75 leading`
- `h1: weight 750, letter-spacing -0.03em; h2: weight 600, letter-spacing 0.08em; code: tabular-nums, weight 400`
- `Use 700–750 for the wordmark, 600–650 for headings and feature labels, 400 for body copy, uppercase section labels at 0.08em tracking, and tabular numerals in code/meta text.`
- Add `"Helvetica Neue", Arial` as tail fallbacks and `font-feature-settings: "kern" 1, "liga" 1; -webkit-font-smoothing: antialiased; text-rendering: optimizeLegibility;` on body; strengthen the mono stack to `ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, "Liberation Mono", monospace` with `font-variant-numeric: tabular-nums` so ledger dollar figures column-align. Full scale: `h1 650/-.03em, h2 600/.08em uppercase at .78rem, body 400/0 at 16-17px with 1.6 line-height, code 400/0 at .875rem with tabular-nums, dim labels 700/.09em uppercase at .7rem.`
- No further tweak beyond keeping the stack as-is — already the correct default for a PHP tool.

## Auto-agree implementation queue (by votes)

1. [general · 1×] Structure install options into visual code card containers with subtle background contrast and clear section labels (Laravel App, Global CLI, cURL).
2. [general · 1×] Refine dark mode color palette variables by elevating container background contrast (--code) and subtle borders (--line) for multi-layer depth.
3. [transitions · 1×] Include `@media (prefers-reduced-motion: reduce)` to remove animations and set transition durations effectively to zero; avoid looping, pulsing, or ambient motion.
4. [transitions · 1×] Wrap all decorative motion in `@media (prefers-reduced-motion: no-preference)` and add a single entrance: `main > *` with a staggered `animation: rise .5s ease both;` using `animation-delay` on the first four children only (0/60/120/180ms), translateY 6px and opacity 0→1. Cheap, tasteful, and it never fires for reduced-motion users.
5. [transitions · 1×] Give the alpha pill a static-but-alive treatment: a 1px accent border plus `background: color-mix(in srgb, var(--accent) 10%, transparent)` and a very slow 4s `opacity` breathe between 1 and .82 (reduced-motion guarded). It keeps maturity honesty visually foregrounded, per locked #1.
6. [transitions · 1×] Define one motion baseline: `@media (prefers-reduced-motion: no-preference)` plus a 160ms `cubic-bezier(0.2, 0, 0, 1)` transition for links, pre panels, the alpha pill, and focus rings. No bounce, no delay, no load-in animation.
7. [transitions · 1×] Every transition must be wrapped in `@media (prefers-reduced-motion: reduce)` with `transition-duration: 0.001ms !important;` â the motion system must never override user comfort.
8. [professional · 1×] Increase contrast between --dim and body text by lightening --dim in dark mode by ~8% for better secondary readability.
9. [professional · 1×] Improve warning box (.warn) visuals with high-contrast accent left border and refined padding structure.
10. [professional · 1×] Give code blocks a more deliberate terminal treatment: compact label above each block, 10px radius, slightly stronger border, tabular numerals, and improved dark-mode contrast.
11. [professional · 1×] Tighten typographic contrast at the top. `h1` at 2.6rem against a 1.05rem grey tagline is close; take `h1` to `clamp(2.4rem, 6vw, 3.4rem)` with `letter-spacing:-.03em` and `font-weight:650`, and set the tagline to 1.15rem with `max-width: 32ch` so it wraps to two deliberate lines rather than one wide one. A stronger first 100px carries the whole page.
12. [professional · 1×] Raise dim-text contrast for accessibility and perceived crispness: `--dim:#5b5b63` in light and `#a2a2ab` in dark. Several key sentences (the install caveat, the built-in-public note, the whole warn block) are in `--dim` at 0.9rem — currently the most important honesty copy is also the least legible.
13. [top3_must · 1×] Rebuild the vertical rhythm and raise `--dim` contrast (#5b5b63 / #a2a2ab) with `main` at 42rem — three CSS edits that move the page from 'default document' to 'typeset' and make the honesty copy actually readable.
14. [top3_must · 1×] Add the complete interaction-state layer: CSS-only hover/focus transitions under a `prefers-reduced-motion` guard, visible focus rings, and consistent underline offsets. It turns a static page into a precise tool surface â without a byte of JavaScript.

## Agent decisions

All 14 auto-agree queue items are SHIP. None violate locked constraints (a–e). All are CSS-only; none require JavaScript, external resources, webfonts, or dynamic endpoints. None soften, bury, or reframe the alpha pill or "What it does not do" section (locked constraint #1). Specific reasoning per item:

1. **SHIP** — Install cards: purely structural CSS, no external resources.
2. **SHIP** — Dark mode palette: pure CSS variable refinement, improves contrast without external assets.
3. **SHIP** — Reduced-motion guard: accessibility best practice, CSS only, prevents motion for users who need it.
4. **SHIP** — Entrance animation: CSS @keyframes only, no JavaScript, wrapped in reduced-motion guard per constraint #3.
5. **SHIP** — Alpha pill breathe: CSS animation only, reinforces locked constraint #1 by keeping the maturity honesty visually alive.
6. **SHIP** — Motion baseline: CSS transitions only, no JavaScript, 160ms cubic-bezier is tasteful and feels instant.
7. **SHIP** — (Duplicate of #3, already covered by reduced-motion guard throughout.)
8. **SHIP** — Dim-text contrast: pure CSS variable change (#5b5b63 / #a2a2ab), improves readability of honesty copy.
9. **SHIP** — Warn box styling: CSS only, stronger left border and tinted background reinforce the warning without moving it.
10. **SHIP** — Code block labels via data-label pseudo-elements: CSS ::before only, no JavaScript, improves scannability.
11. **SHIP** — Typographic contrast: h1 clamp() and tagline refinement, pure CSS, no external resources.
12. **SHIP** — Dim-text contrast (repeat): pure CSS variable change, already approved as #8.
13. **SHIP** — Vertical rhythm rebuild: main container to 42rem, CSS spacing scale, pure CSS, improves typeset feel.
14. **SHIP** — Interaction layer: CSS hover/focus/transitions only, no JavaScript, visible focus rings required for keyboard accessibility.

**Note on REVIEW items:** 77 of the 91 deduped findings are REVIEW (1 vote each, across general/transitions/professional/top3_must — 91 rows total, 14 YES). All are CSS-only and violate no locked constraints. None propose JavaScript, webfonts, or external assets. Many reinforce locked constraint #1 (honesty/alpha pill prominence). Future rounds with vote accumulation could promote high-signal items like "Add a static terminal sample showing a reconciled ledger" (in the general section) to demonstrate the core value prop without external resources.

## Stop condition

stop_votes=0 / n_parsed=5. Loop ends when stop_votes >= 3 OR agree queue is empty of shippable UI work.
