# Pharmacy Wholesale Inventory & Purchasing System

A Laravel-based internal system built for a pharmaceutical wholesale warehouse to manage inventory restocking, shortage tracking, and a full purchasing decision & procurement workflow — from demand forecasting to supplier management to receiving reconciliation.

> Built for a real, active warehouse operation. This repository contains the application code only; no real business or customer data is included (see `.gitignore`).

## What it does

### Inventory & restocking
- Imports stock and sales data from Excel/CSV, matches products automatically by SKU, and keeps the central warehouse stock in sync with pharmacy branches and points of sale.
- Merges duplicate line items per pharmacy into a single restocking invoice, and gives the purchasing team a live shortage dashboard with filtering and one-click Excel export.

### Purchasing decision engine
- Calculates each product's daily consumption rate from historical sales and forecasts near-term demand.
- Allocates a limited purchasing budget across products by priority tier (target products, critical shortages, active promotions), with a monthly budget cap configurable by an admin (with history of past months).
- Lets the purchasing manager choose between three interchangeable strategies — **Balanced**, **Max Profitability**, and **Restocking Only** — implemented with the Strategy design pattern.
- Resolves supplier pricing and evaluates bulk-discount tiers when deciding how much to order.
- Full purchase-order lifecycle: generate proposed orders → review line-by-line reasoning ("why this quantity/supplier") → approve → track status.

### Supplier & pricing management
- Manage suppliers, link products to suppliers with unit cost and preferred-supplier flag, and configure time-boxed bulk-quantity offers (free units or percentage discounts).
- Bulk-import historical supplier prices from Excel to bootstrap supplier/product pricing in one pass.
- **Cost variance monitor** — flags any product whose currently listed supplier price has risen above the last price actually paid, to catch margin erosion early.

### Receiving & reconciliation
- Upload what was physically received against a sent purchase order; the system auto-matches received lines to order items and lets a user manually resolve anything ambiguous.
- On finalization, updates inventory batches, marks orders as fully/partially received, and flags unmet demand as a potential market shortage.

### Platform
- Queued background processing (Laravel queues) for long-running import/allocation jobs.
- Role-agnostic thin controllers — business logic lives entirely in a Service layer (`app/Services/Purchasing/*`), keeping controllers under ~10 lines each.

## Tech stack

- **Backend:** PHP, Laravel, MySQL/SQLite
- **Architecture:** Service layer, Strategy pattern, Form Requests, Enums, Jobs/Queues
- **Data:** Excel/CSV import & export (Laravel Excel)

## Project structure highlights

```
app/
├── Http/Controllers/
│   └── Purchasing/        # Orders, Suppliers, Offers, Receiving, Budget, Cost Variance, Historical Import
├── Models/                 # Eloquent models (Product, Supplier, PurchaseOrder, BudgetSetting, ...)
├── Services/
│   └── Purchasing/
│       ├── DemandCalculationService.php
│       ├── BudgetAllocationService.php
│       ├── SupplierPricingResolver.php
│       ├── ReceivingReconciliationService.php
│       ├── MarketShortageDetectionService.php
│       └── Scenarios/     # Strategy pattern: Balanced / MaxProfitability / RestockingOnly
├── Jobs/                   # Queued background processing
└── Exports/ Imports/       # Excel handling
resources/views/
├── purchasing/              # Orders, suppliers, receiving, budget settings, cost variance
└── inventory/                # Sales/stock upload, shortages, invoices
```

## Status

Currently under active development, built to serve a real pharmaceutical wholesale warehouse's inventory and purchasing needs. The purchasing/procurement module (demand forecasting, budgeting, supplier management, receiving reconciliation) is the current focus (V2) and continues to evolve.

## Author

Majd Alkhedr — Backend Developer (Laravel / PHP)
majdalmalek1@gmail.com

