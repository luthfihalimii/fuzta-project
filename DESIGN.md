# DESIGN.md — Pizza Fruzta

Design system and UX guidelines for the public website, customer ordering, Flutter app, POS, kitchen display, and admin. Read together with `PRD.md` (§6, §9.5, §12) and `AGENTS.md`.

> **Status: Draft.** The PRD states that logo, colors, typography, and photography **must be confirmed with Pizza Fruzta, not assumed**. Green `#056A4D` is confirmed; yellow/orange and typography remain working defaults. Keep tokens in `resources/css/app.css` so provisional values can be swapped in one place. Do not hard-code values in components.

## 1. Design intent

Two audiences, two registers, one system:

| Surface                                        | Job                                                     | Register                                                 |
| ---------------------------------------------- | ------------------------------------------------------- | -------------------------------------------------------- |
| Public website, customer ordering, Flutter app | Make the food look good and ordering effortless         | Expressive: photography, color, personality              |
| POS, kitchen display, admin                    | Let staff work fast and without mistakes under pressure | Functional: neutral, dense where useful, high legibility |

Rule: **brand expression lives on customer surfaces; staff surfaces prioritize speed, clarity, and error prevention.** Do not decorate the POS.

Spend boldness in one place per surface: on customer pages it is the food photography and a confident headline; on staff surfaces it is the status system (§5). Everything else stays quiet.

## 2. Design principles

1. **Appetite first (customer).** Product photography and price are the first things seen and easy to scan.
2. **Speed over beauty (staff).** Fewer taps, larger targets, instant feedback.
3. **State is always visible.** Order status, payment status, and branch are never ambiguous.
4. **Never rely on color alone.** Every status has a text label and/or icon.
5. **Prevent, then confirm.** Destructive or sensitive actions (void, refund, stock adjustment) need a confirmation that names the consequence.
6. **Transparent money.** Totals, fees, and discounts are shown before payment; what is displayed matches the server-calculated total.
7. **Plain language.** Written for restaurant staff and customers, not engineers.

## 3. Brand tokens (green confirmed; yellow/orange provisional)

Brand name: "Pizza Fruzta". Do not invent a logo, mascot, or tagline. Use a text wordmark placeholder until the real logo is supplied.

### Color

| Token          | Hex       | Role                                              |
| -------------- | --------- | ------------------------------------------------- |
| `brand`        | `#056A4D` | Brand accent, primary CTA                         |
| `brand-yellow` | `#FFC72C` | Promotions, badges, kitchen "new order" highlight |
| `brand-orange` | `#F47B20` | Secondary accent, kitchen preparing state         |
| `surface`      | `#FFFFFF` | Cards, sheets                                     |
| `canvas`       | `#F4F6F4` | Page background (customer and staff)              |
| `ink`          | `#1F2421` | Primary text                                      |
| `muted`        | `#5F6B65` | Secondary text                                    |
| `line`         | `#D8DED9` | Dividers, input borders                           |

Constraints:

- Green `brand` is the confirmed brand base. Yellow and orange are **provisional** until Pizza Fruzta supplies official hex values; replace them in `resources/css/app.css` without touching components.
- Text/background pairs meet **WCAG AA** (4.5:1 body, 3:1 large text and UI components). Verify before shipping; do not assume the hexes above pass in every combination.
- Do not use gradient washes as decoration or identical soft-shadow card grids as the default layout.
- Brand colors must not signal errors or status. See semantic colors below.

### Semantic and status colors (staff and customer)

Keep these **separate from brand colors** so a branded action is never confused with an error or warning.

| Token     | Hex       | Use                                                                                                           |
| --------- | --------- | ------------------------------------------------------------------------------------------------------------- |
| `danger`  | `#B3261E` | Errors, destructive actions, failed payment (always with icon + text; never used as a decorative brand color) |
| `warning` | `#9A5B00` | Low stock, price changed, pending transfer                                                                    |
| `success` | `#1E6B3A` | Paid, ready, completed                                                                                        |
| `info`    | `#1F5FA8` | Neutral informational states                                                                                  |
| `neutral` | `#5C6670` | Queued, draft, cancelled                                                                                      |

