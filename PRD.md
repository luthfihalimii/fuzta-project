# Product Requirements Document (PRD)
# Pizza Fruzta — Digital Ordering & Multi-Branch Operations Platform

- **Document status:** Draft v1.0
- **Last updated:** 26 October 2026
- **Product owner:** Pizza Fruzta
- **Prepared for:** Product, design, and engineering
- **Platforms:** Public website, Flutter customer app, web-based POS/admin
- **Initial deployment:** 2 branches

---

## 1. Product Overview

Pizza Fruzta is a digital platform for a pizza business operating across two branches. It combines a public brand website, customer ordering, point of sale (POS), kitchen order handling, warehouse and inventory management, and owner reporting into one system.

The platform will use a shared backend and central database so customer orders, in-store sales, stock movements, and reports follow consistent business rules. Each employee will see only the features and branch data permitted by their role.

### 1.1 Product Vision

Make it easy for customers to discover and order Pizza Fruzta, while giving the owner reliable visibility and control over sales, stock, and daily operations across branches.

### 1.2 Product Principles

1. **One source of truth:** all sales channels use the same backend and catalog.
2. **Branch-aware by design:** transactions and stock are associated with the relevant branch or warehouse.
3. **Operationally simple:** interfaces should be usable by restaurant staff with minimal training.
4. **Secure by default:** server-side validation, role-based access, audit trails, and safe payment handling.
5. **Incremental delivery:** launch a useful MVP before introducing advanced features.
6. **Measurable operations:** sales, stock changes, waste, and order status should be traceable.

---

## 2. Problem Statement

Pizza Fruzta currently operates two branches. As the business grows, managing customer orders, in-store transactions, ingredient stock, inter-branch transfers, and performance reports through disconnected tools can cause:

- Inconsistent menu prices and promotions.
- Orders being missed or entered incorrectly.
- Limited visibility into branch-level sales.
- Stock discrepancies and difficulty tracing waste or adjustments.
- Manual work when transferring ingredients between locations.
- Difficulty understanding best-selling products and estimated ingredient costs.

The platform should reduce manual duplication and provide a consistent operational workflow without introducing unnecessary enterprise-level complexity.

---

## 3. Goals and Success Metrics

### 3.1 Goals

- Present a professional and recognizable Pizza Fruzta brand online.
- Allow customers to browse the menu and place orders through a website and Flutter app.
- Support in-store sales through a browser-based POS.
- Manage products, recipes, ingredients, purchasing, stock, and transfers.
- Provide consolidated and branch-level reports to the owner.
- Enforce role and branch permissions.
- Maintain reliable transaction and stock records.

### 3.2 Initial Success Metrics

Targets below are proposed and should be confirmed with the owner after observing current operations.

| Metric | Proposed initial target |
|---|---|
| Successful order creation | At least 99% of valid checkout attempts, excluding provider/network outages |
| Duplicate orders from repeated requests | 0 duplicate paid orders caused by retries |
| Sales reconciliation | Daily POS and online sales can be reconciled against payment records |
| Inventory traceability | 100% of stock changes recorded with a reason/source and timestamp |
| Branch reporting | Owner can view sales by branch and consolidated totals |
| Staff onboarding | Typical cashier can complete basic sale flow after brief training |
| Data recovery | Backups run automatically and restore procedure is tested before production |

Targets are provisional; the team should collect a baseline before using them as formal service-level commitments.

---

## 4. Users and Roles

### 4.1 Customer
- Browse menu, product options, prices, and promotions.
- Select a branch and fulfillment method.
- Place orders and view order status.
- View order history when signed in.
- Receive order updates and payment status.

### 4.2 Cashier
- Sign in to the assigned branch.
- Create in-store orders.
- Apply authorized discounts.
- Accept supported payment methods.
- Print or display receipts.
- Open and close a cashier shift.
- View permitted transactions for the assigned branch.

### 4.3 Kitchen Staff
- View incoming orders for the relevant branch.
- Accept and update preparation status.
- Mark orders ready for pickup or handoff.
- See order notes and item customizations.

### 4.4 Branch Manager
- View branch sales and order activity.
- Manage branch-level stock tasks and stock counts.
- Request or approve stock transfers according to permissions.
- Review branch reports and exceptions.

