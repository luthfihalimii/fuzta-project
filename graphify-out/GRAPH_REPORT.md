# Graph Report - pizza-fruzta (2026-10-31)

## Corpus Check

- 402 files · ~137,278 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary

- 2764 nodes · 6064 edges · 169 communities (81 shown, 88 thin omitted)
- Extraction: 98% EXTRACTED · 2% INFERRED · 0% AMBIGUOUS · INFERRED: 107 edges (avg confidence: 0.86)
- Token cost: 0 input · 0 output

## Community Hubs (Navigation)

- Core Domain Models
- Vue UI Base Components
- Identity, User & Branch Management
- Inventory Actions & Stock Ledger
- Auth Middleware & Authorization
- Catalog Request Forms
- POS Cart UI
- Laravel Config & Data Factories
- Pricing & Catalog Data
- User Access & Order Scopes
- Order & Reporting Queries
- Branch Presenter & Stock Movement API
- API Client & Realtime
- Recipe Request Forms
- Business Rules & Audit Concepts
- Shift Dialog UI
- Order TypeScript Types
- Shift Lifecycle Jobs
- Shift Actions
- Money & Receipt UI
- Order Management Requests
- Order Status & Consumption Actions
- CI Test Workflow
- Catalog Controllers & Models
- Core Controllers
- Stock Receive UI
- Transfers UI
- Audit & Transfer Actions
- UI Button Component
- Stock Transfer Tests
- Login & Ingredient Requests
- Core Migrations
- Order Pricing & Checkout Core
- App Boot & Request Forms
- Recipes UI
- Auto Close Shift Job
- Units Admin UI
- Kitchen Orders UI
- Transfer Form Requests
- Order Pricing & Checkout Core
- Purchase Actions
- TypeScript Config
- Consumption & Status Transition Actions
- Inventory Form Requests
- Order Pricing & Checkout Core
- Product Edit UI
- Order Creation Tests
- Purchase HTTP Tests
- Modifier Groups UI
- Order Payment Actions
- Stock Movement UI
- Stock Report UI
- Staff Admin UI
- Unit Admin Tests
- Console Commands
- Unit & Conversion Admin
- Purchase Schema Tests
- Build Toolchain Deps
- Catalog Tests
- Stock Ledger Tests
- Composer Scripts
- Payment Sheet UI
- Auth TypeScript Types
- Payment Tests
- Stock Count Requests
- Staff Update & App Services
- Catalog Migrations
- Order Event Migrations
- Kitchen Access Tests
- Order Status & Consumption Actions
- Staff Access Tests
- Order Pricing & Checkout Core
- Dependabot Config
- Test Base Setup
- Staff Controller
- Category Form Requests
- Composer Manifest
- Web Frontend Deps
- Order Shift Gate Tests
- Receipt Access Tests
- Dev Composer Deps
- Vite Plugin Config
- Vite Build Scripts
- Shift Report UI
- Close Shift Tests
- Open Shift Tests
- Recipe Admin Tests
- Void & Cancel Order Tests
- Dashboard Controller
- Web Dev Deps
- Purchase Page Access Tests
- Security Tests
- User Model & Factory
- Auth Redirect Tests
- Report Access Tests
- Stock Movement Ledger Actions
- Seeder Support Requests
- Composer Plugins
- Runtime Composer Deps
- Transfer Index Migrations
- Inventory Reconcile Tests
- Shift Totals Tests
- Stock Count Tests
- Order Status & Consumption Actions
- Transfer Status Enum
- Users Migration
- CSS Utilities
- Auto Close Shift Tests
- Order Status & Consumption Actions
- Stock Movement Ledger Actions
- Shift Reconcile Tests
- Inventory Form Requests
- Composer Autoload Map
- Cache Migration
- User Role Migrations
- Cash Float Migration
- Shift Index Migration
- Realtime Pusher Setup
- Branches Migration
- Audit Logs Migration
- Categories Migration
- Product Variants Migration
- Modifier Groups Migration
- Modifiers Migration
- Product Modifier Groups Migration
- Order Sequences Migration
- Orders Migration
- Order Items Migration
- Order Item Modifiers Migration
- Order Status Events Migration
- Payments Migration
- Units Migration
- Ingredients Migration
- Stock Movements Migration
- Stock Counts Migration
- Recipes Migration
- Cashier Shifts Migration
- Recipe Items Migration
- Cash Movements Migration
- Unit Conversions Migration
- Stock Transfers Migration
- Suppliers Migration
- Purchases Migration
- Purchase Items Migration
- Inventory Form Requests
- Composer Extra Config
- Workspace Config
- Backup Script
- Modular Monolith Architecture
- iOS Touch Icon
- Favicon Brand Mark
- Robots Exclude Rule