### Kitchen display theme

The kitchen display has a fixed dark theme on every viewport. Do not follow the system light/dark preference; the kitchen environment and TV use case require consistent contrast.

| Token                  | Hex       | Use                     |
| ---------------------- | --------- | ----------------------- |
| `kitchen-canvas`       | `#0D120E` | Page background         |
| `kitchen-surface`      | `#161D18` | Order cards and panels  |
| `kitchen-text`         | `#F4F1E8` | Primary text            |
| `kitchen-muted`        | `#AAB5AC` | Secondary text          |
| `kitchen-accepted`     | `#60A5FA` | Accepted badge          |
| `kitchen-ready`        | `#22C55E` | Ready badge and success |
| `kitchen-danger-text`  | `#FCA5A5` | Error text on dark      |
| `kitchen-success-text` | `#86EFAC` | Success text on dark    |

### Order and payment status mapping

Order status and payment status are **independent** (see `AGENTS.md`) and are shown as **two separate badges**, never merged into one.

| Order status | Badge                 | Payment status       | Badge   |
| ------------ | --------------------- | -------------------- | ------- |
| Queued       | neutral               | Unpaid               | warning |
| Accepted     | info                  | Paid                 | success |
| Preparing    | warning               | Failed               | danger  |
| Ready        | success               | Refunded             | neutral |
| Completed    | neutral (muted)       | Pending confirmation | info    |
| Cancelled    | neutral + strike icon |                      |         |

Each badge: icon + label + color. Labels are sentence case, in Bahasa Indonesia in the UI.

### Typography [PROPOSED]

Choose families that are free to use, support Latin Extended, and have **tabular numerals** (essential for prices and quantities).

| Role                          | Proposal                                                                  | Notes                                                        |
| ----------------------------- | ------------------------------------------------------------------------- | ------------------------------------------------------------ |
| Customer headings/wordmark    | One characterful display or grotesque family, to be chosen with the brand | Used for headings and the menu; do not use it for dense data |
| Body and UI (all surfaces)    | One highly legible sans family                                            | Staff UI uses this only                                      |
| Numbers (prices, qty, totals) | Same body family with `font-variant-numeric: tabular-nums`                | Right-align money in tables and carts                        |

Scale (rem, base 16px): 0.875 / 1 / 1.125 / 1.25 / 1.5 / 2 / 2.75. Body line-height 1.5; headings 1.2. Keep text lines under ~75 characters. Minimum UI text on POS: 16px; primary item names and prices on POS: 18px+.

Avoid: tracked-out all-caps eyebrow labels above every heading, accenting a single word of a headline in a different color, and decorative labels that add no information.

### Shape, spacing, elevation

- 4px spacing base: 4, 8, 12, 16, 24, 32, 48, 64.
- Radius: small 6px (inputs, badges), medium 12px (cards, sheets), full for chips. Use radii by element role, not one value everywhere.
- Elevation: borders (`line`) are the default separator; use shadow only for floating layers (modals, dropdowns, sticky cart bar).

## 4. Customer surfaces (website and Flutter app)

### Principles

- Mobile-first. Most customers will order on a phone.
- Menu: category navigation always reachable; product card shows photo, name, short description, and "from" price.
- Customization (size, crust, toppings, add-ons) is progressive: required choices first, optional add-ons after, running price always visible.
- Cart totals, fees, and discounts are transparent **before** payment. If an item becomes unavailable or a price changes, say so clearly at the point it happens and let the customer decide.
- Branch and fulfillment method (pickup / delivery / dine-in, where enabled) are chosen early and always visible in the cart.
- Order confirmation shows the order number, payment state, and the next step.
- Order tracking shows current status from the backend. A push notification is a convenience, never the only source of truth.

### Imagery

- Use only **approved product photography** from Pizza Fruzta. Until supplied, use neutral placeholders that are clearly placeholders (no stock or generated pizza photos in production).
- Optimize image sizes; always provide alt text and reserve space to avoid layout shift.
- Show allergen information only when supplied and verified by Pizza Fruzta.