### 4.5 Warehouse / Inventory Staff
- Record goods received and supplier deliveries.
- Manage stock counts and adjustments.
- Prepare and dispatch stock transfers.
- Track ingredients, packaging, units, and stock movement history.

### 4.6 Owner / Administrator
- Access consolidated and branch-level dashboards.
- Manage branches, staff, roles, products, prices, recipes, and promotions.
- Review sales, estimated cost, waste, stock, and audit logs.
- Configure system-wide settings and integrations.

Access must be enforced on the server, not only by hiding UI elements.

---

## 5. Scope

### 5.1 MVP — Required for Initial Release

1. Public brand website and digital menu.
2. Customer ordering website.
3. Flutter customer app for Android; iOS readiness should be maintained if cross-platform launch is approved.
4. Shared catalog, pricing, and product customization.
5. Branch selection and pickup/delivery configuration.
6. POS for in-store orders.
7. Kitchen order queue and status updates.
8. Order and payment status management.
9. Branch, user, and role management.
10. Ingredient inventory, recipe/BOM, and stock movements.
11. Goods receiving, stock adjustments, and stock transfers.
12. Sales and inventory reports.
13. Audit trail and automatic backups.
14. Responsive interfaces and basic accessibility.

### 5.2 Later Phases

- Loyalty points, referral codes, and customer membership tiers.
- Advanced CRM and segmented campaigns.
- Delivery-driver app and live GPS tracking.
- Demand forecasting and automated purchasing suggestions.
- Accounting software integration.
- Multiple legal entities or franchise management.
- Offline-first POS with conflict-aware synchronization.
- Advanced analytics and cohort reporting.
- Native tablet-specific kitchen display features if operationally justified.

### 5.3 Out of Scope for MVP

- A public multi-merchant marketplace.
- Full ERP/accounting replacement.
- Automated machine control for ovens or kitchen equipment.
- Guaranteed delivery logistics without an agreed delivery partner.
- Complex microservices architecture.

---

## 6. Product Surfaces

### 6.1 Public Website
Purpose: brand discovery, menu browsing, and conversion to ordering.

Pages:
- Home
- Menu / product detail
- Promotions
- About Pizza Fruzta
- Branch locations and opening hours
- Contact / FAQs
- Order entry point
- Privacy policy and terms

Requirements:
- Mobile-first responsive design.
- Search-engine-friendly page content and metadata.
- Optimized image sizes and loading.
- Menu and branch details managed from the admin system.
- Clear calls to action to order online.

### 6.2 Customer Ordering Website
- Browse categories and product details.
- Select size, crust, toppings, add-ons, and other configured options.
- Add products to cart and edit quantities.
- Choose branch and fulfillment method.
- Enter customer details and order notes.
- Select an available payment method.
- Review a server-calculated order total before confirmation.
- View order number, payment state, and fulfillment status.
- Receive clear feedback when an item becomes unavailable or a price changes.

### 6.3 Flutter Customer App
Target: Android first; architecture should remain suitable for iOS if included in the launch plan.

Features:
- Onboarding and optional account registration.
- Menu browsing and product customization.
- Cart and checkout.
- Branch selection.
- Pickup or delivery where supported.
- Order status and order history.
- Push notifications for important order updates.
- Profile and saved contact information.
- Deep links from promotions, if applicable.

The app must not trust client-provided prices, discount amounts, payment state, or inventory availability. Laravel remains authoritative.

### 6.4 POS and Admin Web App
- Browser-based interface for desktops and tablets.
- Fast product search and category navigation.
- Cart editing, item notes, discounts, taxes/fees where applicable, and payment selection.
- Order creation for dine-in, takeaway, pickup, and delivery only where configured.
- Receipt printing support to be validated against the actual printer and browser environment.
- Shift opening/closing and cash reconciliation.
- Order cancellation/refund flow based on permissions and reason codes.
- Admin tools for catalog, branches, staff, inventory, and reporting.

### 6.5 Kitchen Order Display
- Display orders for the assigned branch.
- Sort by accepted time or configured priority.
- Show item quantities, options, and special instructions.
- Track statuses: queued, accepted, preparing, ready, completed.
- Allow authorized staff to mark an order unavailable or flag an issue.
- Keep a timestamped history of status transitions.