## God Nodes (most connected - your core abstractions)

1. `User` - 152 edges
2. `TestCase` - 105 edges
3. `Order` - 99 edges
4. `Branch` - 94 edges
5. `CashierShift` - 86 edges
6. `WriteAuditLog` - 83 edges
7. `Ingredient` - 52 edges
8. `Controller` - 46 edges
9. `Product` - 46 edges
10. `OrderStatus` - 42 edges

## Surprising Connections (you probably didn't know these)

- `{closure#7}()` --references--> `StockMovement` [EXTRACTED]
  tests/Feature/PurchaseActionTest.php → app/Modules/Inventory/Models/StockMovement.php
- `{closure#8}()` --references--> `StockMovement` [EXTRACTED]
  tests/Feature/PurchaseActionTest.php → app/Modules/Inventory/Models/StockMovement.php
- `{closure#1}()` --references--> `CashierShift` [EXTRACTED]
  database/factories/CashierShiftFactory.php → app/Modules/Shifts/Models/CashierShift.php
- `{closure#4}()` --references--> `CashierShift` [EXTRACTED]
  database/factories/CashierShiftFactory.php → app/Modules/Shifts/Models/CashierShift.php
- `Payment (entity; methods cash, qris_static)` --references--> `Money as integer minor units (IDR), never floats` [INFERRED]
  docs/superpowers/specs/2026-10-26-in-branch-operations-design.md → AGENTS.md

## Import Cycles

- None detected.

## Hyperedges (group relationships)

- **Order creation & checkout flow** — docs_superpowers_specs_2026_10_26_in_branch_operations_design_order_creation_flow, docs_superpowers_specs_2026_10_26_in_branch_operations_design_order, docs_superpowers_specs_2026_10_26_in_branch_operations_design_payment, docs_superpowers_specs_2026_10_26_in_branch_operations_design_order_status_machine, docs_superpowers_specs_2026_10_26_in_branch_operations_design_payment_status_machine, agents_idempotency, docs_superpowers_specs_2026_10_28_shift_kasir_design_shift_gate [INFERRED 0.85]
- **Open-shift gate over cash flows** — docs_superpowers_specs_2026_10_28_shift_kasir_design_shift_gate, docs_superpowers_specs_2026_10_28_shift_kasir_design_cashier_shift, docs_superpowers_specs_2026_10_28_shift_kasir_design_cash_movement, docs_superpowers_specs_2026_10_26_in_branch_operations_design_order, docs_superpowers_specs_2026_10_26_in_branch_operations_design_payment [INFERRED 0.85]
- **Append-only stock ledger invariant** — agents_stock_ledger, agents_inventory_reconcile, docs_superpowers_specs_2026_10_28_recipe_inventory_design_consume_order_ingredients, docs_superpowers_specs_2026_10_28_inter_branch_transfer_design_stock_transfer, docs_superpowers_specs_2026_10_30_purchasing_supplier_design_purchase, agents_negative_stock_block [INFERRED 0.85]

## Communities (169 total, 88 thin omitted)

### Community 0 - "Core Domain Models"

