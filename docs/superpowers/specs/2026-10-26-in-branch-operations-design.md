# Pizza Fruzta: First Release Design (In-Branch Operations)

- Date: 26 October 2026
- Status: Draft for approval
- Scope: First release, in-branch operations only
- Source of product truth: `PRD.md`. This document narrows the first release and records decisions. If the two conflict, the conflict must be raised before building.

## 1. Intent and why

Pizza Fruzta runs two branches with paper-based ordering and stock records. The two biggest daily problems are:

1. Cashiers must walk to the kitchen to relay every order. This wastes cashier time and causes missed or misheard orders.
2. Stock is checked manually at closing. There is no history, no shared visibility, and no reliable low-stock signal.

This release replaces those two paper loops with one shared system while keeping the rest of the operation unchanged. Customer-facing ordering, delivery, recipes, purchasing workflows, and advanced reporting come later.

## 2. Goals

- A cashier can create a dine-in or takeaway order, take payment, and have it appear in the kitchen without leaving the counter.
- Kitchen staff can see incoming orders, update preparation status, and leave a timestamped history.
- Staff can record stock receipts, waste, adjustments, and closing counts in one ledger.
- The owner can see daily sales by branch and consolidated, plus current stock and low-stock items.
- Branch access and sensitive actions are enforced on the server and recorded in an audit log.
- Orders, payments, and stock movements remain correct and explainable across both branches.

## 3. Non-goals for this release

- Public brand website and customer online ordering.
- Flutter customer app.
- Customer accounts, order history, promotions, vouchers, and discounts.
- Delivery and delivery fees.
- Cashier shifts and cash drawer reconciliation.
- Recipe/BOM-based automatic ingredient deduction.
- Supplier records, purchase orders, goods receipts against POs, and inter-branch transfers.
- Payment gateway integration, QRIS API, refunds through a provider, and settlement reconciliation.
- Advanced reports, CSV export, and cost or margin reporting.
- Offline POS.
- Product photography and image management.
- Redis, WebSockets, and microservices.

These are deliberately excluded. The modules must be structured so they can be added without rewriting order, payment, or stock logic.

## 4. Anti-goals

The release fails if any of these happen:

- A cashier can read or change another branch's data by changing a request.
- The client can set a price, total, or payment state that the server accepts.
- A repeated checkout request creates a duplicate order.
- A paid order can be voided with no reason, no permission, or no audit record.
- A stock movement is edited or deleted instead of corrected with a new entry.
- Stock balances cannot be reproduced from the movement ledger.
- Printing is declared done without testing the actual printer.

## 5. Users and permissions

Three roles in this release. No custom role editor yet.

| Capability | Owner | Cashier | Kitchen |
|---|---|---|---|
| See and switch all branches | Yes | No | No |
| Manage branches | Yes | No | No |
| Manage staff accounts | Yes | No | No |
| Manage categories, products, prices, modifiers | Yes | No | No |
| Set product availability per branch | Yes | No | No |
| Create orders and take payment | Yes, any branch | Yes, own branch | No |
| Cancel unpaid order | Yes, any branch | Yes, own branch, before kitchen accepts | No |
| Void paid order with reason | Yes | No | No |
| View own branch orders | Yes | Yes | Yes |
| Update kitchen status | No | No | Yes, own branch |
| Record stock receipts and adjustments | Yes | No | No |
| Record waste and closing counts | Yes | No | Yes, own branch |
| Set low-stock thresholds | Yes | No | No |
| View reports | Yes, all branches | No | No |
| View audit log | Yes | No | No |

Rules:

- Owner has `branch_id = null` and may act on both branches.
- Cashier and kitchen have a non-null `branch_id`.
- Deactivate staff, never delete them.
- Authorization is enforced by policies and scoped queries. UI hiding is not access control.
- Login is rate limited. There is no public registration. The owner creates accounts and resets passwords.

## 6. Core flows

### 6.1 Cashier creates an order

1. Cashier signs in to their branch.
2. Cashier picks dine-in or takeaway, enters a table number for dine-in, searches or browses the catalog, chooses a product, size, crust, toppings/add-ons, quantity, and item note.
3. The cart shows a server-calculated estimate. The server remains the source of truth.
4. Cashier selects cash or QRIS static. For cash, the cashier may enter the amount received and the screen shows the change. For QRIS static, the cashier may wait for the customer to pay before confirming.
5. The server creates the order with order status `queued` and payment status `unpaid`, and generates the order number.
6. When the cashier confirms payment, the server records the payment, marks it `paid`, and makes the order visible to the kitchen in the same transaction. A retry of the confirmation does not create a second payment.
7. The order appears in the kitchen queue for that branch. Unpaid orders are visible to the cashier but not to the kitchen.