---

## 7. Functional Requirements

Priority labels:
- **P0:** Required for MVP launch.
- **P1:** Important, can follow shortly after initial launch.
- **P2:** Future enhancement.

### 7.1 Authentication and Authorization

- **P0** Staff can sign in securely.
- **P0** Users have roles and permissions.
- **P0** Staff access is limited to assigned branches unless explicitly authorized.
- **P0** Sensitive actions require authorization and are recorded in the audit log.
- **P1** Customers can sign in to view saved details and order history.
- **P1** Password reset and account recovery are available.

### 7.2 Branch Management

- **P0** Admin can create and update branch records.
- **P0** Each branch has name, address, contact, opening hours, and active status.
- **P0** Orders are associated with the branch responsible for fulfillment.
- **P0** Branch-specific availability and inventory are supported.
- **P1** Temporary closures and special operating hours can be configured.

### 7.3 Product Catalog and Pricing

- **P0** Admin can manage categories, products, descriptions, images, and availability.
- **P0** Products can have configurable options and add-ons.
- **P0** Prices and active promotions are calculated on the server.
- **P0** Products can be enabled or disabled by branch where required.
- **P0** Price and product details used by an order are stored as an order snapshot.
- **P1** Scheduled promotions and time-limited menus.
- **P1** Product-level allergen information, if supplied and verified by Pizza Fruzta.

### 7.4 Ordering

- **P0** A customer can create an order from the website or app.
- **P0** An order includes a unique human-readable order number.
- **P0** Checkout validates branch, availability, product options, prices, fees, and promotion rules.
- **P0** Repeated checkout requests are handled idempotently.
- **P0** Order lifecycle and payment lifecycle are tracked separately.
- **P0** Staff can view orders for their permitted branch.
- **P0** Cancellation is controlled by order state and permission.
- **P1** Customer order history and reorder.
- **P1** Scheduled pickup orders, if the business confirms this workflow.

### 7.5 Payments

- **P0** Support cash payment at the branch or on collection, if enabled.
- **P0** Record payment method, amount, status, reference, and timestamps.
- **P0** If a payment gateway is used, verify payment results through a trusted server-side callback/webhook.
- **P0** Webhook processing is idempotent and validates authenticity.
- **P0** Do not store card credentials or sensitive payment authentication data.
- **P1** Online payment/QRIS integration with a selected provider.
- **P1** Authorized refund recording and reconciliation.

Payment provider, fees, settlement schedule, and supported methods must be confirmed before implementation.

### 7.6 POS and Cashier Shifts

- **P0** Cashier can create and complete an in-store sale.
- **P0** Sales are assigned to the active branch and cashier.
- **P0** Cashier shift records opening cash, cash movements where applicable, expected closing cash, and counted closing cash.
- **P0** Voids, discounts, and refunds require appropriate permission and reason.
- **P0** Receipt or order confirmation can be printed or displayed.
- **P1** Cashier performance and shift discrepancy reports.

### 7.7 Kitchen Operations

- **P0** New accepted orders appear in the correct branch queue.
- **P0** Kitchen staff can update order preparation statuses.
- **P0** Status changes are timestamped and attributable to a user or system event.
- **P0** Customer-facing status updates do not expose internal staff information.
- **P1** Sound/visual alerts and configurable preparation-time indicators.

### 7.8 Inventory and Recipes

- **P0** Admin can define ingredients, packaging, stock units, and conversion rules.
- **P0** Products can be linked to recipes/BOMs, including ingredient quantities per menu item and option.
- **P0** Inventory is tracked by branch or warehouse location.
- **P0** Record receipts, consumption, waste, transfers, counts, and adjustments.
- **P0** Every stock movement includes quantity, unit, source/destination where applicable, timestamp, actor, and reason/reference.
- **P0** Stock balances are derived from or reconciled against a traceable stock movement ledger.
- **P0** Prevent negative stock by default, with any override restricted and audited.
- **P0** Order-driven ingredient consumption follows an explicitly configured business event, with reversals for cancellations/refunds where applicable.
- **P1** Low-stock alerts.
- **P1** Lot, expiry, and FEFO tracking if required by actual food handling procedures.
- **P1** Waste and variance reports.