Cohesion: 0.05
Nodes (13): ProductVariant, {closure#1}(), {closure#1}(), {closure#2}(), RecipeItem, StockCount, StockCountItem, StockTransferItem (+5 more)

### Community 1 - "Vue UI Base Components"

Cohesion: 0.03
Nodes (54): @inertiajs/vue3, Tone, tones, model, nav, page, user, AuditRow (+46 more)

### Community 2 - "Identity, User & Branch Management"

Cohesion: 0.05
Nodes (14): User, Branch, {closure#1}(), AuditLogTest, AuditViewTest, CashMovementTest, ExampleTest, HealthTest (+6 more)

### Community 3 - "Inventory Actions & Stock Ledger"

Cohesion: 0.07
Nodes (29): {closure#1}(), {closure#1}(), {closure#1}(), {closure#1}(), {closure#1}(), RecordStockMovement, StockMovementType, Adjustment (+21 more)

### Community 4 - "Auth Middleware & Authorization"

Cohesion: 0.05
Nodes (19): EnsureRole, EnsureUserIsActive, HandleInertiaRequests, SecurityHeaders, StorePurchaseRequest, RecordPurchase, RecordPurchaseData, {closure#1}() (+11 more)

### Community 5 - "Catalog Request Forms"

Cohesion: 0.05
Nodes (22): {closure#1}(), StoreModifierGroupRequest, StoreProductRequest, {closure#1}(), UpdateModifierGroupRequest, UpdateProductRequest, {closure#1}(), {closure#2}() (+14 more)

### Community 6 - "POS Cart UI"

Cohesion: 0.04
Nodes (45): addDraftToCart(), branchSelect, cart, cartCount, CartLine, cartOpen, cartTotal, channel (+37 more)

### Community 7 - "Laravel Config & Data Factories"

Cohesion: 0.05
Nodes (17): BranchFactory, CashMovementFactory, CategoryFactory, IngredientFactory, ModifierFactory, ModifierGroupFactory, OrderItemFactory, ProductFactory (+9 more)

### Community 8 - "Pricing & Catalog Data"

Cohesion: 0.06
Nodes (32): Modifier, Product, {closure#2}(), {closure#3}(), OrderItem, OrderItemModifier, {closure#1}(), {closure#2}() (+24 more)

### Community 9 - "User Access & Order Scopes"

Cohesion: 0.06
Nodes (14): {closure#1}(), SubmitStockCount, CashierShiftPolicy, BranchPolicy, OrderPolicy, UserPolicy, AppServiceProvider, {closure#1}() (+6 more)

### Community 10 - "Order & Reporting Queries"

Cohesion: 0.06
Nodes (13): {closure#1}(), OpenShift, ResolveShiftWindow, ShiftWindowData, {closure#10}(), {closure#4}(), {closure#5}(), {closure#6}() (+5 more)

### Community 11 - "Branch Presenter & Stock Movement API"

Cohesion: 0.11
Nodes (13): StoreStockMovementRequest, BranchPresenter, {closure#1}(), {closure#4}(), {closure#5}(), StockMovementController, ResolvePosBranch, {closure#4}() (+5 more)

### Community 12 - "API Client & Realtime"

Cohesion: 0.06
Nodes (39): realtimeEcho(), csrfToken(), getJson(), HttpError, patchJson(), postJson(), requestJson(), isLate() (+31 more)

### Community 13 - "Recipe Request Forms"

Cohesion: 0.07
Nodes (15): StoreRecipeRequest, UpdateRecipeRequest, {closure#3}(), ResolveOrderRequirements, {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}() (+7 more)

### Community 14 - "Business Rules & Audit Concepts"

Cohesion: 0.06
Nodes (38): AGENTS.md — Agent instructions & non-negotiable business rules, inventory:reconcile (scan saldo vs ledger, --fix to rewrite + audit), Append-only stock movement ledger (stock_movements; balances reconcilable), DESIGN.md — Design system & UX guidelines, Backup & restore operations (nightly pg_dump, 14-day retention, optional S3), Deployment operations (Ubuntu 24.04 VPS, Nginx + PHP-FPM 8.3, queue worker, scheduler), Pilot acceptance checklist (run 2026-10-27), Receipt printing operations (browser dialog or RawBT ESC/POS) (+30 more)

### Community 15 - "Shift Dialog UI"

Cohesion: 0.07
Nodes (33): counted, deposit, emit, form, props, shiftError, submit(), variance (+25 more)

### Community 16 - "Order TypeScript Types"

Cohesion: 0.05
Nodes (34): BranchOption, BranchSummary, OrderItem, OrderItemModifier, OrderPayment, OrderRow, PosOrder, branchSelect (+26 more)

### Community 17 - "Shift Lifecycle Jobs"

Cohesion: 0.07
Nodes (11): {closure#6}(), {closure#7}(), {closure#8}(), {closure#9}(), BuildShiftReminder, {closure#1}(), CashierShift, {closure#3}() (+3 more)

### Community 18 - "Shift Actions"

Cohesion: 0.08
Nodes (15): CalculateShiftTotals, CloseShift, ReconcileShift, {closure#1}(), RecordCashMovement, ResolveShiftForClose, CashMovementDirection, In (+7 more)

### Community 19 - "Money & Receipt UI"

Cohesion: 0.11
Nodes (32): vitest@^5.0.5, vitest, formatted, props, emit, rowTotal(), formatRupiah(), formatSignedRupiah() (+24 more)

### Community 20 - "Order Management Requests"

Cohesion: 0.09
Nodes (10): OrderReasonRequest, UpdateOrderStatusRequest, {closure#4}(), KitchenController, Completed, {closure#3}(), OrderController, PosController (+2 more)

### Community 21 - "Order Status & Consumption Actions"

Cohesion: 0.13
Nodes (15): {closure#1}(), {closure#2}(), OrderStatus, Accepted, Cancelled, Preparing, Queued, Cash (+7 more)

### Community 22 - "CI Test Workflow"

Cohesion: 0.05
Nodes (37): env, runs-on, services, steps, DB_CONNECTION, DB_DATABASE, DB_HOST, DB_PASSWORD (+29 more)

### Community 23 - "Catalog Controllers & Models"

Cohesion: 0.07
Nodes (14): {closure#1}(), {closure#5}(), Category, {closure#1}(), ConvertUnit, UnitConversion, {closure#10}(), Supplier (+6 more)

### Community 24 - "Core Controllers"

Cohesion: 0.07
Nodes (10): DashboardController, LoginController, Controller, AuditLogController, {closure#1}(), {closure#2}(), IngredientController, {closure#1}() (+2 more)

### Community 25 - "Stock Receive UI"

Cohesion: 0.07
Nodes (28): addRow(), branchId, branchOptions, canSubmit, emptyRow(), errors, filledRows, FormRow (+20 more)

### Community 26 - "Transfers UI"

Cohesion: 0.07
Nodes (25): activeTransferId, branchOptions, BranchRow, cancelFormFor(), cancelForms, createCancelForm(), createDispatchForm(), createReceiveForm() (+17 more)

### Community 27 - "Audit & Transfer Actions"

Cohesion: 0.09
Nodes (9): WriteAuditLog, CancelStockTransfer, CreateStockTransfer, DispatchStockTransfer, ReceiveStockTransfer, {closure#3}(), UnitConversionController, CancelOrder (+1 more)

### Community 28 - "UI Button Component"

Cohesion: 0.08
Nodes (21): vue, classes, props, Variant, form, props, shiftError, form (+13 more)

### Community 30 - "Login & Ingredient Requests"

Cohesion: 0.08
Nodes (6): LoginRequest, StoreIngredientRequest, StoreOrderRequest, StoreUnitConversionRequest, StoreUnitRequest, UpdateCategoryRequest

### Community 31 - "Core Migrations"

Cohesion: 0.11
Nodes (15): {closure#1}(), {closure#2}(), {closure#3}(), {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#1}() (+7 more)

### Community 32 - "Order Pricing & Checkout Core"

Cohesion: 0.17
Nodes (9): OrderCreated, OrderPaymentChanged, {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#6}(), {closure#7}() (+1 more)

### Community 33 - "App Boot & Request Forms"

Cohesion: 0.16
Nodes (6): {closure#2}(), Role, Cashier, Kitchen, Owner, DatabaseSeeder

### Community 34 - "Recipes UI"

Cohesion: 0.08
Nodes (18): cancelEdit(), deleteForm, deletingRecipe, editingId, form, ingredientOptions, IngredientRow, ItemForm (+10 more)

### Community 35 - "Auto Close Shift Job"

Cohesion: 0.11
Nodes (8): {closure#1}(), {closure#1}(), ShiftCloseType, Auto, Manual, CashierShiftFactory, {closure#1}(), {closure#4}()

### Community 36 - "Units Admin UI"

Cohesion: 0.09
Nodes (17): emit, cancelEditUnit(), conversionExample, conversionForm, ConversionRow, deleteConversionForm, deleteUnitError, deleteUnitForm (+9 more)

### Community 37 - "Kitchen Orders UI"

Cohesion: 0.09
Nodes (16): branchSelect, connectRealtime(), onRealtime(), page, payingOrder, paymentOpen, processing, props (+8 more)

### Community 38 - "Transfer Form Requests"

Cohesion: 0.09
Nodes (5): CancelStockTransferRequest, DispatchStockTransferRequest, ReceiveStockTransferRequest, StoreStockTransferRequest, StockTransferController

### Community 39 - "Order Pricing & Checkout Core"

Cohesion: 0.22
Nodes (4): {closure#1}(), OrderStatusChanged, BroadcastOrderEventsTest, {closure#5}()

### Community 40 - "Purchase Actions"

Cohesion: 0.10
Nodes (4): {closure#1}(), {closure#1}(), Purchase, PurchaseSchemaTest

### Community 41 - "TypeScript Config"

Cohesion: 0.10
Nodes (20): compilerOptions, allowJs, esModuleInterop, forceConsistentCasingInFileNames, isolatedModules, jsx, jsxImportSource, lib (+12 more)

### Community 42 - "Consumption & Status Transition Actions"

Cohesion: 0.13
Nodes (8): {closure#1}(), ConsumeOrderIngredients, {closure#1}(), TransitionOrderStatus, {closure#1}(), Order, {closure#3}(), {closure#5}()

### Community 43 - "Inventory Form Requests"

Cohesion: 0.11
Nodes (4): StorePaymentRequest, UpdateSupplierRequest, QrisStatic, RecordCashMovementRequest

### Community 44 - "Order Pricing & Checkout Core"

Cohesion: 0.16
Nodes (7): PriceOrderItems, CreateOrder, GenerateOrderNumber, CreateOrderData, OrderChannel, DineIn, Takeaway

### Community 45 - "Product Edit UI"

Cohesion: 0.11
Nodes (13): availabilityByBranch, BranchAvailability, BranchOption, CategoryOption, categoryOptions, errors, form, initialBranchAvailability (+5 more)

### Community 48 - "Modifier Groups UI"

Cohesion: 0.12
Nodes (10): editingId, errors, form, formTitle, GroupRow, isEditing, ModifierDraft, ModifierRow (+2 more)

### Community 49 - "Order Payment Actions"

Cohesion: 0.15
Nodes (7): {closure#1}(), {closure#2}(), {closure#1}(), MarkOrderPaid, PaymentMethod, Payment, ResolveOpenShift

### Community 50 - "Stock Movement UI"

Cohesion: 0.12
Nodes (10): model, SelectOption, branchSelect, form, IngredientOption, ingredientOptions, MovementRow, page (+2 more)

### Community 51 - "Stock Report UI"

Cohesion: 0.13
Nodes (12): BalanceRow, branch, BranchOption, dateInJakarta(), filteredMovements, jakartaDateFormatter, movementFrom, MovementRow (+4 more)

### Community 52 - "Staff Admin UI"

Cohesion: 0.12
Nodes (11): BranchOption, branchOptions, editingId, form, formTitle, isEditing, page, props (+3 more)

### Community 54 - "Console Commands"

Cohesion: 0.15
Nodes (3): AutoCloseStaleShifts, CreateOwner, InventoryReconcile

### Community 55 - "Unit & Conversion Admin"

Cohesion: 0.14
Nodes (3): {closure#1}(), {closure#7}(), {closure#8}()

### Community 56 - "Purchase Schema Tests"

Cohesion: 0.14
Nodes (3): {closure#1}(), AuditLog, ShiftSchemaTest

### Community 57 - "Build Toolchain Deps"

Cohesion: 0.17
Nodes (14): optionalDependencies, @laravel/multiplex@^0.4.1, lightningcss-linux-x64-gnu@^1.29.1, @rollup/rollup-linux-x64-gnu@4.9.5, @tailwindcss/oxide-linux-x64-gnu@^4.0.1, overrides, vite, private (+6 more)

### Community 60 - "Composer Scripts"

Cohesion: 0.14
Nodes (14): scripts, analyse, ci:check, dev, lint, lint:check, post-autoload-dump, post-create-project-cmd (+6 more)

### Community 61 - "Payment Sheet UI"

Cohesion: 0.16
Nodes (12): cashAmount, cashReceived, change, close(), emit, errorMessage, method, pay() (+4 more)

### Community 62 - "Auth TypeScript Types"

Cohesion: 0.16
Nodes (11): Auth, Role, User, ComponentCustomProperties, GlobalDirectives, ImportMeta, ImportMetaEnv, InertiaConfig (+3 more)

### Community 64 - "Stock Count Requests"

Cohesion: 0.23
Nodes (3): SubmitStockCountRequest, {closure#4}(), StockCountController

### Community 65 - "Staff Update & App Services"

Cohesion: 0.17
Nodes (4): UpdateStaffRequest, {closure#7}(), {closure#1}(), CreateOwnerCommandTest

### Community 66 - "Catalog Migrations"

Cohesion: 0.15
Nodes (3): {closure#1}(), {closure#1}(), {closure#1}()

### Community 67 - "Order Event Migrations"

Cohesion: 0.15
Nodes (3): {closure#1}(), {closure#1}(), {closure#1}()

### Community 71 - "Order Pricing & Checkout Core"

Cohesion: 0.21
Nodes (7): ReverseOrderConsumption, {closure#1}(), VoidOrder, Ready, PaymentEventType, Paid, Voided

### Community 72 - "Dependabot Config"

Cohesion: 0.17
Nodes (11): default-days, patterns, github-actions, interval, updates, cooldown, directory, groups (+3 more)

### Community 74 - "Staff Controller"

Cohesion: 0.20
Nodes (4): {closure#3}(), {closure#4}(), StaffController, StoreStaffRequest

### Community 75 - "Category Form Requests"

Cohesion: 0.24
Nodes (4): StoreCategoryRequest, CategoryController, {closure#2}(), {closure#3}()

### Community 76 - "Composer Manifest"

Cohesion: 0.18
Nodes (10): autoload-dev, psr-4, description, keywords, license, minimum-stability, name, Tests\\ (+2 more)

### Community 77 - "Web Frontend Deps"

Cohesion: 0.18
Nodes (11): dependencies, concurrently@^10.0.3, @inertiajs/vue3@^3.0.0, tailwindcss@^4.1.1, typescript@^5.2.2, vite@npm:@voidzero-dev/vite-plus-core@1.4.0, vue@^3.5.13, concurrently (+3 more)

### Community 80 - "Dev Composer Deps"

Cohesion: 0.20
Nodes (10): require-dev, fakerphp/faker, larastan/larastan, laravel/pail, laravel/pao, laravel/pint, laravel/sail, mockery/mockery (+2 more)

### Community 81 - "Vite Plugin Config"

Cohesion: 0.20
Nodes (8): @inertiajs/vite@^3.0.0, laravel-vite-plugin@^3.1, @tailwindcss/vite@^4.1.11, @vitejs/plugin-vue@^6.0.0, @inertiajs/vite, laravel-vite-plugin, @tailwindcss/vite, @vitejs/plugin-vue

### Community 82 - "Vite Build Scripts"

Cohesion: 0.20
Nodes (10): scripts, build, build:ssr, check, check:fix, dev, lint, test:js (+2 more)

### Community 83 - "Shift Report UI"

Cohesion: 0.20
Nodes (6): Row, filter, Filters, PaginatedShifts, props, ShiftRow

### Community 88 - "Dashboard Controller"

Cohesion: 0.28
Nodes (4): BranchController, {closure#1}(), {closure#2}(), {closure#3}()

### Community 89 - "Web Dev Deps"

Cohesion: 0.22
Nodes (9): devDependencies, @laravel/vite-plugin-wayfinder@^0.1.3, @types/node@^22.13.5, vite-plus@1.4.0, vue-tsc@^3.3.13, @laravel/vite-plugin-wayfinder, @types/node, vite-plus (+1 more)

### Community 98 - "Composer Plugins"

Cohesion: 0.29
Nodes (7): pestphp/pest-plugin, php-http/discovery, config, allow-plugins, optimize-autoloader, preferred-install, sort-packages

### Community 99 - "Runtime Composer Deps"

Cohesion: 0.29
Nodes (7): require, inertiajs/inertia-laravel, laravel/framework, laravel/reverb, laravel/tinker, laravel/wayfinder, php

### Community 101 - "Transfer Index Migrations"

Cohesion: 0.29
Nodes (4): {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}()

### Community 107 - "Users Migration"

Cohesion: 0.33
Nodes (3): {closure#1}(), {closure#2}(), {closure#3}()

### Community 108 - "CSS Utilities"

Cohesion: 0.33
Nodes (4): clsx@^2.1.1, tailwind-merge@^3.2.0, clsx, tailwind-merge

### Community 114 - "Composer Autoload Map"

Cohesion: 0.40
Nodes (5): autoload, psr-4, App\\, Database\\Factories\\, Database\\Seeders\\

### Community 120 - "Realtime Pusher Setup"

Cohesion: 0.40
Nodes (4): laravel-echo@^2.6.3, pusher-js@^8.6.0, laravel-echo, pusher-js

### Community 149 - "Composer Extra Config"

Cohesion: 0.67
Nodes (3): extra, laravel, dont-discover

## Knowledge Gaps

- **17 isolated node(s):** `Modular monolith (12 domain modules, no microservices)`, `inventory:reconcile (scan saldo vs ledger, --fix to rewrite + audit)`, `Backup & restore operations (nightly pg_dump, 14-day retention, optional S3)`, `Deployment operations (Ubuntu 24.04 VPS, Nginx + PHP-FPM 8.3, queue worker, scheduler)`, `Receipt printing operations (browser dialog or RawBT ESC/POS)` (+12 more)
  These have ≤1 connection - possible missing edges or undocumented components. (Counts symbols only; 1202 node(s) total have ≤1 connection when file, concept and rationale nodes are included.)
- **88 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions

_Questions this graph is uniquely positioned to answer:_

- **Why does `User` connect `Identity, User & Branch Management` to `Core Domain Models`, `Inventory Actions & Stock Ledger`, `Auth Middleware & Authorization`, `Laravel Config & Data Factories`, `Pricing & Catalog Data`, `User Access & Order Scopes`, `Order & Reporting Queries`, `Shift Actions`, `Order Status & Consumption Actions`, `Audit & Transfer Actions`, `Order Pricing & Checkout Core`, `App Boot & Request Forms`, `Auto Close Shift Job`, `Order Pricing & Checkout Core`, `Purchase Actions`, `Consumption & Status Transition Actions`, `Order Pricing & Checkout Core`, `Order Creation Tests`, `Purchase HTTP Tests`, `Order Payment Actions`, `Purchase Schema Tests`, `Payment Tests`, `Staff Update & App Services`, `Order Status & Consumption Actions`, `Order Pricing & Checkout Core`, `Test Base Setup`, `Staff Controller`, `User Factory`, `Order Status & Consumption Actions`?**
  _High betweenness centrality (0.071) - this node is a cross-community bridge._
- **What connects `Modular monolith (12 domain modules, no microservices)`, `inventory:reconcile (scan saldo vs ledger, --fix to rewrite + audit)`, `Backup & restore operations (nightly pg_dump, 14-day retention, optional S3)` to the rest of the system?**
  _17 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `Core Domain Models` be split into smaller, more focused modules?**
  _Cohesion score 0.04598490048043926 - nodes in this community are weakly interconnected._
- **Why does `TestCase` connect `Identity, User & Branch Management` to `Inventory Actions & Stock Ledger`, `Auth Middleware & Authorization`, `Pricing & Catalog Data`, `Order & Reporting Queries`, `Recipe Request Forms`, `Shift Lifecycle Jobs`, `Order Status & Consumption Actions`, `Catalog Controllers & Models`, `Stock Transfer Tests`, `Order Pricing & Checkout Core`, `Order Pricing & Checkout Core`, `Purchase Actions`, `Order Creation Tests`, `Purchase HTTP Tests`, `Unit Admin Tests`, `Purchase Schema Tests`, `Catalog Tests`, `Stock Ledger Tests`, `Payment Tests`, `Staff Update & App Services`, `Kitchen Access Tests`, `Order Status & Consumption Actions`, `Staff Access Tests`, `Test Base Setup`, `Order Shift Gate Tests`, `Receipt Access Tests`, `Close Shift Tests`, `Open Shift Tests`, `Recipe Admin Tests`, `Void & Cancel Order Tests`, `Purchase Page Access Tests`, `Security Tests`, `Auth Redirect Tests`, `Report Access Tests`, `Inventory Reconcile Tests`, `Shift Totals Tests`, `Stock Count Tests`, `Auto Close Shift Tests`, `Order Status & Consumption Actions`, `Stock Movement Ledger Actions`, `Shift Reconcile Tests`?**
  _High betweenness centrality (0.067) - this node is a cross-community bridge._
- **Should `Vue UI Base Components` be split into smaller, more focused modules?**
  _Cohesion score 0.03036935704514364 - nodes in this community are weakly interconnected._
- **Why does `CashierShift` connect `Shift Lifecycle Jobs` to `Core Domain Models`, `Identity, User & Branch Management`, `Inventory Actions & Stock Ledger`, `Laravel Config & Data Factories`, `User Access & Order Scopes`, `Order & Reporting Queries`, `Branch Presenter & Stock Movement API`, `Shift Actions`, `Order Management Requests`, `Order Status & Consumption Actions`, `Order Pricing & Checkout Core`, `App Boot & Request Forms`, `Auto Close Shift Job`, `Order Creation Tests`, `Order Payment Actions`, `Unit & Conversion Admin`, `Purchase Schema Tests`, `Payment Tests`, `Close Shift Tests`, `Shift Totals Tests`, `Auto Close Shift Tests`?**
  _High betweenness centrality (0.043) - this node is a cross-community bridge._
- **Should `Identity, User & Branch Management` be split into smaller, more focused modules?**
  _Cohesion score 0.04593837535014006 - nodes in this community are weakly interconnected._