Payment is taken before the kitchen starts, for both dine-in and takeaway.

### 6.2 Kitchen handles the order

1. Kitchen sees queued orders for its branch, oldest first.
2. Kitchen accepts the order, then marks preparing, then ready, then completed.
3. Every transition stores the actor and timestamp.
4. Customer-facing information, if shown later, must not expose staff identity.

### 6.3 Cancel and void

- Cashier may cancel an unpaid order with a reason before the kitchen accepts it. No payment reversal is needed.
- Once paid, only the owner may void. A reason is required.
- Voiding sets payment status `voided` and order status `cancelled`, and writes a reversing payment record. It never deletes the original payment or order.
- There is no automatic stock deduction in this release, so voiding has no automatic inventory reversal. Any stock correction is a manual stock adjustment with a reason.

### 6.4 Stock

- Each ingredient has one base unit: gram, millilitre, or piece.
- The kitchen records a closing count per ingredient. The count may be partial: only ingredients that are filled in produce a correction. The system compares the counted quantity to the current balance and writes a `count_correction` movement for the difference with a reason.
- The owner records stock receipts, adjustments, and opening stock. The kitchen records waste.
- Every movement is append-only and includes branch, ingredient, signed quantity, unit, type, actor, timestamp, and reason or reference.
- Negative resulting balance is rejected.
- Low stock is a per-ingredient threshold in the base unit, set by the owner.
- Unit conversions and pack sizes are not supported yet. Purchases are entered in the base unit. This is explicit, not hidden.

### 6.5 Reports

Owner-only, filterable by date range and branch:

- Paid sales total, order count, and average order value.
- Sales by payment method.
- Product, variant, and modifier quantities.
- Current stock by branch, low-stock list, and stock movement history.

Voided orders are excluded from sales totals and shown separately.

## 7. Order and payment states

Two independent state machines.

Order status:

`queued` -> `accepted` -> `preparing` -> `ready` -> `completed`

`cancelled` is reachable from `queued` via cashier cancel or owner void, and from later states only through an owner void with reason.

Payment status:

`unpaid` -> `paid` -> `voided`

Payment methods in this release: `cash`, `qris_static`.

Assumptions:

- One payment per order. Split payment is not supported.
- An order reaches the kitchen only after payment is `paid`.
- An order cannot be edited after payment. To change items, void it and create a new order.
- Item-level rejection is not supported. If an item becomes unavailable after payment, the owner voids the order with a reason and the cashier informs the customer.
- The order number is human-readable: `PF-{branch code}-{YYMMDD}-{sequence}`, unique per branch per day, generated inside a locked transaction.
- Order creation accepts an idempotency key generated by the client. A duplicate key returns the existing order, not a new one.

## 8. Catalog model

- Categories: name, sort order, active.
- Products: category, name, description, base price, active.
- Sizes are product variants with their own price.
- Crust, toppings, and add-ons are modifier groups and modifiers, with min/max selection and price deltas.
- Each product can be available or unavailable per branch.
- Prices are the same in both branches in this release. Only availability is branch-specific.
- Product images are optional and not part of this release. The POS uses names and categories.
- Promotions and discounts are not part of this release.
- Prices are integer rupiah. The client sends product, variant, and modifier IDs plus quantity and notes. The server recalculates every line and total.

Order items store a snapshot of product name, variant name, modifier names and prices, unit price, quantity, line total, and notes at the time of sale.

## 9. Data model, initial

Module ownership is shown in parentheses.

Identity and access:

- `users`: name, email, password, role enum (`owner`, `cashier`, `kitchen`), `branch_id` nullable, active flag, timestamps.
- `branches`: name, code, address, phone, active flag, timestamps.
- `sessions` and password reset tables from Laravel.

Catalog:

- `categories`
- `products`
- `product_variants`
- `modifier_groups`
- `modifiers`
- `product_modifier_groups`
- `product_branch_availability`

Ordering and payments:

- `orders`: order number, branch, channel (`dine_in`, `takeaway`), table number optional, order status, payment status, subtotal, total, customer name optional, note, idempotency key, placed by, timestamps.
- `order_items`: snapshot fields above.
- `order_item_modifiers`: snapshot modifier name and price delta.
- `order_status_events`: from status, to status, actor, timestamp, note.
- `payments`: order, method, amount, cash received optional, change optional, status, reference optional, paid by, paid at, voided by, voided at, void reason.
- `payment_events`: append-only payment state history.