**Important:** The owner must confirm when ingredients are deducted (e.g. order acceptance or preparation start), how cancellations are reversed, and how recipe variance/waste is recorded. These are business decisions, not assumptions to bury in code.

### 7.9 Warehouse, Purchasing, and Transfers

- **P0** Record suppliers and purchase receipts.
- **P0** Receive stock against a purchase record or documented manual receipt.
- **P0** Transfer stock between warehouse and branches through explicit statuses: draft, dispatched, received, cancelled.
- **P0** Dispatch reduces source available stock and records stock in transit; receipt adds stock to destination.
- **P0** Transfer quantity discrepancies require a recorded reason.
- **P1** Purchase orders and supplier price history.
- **P1** Approval thresholds for purchasing and stock adjustments.

### 7.10 Reporting

- **P0** Daily sales by branch and consolidated total.
- **P0** Sales breakdown by payment method and order channel.
- **P0** Order counts, average order value, and product sales quantities.
- **P0** Current stock by location and movement history.
- **P0** Cashier shift reconciliation.
- **P1** Estimated ingredient cost and gross margin based on maintained recipes and ingredient costs.
- **P1** Waste, stock variance, and low-stock reporting.
- **P1** Export permitted reports to CSV.

Reports must clearly distinguish sales, discounts, refunds, tax/fees, payment receipts, and estimated costs. Revenue is not the same as profit; profit reporting requires validated cost and expense inputs.

### 7.11 Notifications

- **P0** Show order confirmation and current status in the app/website.
- **P1** Push notification for accepted, ready, cancelled, or other configured important status changes.
- **P1** Operational alerts for low stock or failed integrations.
- Notifications must not be the only source of truth; the user can refresh and retrieve current status from the backend.

### 7.12 Audit and Administration

- **P0** Log sensitive actions such as price changes, refunds, discounts, stock adjustments, role changes, and transfer discrepancies.
- **P0** Audit entries include actor, action, target, timestamp, and relevant before/after values where safe.
- **P0** Do not log passwords, access tokens, payment secrets, or unnecessary personal data.
- **P0** Deactivate staff accounts without deleting historical transaction attribution.

---

## 8. Business Rules

1. Every order must have a unique ID and order number.
2. Each order has one fulfillment branch, unless a future workflow explicitly supports split fulfillment.
3. The server recalculates line prices, modifiers, discounts, fees, and totals at checkout.
4. Orders store a snapshot of the product name, options, unit price, discount, and quantity at the time of sale.
5. Order status and payment status are independent state machines.
6. A payment webhook may be received more than once; processing must be idempotent.
7. Stock changes must be traceable and should not be silently overwritten.
8. Stock transfers are not complete until destination receipt is recorded.
9. Refunds and cancellations must trigger the appropriate financial and inventory adjustments without erasing the original transaction.
10. Staff permissions and branch access are enforced server-side.
11. All persisted timestamps use a consistent timezone strategy; business-facing schedules and reports use the configured local timezone, initially Asia/Jakarta.
12. Financial amounts use fixed-precision decimal or integer minor units, never binary floating-point arithmetic.
13. Recipe consumption and stock deduction timing must be configurable or explicitly documented before production.
14. Reports should be reproducible from stored transactions and movement records.

---

## 9. Non-Functional Requirements

### 9.1 Security
- HTTPS in production.
- Server-side authorization for every protected action.
- Request validation and output encoding.
- Rate limiting for authentication, checkout, and sensitive endpoints.
- Secure password hashing and session/token lifecycle.
- Secrets supplied through environment/secret management, not committed to source control.
- Payment gateway secrets and webhook validation protected.
- Dependency updates, vulnerability review, and production debug mode disabled.
- Audit logging for sensitive operations.
- Data minimization and documented retention rules.

### 9.2 Reliability and Performance
- Initial target: common catalog and admin pages should feel responsive on ordinary mobile networks.
- Initial target: p95 API response under 500 ms for routine non-reporting requests under expected launch load, excluding external provider latency. Validate through load testing; this is a target, not a guarantee.
- Slow reports and notifications should use background jobs where appropriate.
- Use database transactions for order/payment/stock operations that must remain consistent.
- Monitor failed jobs, API errors, database health, and disk usage.