### Hero and layout

- Lead the homepage with the most characteristic thing: the food, plus a direct "Order now" action and the nearest branch/hours. Do not default to a big-number-with-gradient hero.

```
Home (mobile)
+------------------------+
| Wordmark      [Branch] |
|------------------------|
|  [ Hero photo ]        |
|  Headline              |
|  [ Order now ]         |
|------------------------|
|  Categories  > > >     |
|  [Product card]        |
|  [Product card]        |
|------------------------|
|  Cart bar: 2 items  Rp |
+------------------------+
```

## 5. POS (browser, phone, tablet and desktop)

### Principles

- **Phone-first** for this release: a cashier must be able to complete a sale from 360px width upward. Tablet landscape is the primary in-store layout; desktop is supported.
- Large touch-friendly controls: **minimum touch target 48×48px, primary actions 56px+**. Quantity steppers are 48×48px.
- Popular items and categories reachable in **one tap**. Search is always visible.
- A cashier completes a standard sale in as few steps as possible; avoid modal chains.
- Branch and cashier are always visible in the header. Cashier shifts are deferred from MVP; do not show a shift status.
- Payment is taken at the start. Supported methods are cash and manual static QRIS; record the payment reference for QRIS.
- Destructive/sensitive actions (void, refund, cancel) require a reason and a confirmation that states the effect (e.g. "Void order #A-0142? Stock will be returned.").
- Permission-gated actions are hidden **or** disabled with an explanation; the server remains the real gate.
- Never block the whole screen for slow network. Show clear in-progress, success, and failure states, and make retries safe (idempotent).

```
POS (tablet landscape)
+----------------------------------------------------------+
| Branch X | Cashier                    [Search........] |
|----------------------------------------------------------|
| Categories |  Product grid              |  Current order  |
| [Pizza]    |  [item] [item] [item]      |  1x Item   Rp   |
| [Drinks]   |  [item] [item] [item]      |  note ...       |
| [Sides]    |  [item] [item] [item]      |-----------------|
|            |                            |  Subtotal  Rp   |
|            |                            |  Total     Rp   |
|            |                            | [Bayar]         |
+----------------------------------------------------------+
```

On phones the order summary becomes a sticky bottom bar; tapping it opens the cart sheet. Do not shrink targets to fit more columns.

## 6. Kitchen display

- Responsive from a kitchen phone (360px) to a wall-mounted TV. Cards use a fluid grid; on a TV, type and controls scale up rather than adding more columns.
- Fixed dark theme using the kitchen tokens above. High contrast is required at 1–2 meters.
- Shows orders for the assigned branch only, sorted by accepted time (or configured priority).
- Each order card: order number, fulfillment type, elapsed time, items with quantities, **options and special instructions prominently**, and one primary action for the next status (Accept → Start preparing → Mark ready → Complete).
- Statuses: queued, accepted, preparing, ready, completed. Every transition is timestamped and attributed.
- Elapsed-time indicator escalates through `kitchen-muted` → `warning` → `danger` using configurable thresholds (P1).
- Do not show customer phone number or address to kitchen staff unless the workflow needs it.
- Sound/visual alert for new orders (P1). Visual alert must work without sound and respect `prefers-reduced-motion` (use a static highlight when motion is reduced).

## 7. Admin

- A **branch filter is visible** on every report and stock screen, with an explicit "All branches" option for authorized roles.
- Lists have search, filters, pagination, and a useful empty state.
- Money columns are right-aligned, tabular, and formatted consistently.
- Surface problems proactively: low stock, stock discrepancies, failed payments, pending transfers, transfer quantity mismatches.
- Reports distinguish **sales, discounts, refunds, tax/fees, payment receipts, and estimated costs**. Label estimated values as estimates. Revenue is not profit.
- Stock movement history and audit logs are read-only views; corrections happen through new adjustment entries, never edits.

## 8. Content and microcopy