Inventory:

- `units`: code, name.
- `ingredients`: name, base unit, low-stock threshold, active.
- `stock_movements`: branch, ingredient, signed quantity, unit, movement type (`opening`, `receipt`, `waste`, `adjustment`, `count_correction`), reference type and id, reason, actor, occurred at, created at.
- `stock_balances`: branch, ingredient, quantity, updated at. Unique per branch and ingredient. Recomputable from the ledger.
- `stock_counts`: branch, counted by, counted at, note.
- `stock_count_items`: stock count, ingredient, counted quantity, system quantity, difference.

Operations:

- `audit_logs`: actor, branch, action, target type, target id, safe before and after JSON, reason, IP, user agent, created at. Append-only.

Database rules:

- Foreign keys and unique constraints on order number, idempotency key per branch, stock balance per branch and ingredient.
- Index branch, status, and timestamps used by queues and reports.
- Financial and stock rows are never hard-deleted.

## 10. Server rules

- Money is stored as integer rupiah. No floats.
- All prices and totals are recalculated on the server from IDs and quantities. Client totals are display only.
- Order creation, payment confirmation, order number generation, and stock movements run in database transactions.
- Order number generation locks a per-branch daily sequence.
- Stock writes lock the `(branch, ingredient)` balance row, append the movement, update the cached balance, and reject a negative result.
- Idempotency keys are unique per branch. A retry returns the original order.
- Timestamps are stored in UTC and displayed in `Asia/Jakarta`.
- Audit entries are written for price and catalog changes, staff and branch access changes, paid-order voids, stock receipts, waste, adjustments, count corrections, and low-stock threshold changes.
- Secrets never appear in logs or audit payloads.

## 11. Architecture

- Laravel modular monolith. Modules under `app/Modules/`: Identity, Branches, Catalog, Ordering, Payments, Kitchen, Inventory, Reporting, Audit.
- Business logic lives in actions or services inside modules. Inertia controllers and future API controllers call the same actions.
- Controllers stay thin: Form Request validation, policy authorization, one action call, response.
- Vue 3, TypeScript, Inertia.js, and Tailwind CSS for the web UI.
- PostgreSQL for all transactional data.
- Queue driver: database. Redis is not required for this release.
- Kitchen updates use polling every 5 seconds. No WebSockets in this release.
- REST endpoints under `/api/v1` are not part of this release. The action layer is built so they can be added for the Flutter app and online ordering without moving business logic.
- Deployment: single Linux VPS, Nginx, PHP-FPM, PostgreSQL, HTTPS, scheduler and queue worker via systemd or supervisor.
- Monitoring: uptime check, Laravel error logs, and failed-job alerts. No external monitoring service is required for this release.
- Backups: nightly `pg_dump` to S3-compatible storage with a documented restore test before pilot.

## 12. Screens

Cashier:

- Login.
- POS home: category tabs, search, product grid, cart, dine-in or takeaway, item notes, modifier selection.
- Payment: cash or QRIS static, amount due, confirm paid.
- Receipt preview and print.
- Today's orders for the branch, with status.

Kitchen:

- Kitchen queue: queued, accepted, preparing, ready, completed tabs, oldest first, item quantities, options, notes.
- Stock: closing count form per ingredient, waste entry.
- Low-stock view.

Owner:

- Dashboard: sales today, payment split, order count, low-stock items.
- Catalog management: categories, products, variants, modifier groups, modifiers, per-branch availability, prices.
- Staff management: create, deactivate, reset password, branch assignment.
- Stock: ingredients, units, thresholds, opening stock, receipts, adjustments, movement history.
- Reports: daily sales, payment split, product quantities, stock.
- Audit log.

All data screens have loading, empty, error, and success states. All destructive or sensitive actions require confirmation and, where specified, a reason.

## 13. Design direction

A deliberately quiet, operational interface with the Pizza Fruzta brand, not a generic dashboard.