### 9.3 Availability and Recovery
- Automated database backups with off-server copies.
- Define and test restoration before launch.
- Proposed initial recovery objectives: RPO 24 hours or better and RTO 8 hours or better; improve if business operations require it.
- Document deployment rollback and incident response.
- POS offline operation is not included in MVP unless explicitly prioritized and designed.

### 9.4 Privacy
- Collect only customer information needed to fulfill orders and provide support.
- Publish a privacy notice and terms.
- Restrict staff access to customer data.
- Define data retention and deletion workflows consistent with applicable Indonesian law.
- Do not expose customer phone numbers or addresses unnecessarily to kitchen staff.

### 9.5 Accessibility and Usability
- Responsive design for mobile, tablet, and desktop.
- Clear touch targets and readable text for POS use.
- Form validation with actionable error messages.
- Keyboard navigation for critical web workflows where feasible.
- Loading, empty, success, and failure states for major user flows.

---

## 10. Technical Architecture

### 10.1 Recommended Stack

| Layer | Technology | Purpose |
|---|---|---|
| Backend | Laravel 13, subject to compatibility checks | Shared business logic and API |
| Public website / admin / POS | Vue 3 + TypeScript + Inertia.js | SEO-capable public pages and operational web interfaces |
| Customer app | Flutter + Dart | Android first; iOS if included in launch scope |
| Database | PostgreSQL | Orders, payments, branches, stock ledger, users, reporting |
| Queue/cache | Redis, optional initially | Background jobs and caching |
| Push notifications | Firebase Cloud Messaging | Mobile push delivery |
| Image/file storage | S3-compatible object storage | Product images and approved files |
| Web server | Nginx | HTTPS termination/reverse proxy and static asset delivery |
| Deployment | Linux VPS or managed hosting | Initial production environment |
| Monitoring | Error tracking, logs, uptime checks | Diagnose production problems |

Before implementation, confirm that the selected Laravel, Vue, Inertia, Flutter, and package versions are compatible and supported. Prefer stable releases and lock dependencies.

### 10.2 Architecture Pattern

Use a **modular monolith** initially. Keep modules separated by responsibility inside one deployable backend:
- Identity and access
- Branches
- Catalog and pricing
- Ordering and checkout
- Payments
- POS and shifts
- Kitchen
- Inventory and recipes
- Purchasing and warehouse
- Reporting
- Notifications
- Audit

Avoid microservices until there is a demonstrated scaling, team ownership, or deployment reason.

### 10.3 API Conventions

- Version APIs under `/api/v1`.
- Use consistent JSON responses and validation error formats.
- Authenticate customers and staff with an appropriate mechanism; use secure web sessions for the web app and a well-maintained token strategy for the mobile app.
- Apply idempotency to order creation and payment-related actions.
- Paginate lists and filter by branch, status, and date as permitted.
- Validate authorization on the server for every resource.
- Never expose internal database IDs as authorization controls.
- Publish API documentation for mobile/web integration.

Example endpoint groups:
- `GET /api/v1/menu`
- `GET /api/v1/branches`
- `POST /api/v1/orders`
- `GET /api/v1/orders/{order}`
- `POST /api/v1/orders/{order}/cancel`
- `POST /api/v1/payments/webhooks/{provider}`
- `GET /api/v1/branches/{branch}/orders`
- `POST /api/v1/inventory/transfers`
- `POST /api/v1/inventory/receipts`

Endpoints are illustrative; authorization and exact request/response contracts must be designed before implementation.

---

## 11. Data Model — Initial Entities

The following is a conceptual starting point, not a finalized migration specification.

### 11.1 Identity and Organization
- `users`
- `roles`
- `permissions`
- `role_permissions`
- `user_roles`
- `branches`
- `user_branch_access`
- `warehouses`

### 11.2 Catalog
- `categories`
- `products`
- `product_branch_availability`
- `product_variants`
- `modifier_groups`
- `modifiers`
- `product_modifier_groups`
- `promotions`
- `promotion_rules`