- **Language:** UI in Bahasa Indonesia by default; keep strings in translation files, not hard-coded, so English can be added.
- **Tone:** friendly, short, plain verbs, sentence case. No filler.
- **Buttons say exactly what happens:** "Simpan perubahan", not "Kirim". The same action keeps the same name through the whole flow (button "Bayar" → toast "Pembayaran diterima").
- **Errors** say what happened and how to fix it. They do not apologize or blame. Example: "Stok mozzarella di cabang ini tidak cukup. Kurangi jumlah atau pilih cabang lain."
- **Empty states** invite an action ("Belum ada pesanan hari ini").
- Name things by what users understand (stok, pesanan, pembayaran), not by how the system is built.

### Formatting conventions

- Currency: `Rp 85.000` (rupiah, dot as thousands separator, no decimals unless required). Format with a shared helper; never concatenate manually.
- Dates and times: `Asia/Jakarta`, 24-hour time, e.g. `26 Okt 2026, 14:30`.
- Order numbers: monospace-free, clearly separated from surrounding text, copyable.

## 9. Accessibility and quality floor

Applies to every surface (PRD §9.5):

- Responsive from small phone to desktop; no horizontal scroll at 360px width.
- Visible **keyboard focus** on all interactive elements; logical tab order for critical web flows.
- Contrast meets WCAG AA. Status is never conveyed by color alone.
- Form fields: visible labels, inline validation, and actionable error messages tied to the field.
- Respect `prefers-reduced-motion`.
- Every async view has **loading, empty, success, and error** states (also required by Definition of Done in `AGENTS.md`).
- Touch targets: 48px minimum (POS primary actions 56px+).

## 10. Motion

- Use motion to show what changed after a person's action (item added to cart, status changed, panel opened). Keep it short (150–250ms).
- No decorative entrance animations on every section and no hover effects on every card.
- Exception: one deliberate attention moment, such as a new order arriving in the kitchen queue.

## 11. Implementation guidance

- **Single token source.** Define all tokens above as CSS custom properties (web) and mirror them in the Flutter `ThemeData`/`ThemeExtension`. Components consume tokens only; no raw hex values or magic spacing numbers in components.
- **Shared components first.** Build and reuse: `Button`, `Input`, `Select`, `Badge` (order and payment variants), `Money`, `QuantityStepper`, `ProductCard`, `CartLine`, `OrderCard`, `ConfirmDialog` (with reason field), `DataTable`, `EmptyState`, `Toast`. Add new variants before adding one-off styles.
- **Components needing state variants:** default, hover/pressed, focus, disabled, loading, error.
- **Performance:** optimize images, lazy-load below the fold, keep the customer menu fast on ordinary mobile networks.
- **Theme per surface, not per component.** Customer and staff surfaces share tokens but use different density settings (comfortable vs compact spacing). The kitchen uses its fixed dark token set; all other surfaces use the light `canvas` theme.
- Do not introduce a UI library without agreement; if one is adopted, map its theme to these tokens.

## 12. Open design questions (ask, do not assume)

- Final logo, official yellow/orange hex values, typography, and tone of voice. Green `#056A4D` is the confirmed base.
- Approved product photography and who produces it.
- Whether the customer app and website share the exact same visual language or the app is lighter.
- Receipt layout and printer width (depends on printer model).
- Kitchen display hardware (tablet, monitor, printer) and viewing distance.
- Whether English UI is needed at launch.
- Light/dark mode for customer and admin surfaces is out of scope for MVP. The kitchen display is fixed dark by design.

## 13. Review checklist (before merging UI changes)

- [ ] Uses tokens only; no raw colors or spacing.
- [ ] Meets contrast and touch-target minimums.
- [ ] Loading, empty, success, and error states exist.
- [ ] Status shown with text/icon, not color alone.
- [ ] Money is formatted via the shared helper and right-aligned in tables.
- [ ] Sensitive actions have a consequence-stating confirmation and reason.
- [ ] Works at 360px width and on tablet landscape (POS/kitchen).
- [ ] Copy is Bahasa Indonesia, sentence case, active voice, no vague errors.