- Brand green: `#056A4D` (provided).
- Yellow, orange, and red accents: taken provisionally from the supplied logo image. The provided hex codes for yellow and orange were identical to the green, so they are treated as a typo and must be confirmed before final polish.
- Logo: text wordmark "Pizza Fruzta". No logo is invented.
- Typography: Plus Jakarta Sans, chosen for a friendly, modern Indonesian character and strong small-size readability. No monospace headings.
- Surfaces: light theme for POS and admin because counters are bright and legibility matters. Dark theme for the kitchen display because it is often viewed in a dimmer kitchen and on a large screen. No user theme toggle in this release; each surface has one fixed, tested theme.
- Dials: ENERGY 2, RHYTHM 2, MOTION 1.
- Mobile-first. Minimum tap target 44px. High contrast. Visible focus states. No decorative gradients, glassmorphism, glows, or background grids.
- Status is communicated by text and color, never color alone.
- Currency format: `Rp 45.000`. UI language: Bahasa Indonesia.

Every visual decision must have a one-line reason. No fabricated statistics, testimonials, or trust claims.

## 14. Printing

- Receipt content is generated server-side from order snapshots: branch, order number, date and time, channel, items, modifiers, notes, total, payment method, and cashier.
- Receipt is shown on screen and can be printed.
- The printer supports Bluetooth, USB, and WiFi, but the model is unknown.
- Transport is not locked yet. A print adapter will isolate it. Recommended initial path for Android phones is RawBT or an equivalent ESC/POS bridge, tested against the actual printer before pilot.
- Printing is not considered done until tested with the branch printer.

## 15. Security

- Server-side authorization on every action, including branch scope.
- Form Request validation on every write.
- Rate limits on login and order creation.
- CSRF protection for Inertia requests.
- Password hashing with Laravel defaults.
- HTTPS in production, debug mode off.
- `.env` excluded from git. Secrets from environment.
- Audit logs exclude passwords, tokens, and payment secrets.
- Customer data collected in this release is minimal: optional name and order note. No phone number or address is required for dine-in or takeaway.

## 16. Testing

Backend feature tests with PHPUnit, using factories with two branches:

- Checkout totals: variants, modifiers, quantities, server recalculation, rejection of client-supplied prices.
- Idempotent retry returns the same order.
- Payment confirmation and state separation.
- Cashier cancel and owner void rules, including audit records.
- Branch access: cashier and kitchen cannot read or write another branch.
- Kitchen transitions and timestamps.
- Stock ledger: append-only, balance reconciliation, negative stock rejection, count correction, waste, receipt.
- Concurrency: two simultaneous stock movements cannot produce a wrong balance.
- Reports count paid orders only and exclude voided orders.
- Receipt payload matches the order snapshot.

Frontend checks:

- Money and date formatting.
- Status label mapping.
- One smoke test per critical screen for loading, empty, and error states.

Verification before delivery: run migrations and tests, build the frontend, click through the core flows in a browser, and record the results.

## 17. Rollout

- Seed two branches and the real menu, prices, ingredients, and opening stock before pilot.
- Suggested rollout: enable one branch for a few days, reconcile sales and stock daily, then enable the second branch. The user may choose to start both at once.
- Backup before pilot and verify a restore.
- Train cashiers and kitchen staff on one order and one closing count.

## 18. Decisions made in this document

These are proposals, not confirmed business policy. The owner must confirm or change them:

1. Three roles only: owner, cashier, kitchen.
2. Single payment per order, taken before kitchen preparation.
3. No cashier shifts or cash drawer reconciliation in this release.
4. No recipe deduction; stock changes are manual only.
5. One base unit per ingredient; no conversions.
6. Same prices across branches; availability is branch-specific.
7. Cashier cancels unpaid orders; owner voids paid orders with a reason.
8. Owner records receipts and adjustments; kitchen records waste and closing counts.
9. Printing transport is chosen after the printer model is confirmed.
10. Kitchen display uses polling, not WebSockets.
11. Light theme for POS and admin, fixed dark theme for kitchen display, no toggle.
12. UI copy is in Bahasa Indonesia.
13. The owner can also create orders, because the owner may cover a shift.
14. Dine-in orders capture a table number.
15. Cash payment may record the amount received and show change.
16. Orders cannot be edited after payment; void and recreate.
17. Closing counts may be partial; only entered ingredients produce corrections.

## 19. Open items to confirm before or during build

- Official yellow, orange, and red hex codes.
- Receipt printer brand and model.
- Final menu, sizes, crusts, toppings, add-ons, and prices.
- Ingredient list, base units, low-stock thresholds, and opening stock per branch.
- Whether any product is unavailable at one branch at launch.
- Staff names and branch assignments.
- Domain name and hosting ownership.
- Backup destination and retention owner.