### 11.3 Orders and Payments
- `orders`
- `order_items`
- `order_item_modifiers`
- `order_status_events`
- `payments`
- `payment_events`
- `refunds`
- `cashier_shifts`
- `cash_movements`
- `receipts` (if required)

### 11.4 Inventory and Purchasing
- `ingredients`
- `units`
- `unit_conversions`
- `recipes`
- `recipe_items`
- `inventory_locations`
- `stock_movements`
- `stock_balances` (optional cached/derived balance, reconciled against ledger)
- `suppliers`
- `purchase_orders`
- `purchase_order_items`
- `goods_receipts`
- `goods_receipt_items`
- `stock_transfers`
- `stock_transfer_items`
- `stock_counts`
- `stock_count_items`
- `waste_records`

### 11.5 Operations
- `notifications`
- `audit_logs`
- `settings`
- `file_assets`

### 11.6 Data Integrity Notes
- Use foreign keys and appropriate unique constraints.
- Index branch, status, and timestamp fields used in common queries.
- Store order-item price and product snapshots.
- Represent units and conversions explicitly; do not mix grams, kilograms, pieces, and packs without conversion rules.
- Use append-only stock movement records for traceability.
- Define deletion rules carefully; financial and stock records should generally be retained and corrected through reversal/adjustment entries rather than destructive deletion.
- Store only payment provider references and safe metadata, not sensitive payment credentials.

---

## 12. UX and Design Direction

The brand should feel appetizing, friendly, modern, and easy to recognize. The exact colors, typography, logo, and visual language must be confirmed with Pizza Fruzta rather than assumed.

### Customer experience
- Product photography and pricing are easy to scan.
- Branch and fulfillment choices are clear.
- Customization is understandable and does not overwhelm the user.
- Cart totals and fees are transparent before payment.
- Order confirmation includes the order number and next step.

### POS experience
- Large touch-friendly controls.
- Fast access to popular items and categories.
- Clear distinction between unpaid, paid, preparing, ready, and completed orders.
- Minimize steps for repeatable cashier workflows.
- Confirmation for destructive or sensitive actions.

### Admin experience
- Branch filter visible on reports and stock screens.
- Search, filters, pagination, and useful empty states.
- Clear warnings for low stock, discrepancies, failed payments, and pending transfers.

---

## 13. Acceptance Criteria

The MVP is ready for controlled pilot use when all applicable criteria below pass.

### Ordering
- A customer can browse the menu and submit a valid order from web and Flutter.
- Server-calculated totals match the displayed checkout total.
- Invalid options, unavailable products, and unauthorized discounts are rejected.
- Repeated requests do not create duplicate orders or duplicate payment processing.
- Customer can retrieve current order status.

### POS and Kitchen
- An authorized cashier can create a sale for the correct branch.
- Payment and order states remain accurate after refresh.
- Kitchen staff can update statuses and the history is recorded.
- Cashier shift totals can be reconciled against recorded transactions.

### Inventory
- A configured recipe consumes the expected ingredients at the agreed business event.
- Stock receipt, adjustment, transfer, and waste each produce traceable movements.
- A transfer is not treated as received until destination confirmation.
- Concurrent sales cannot silently produce inconsistent stock balances.
- Cancellation/refund behavior follows the approved inventory policy.

### Permissions and Security
- A staff user cannot read or change data for an unauthorized branch by manipulating requests.
- Sensitive actions require the right permission and create audit records.
- Production secrets and debug information are not exposed.
- Payment callbacks are authenticated and idempotent.

### Operations
- Automated backups run successfully.
- A restore test has been completed and documented.
- Monitoring alerts on critical application and background-job failures.
- Staff have completed a test workflow at both branches before public launch.

---

## 14. Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Unclear current restaurant workflow | Rework and poor adoption | Interview owner and staff; map current sales, stock, and transfer processes |
| Printer or cash drawer incompatibility | POS delays | Test actual hardware and browser/OS before committing to implementation |
| Unstable internet | Orders or POS disruption | Define manual fallback; consider offline-first POS as a separate phase |
| Incorrect recipe quantities | Misleading stock and cost reports | Validate recipes with kitchen staff and run reconciliation |
| Payment webhook retries or delays | Incorrect payment/order state | Verify signatures, make processing idempotent, and support reconciliation |
| Unauthorized staff access | Data exposure or fraud | Server-side RBAC, branch scope checks, audit logs |
| Stock race conditions | Overselling or negative stock | Database transactions, suitable locking, and concurrency tests |
| Unreliable backups | Extended outage/data loss | Off-server backups and regular restore tests |
| Scope creep | Delayed launch | Prioritize MVP and maintain a separate post-launch backlog |

---

## 15. Dependencies and Open Questions

These items require confirmation from Pizza Fruzta before the relevant feature is finalized.

### Business and Operations
- Exact menu, sizes, toppings, add-ons, and product pricing.
- Current opening hours and branch addresses.
- Whether there is a central warehouse, branch-level storage, or both.
- Who can approve purchases, discounts, refunds, and stock adjustments.
- Ingredient recipe quantities and stock deduction timing.
- Whether dine-in, takeaway, pickup, and delivery are all supported.
- Delivery radius, fee calculation, and delivery responsibility.
- Expected order volume and peak periods.

### Hardware
- POS devices and operating systems used at each branch.
- Receipt printer model and connection method.
- Kitchen printer/display requirements.
- Barcode scanner, cash drawer, or other hardware, if any.

### Payments
- Whether MVP starts with cash/manual payment or online payment.
- Preferred payment gateway and supported QRIS/payment methods.
- Refund and settlement reconciliation process.

### Product Launch
- Android only or Android + iOS on day one.
- Whether customers can order without creating an account.
- Whether offline POS is mandatory for the initial launch.
- Brand logo, colors, typography, and approved product photography.
- Hosting budget, maintenance owner, and support expectations.

---

## 16. Delivery Plan

A proposed solo-developer sequence; timeline depends on confirmed scope, hardware, and integration requirements.

| Phase | Deliverables | Indicative duration |
|---|---|---|
| 0. Discovery | Interview owner/staff, map workflows, validate recipes and hardware | 1–2 weeks |
| 1. Foundation | Repo, environments, auth, roles, branches, catalog, design system | 1–2 weeks |
| 2. Ordering | Website ordering, Flutter app basics, checkout, order state | 2–3 weeks |
| 3. POS/Kitchen | Sales, shift handling, receipts, kitchen queue | 2–3 weeks |
| 4. Inventory | Recipes, stock ledger, receipts, transfers, counts | 2–4 weeks |
| 5. Reports/Hardening | Reports, security review, backups, tests, pilot training | 1–2 weeks |

Indicative total: **9–16 weeks** for a focused MVP with one developer, subject to discovery findings and available working time. A robust offline POS, complex delivery, or extensive payment integration can increase the schedule.

### Release Strategy
1. Development environment with seeded sample data.
2. Internal testing using representative orders and stock movements.
3. Pilot in one branch with staff training.
4. Pilot in the second branch and reconcile reports.
5. Limited customer launch.
6. Monitor errors, stock variance, support requests, and order completion before expanding scope.

---

## 17. Definition of Done

A feature is complete when:
- Requirements and acceptance criteria are met.
- Authorization and validation are implemented server-side.
- Automated tests cover core success and failure paths.
- Relevant audit events and error handling are present.
- UI includes loading, empty, success, and error states.
- API and setup documentation are updated.
- Logs do not expose secrets or unnecessary customer data.
- Changes have been reviewed and deployed to a test environment.
- The owner/staff workflow has been validated where it affects operations.

---

## 18. Final Recommendation

Build Pizza Fruzta as a **modular monolith** with:

- **Laravel** for the shared backend and REST API.
- **Vue 3 + TypeScript + Inertia.js** for the public website, POS, and admin dashboard.
- **Flutter** for the customer ordering app.
- **PostgreSQL** for transactional data.
- **Redis** when queue/cache needs justify it.
- **Firebase Cloud Messaging** for push notifications.

Start with the two-branch workflow and a tightly scoped MVP. Confirm the real operating procedures, recipe data, hardware, payment methods, and delivery rules before locking the database and stock-consumption behavior. Do not introduce microservices or full offline synchronization until there is a clear business requirement.

The platform's most important quality is not the number of features—it is that orders, payments, and stock records remain correct and explainable across both branches.
