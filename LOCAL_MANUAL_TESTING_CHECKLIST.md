# Local Manual Testing Checklist (`http://127.0.0.1:8000`)

> **Testing Standard**: Do not mark a module **PASS** merely because its page loads. Verify **Page Loads (UI)** AND **End-to-End Business Workflow (Create/Edit/Persist/Calculate/Reload)** with Browser DevTools Console & Network tabs open.

| # | Module | URL / Navigation | Page Loads | Workflow Verification Steps | Result |
|---|---|---|---|---|---|
| 1 | **Login & Logout** | `http://127.0.0.1:8000/app/login` | [ ] | Log in as Store Admin -> refresh browser (session persists) -> log out -> confirm redirected to `/app/login` and protected API returns `401`. | [ ] |
| 2 | **Dashboard** | `/app/user/dashboard` | [ ] | Confirm KPI cards, weekly sales/purchase chart, recent sales, and stock alerts load with HTTP `200` and zero console errors. | [ ] |
| 3 | **Products** | Sidebar -> Products | [ ] | Create a single product with SKU, barcode, cost/selling price, and PNG/JPG image -> verify image renders from `/uploads/...` -> edit price -> verify persistence. | [ ] |
| 4 | **Product Variations** | Sidebar -> Variations & Products | [ ] | Create a variation (e.g., Size: S, M, L) -> create a variation product -> verify each variant SKU appears and can be edited. | [ ] |
| 5 | **Categories & Brands** | Sidebar -> Product Categories / Brands | [ ] | Create a category and brand (with logo) -> assign to product -> verify filter dropdowns show them. | [ ] |
| 6 | **Units** | Sidebar -> Base Units / Units | [ ] | Create a base unit & derived unit -> assign to product -> verify unit displays in Purchase and POS screens. | [ ] |
| 7 | **Customers** | Sidebar -> Customers | [ ] | Create a customer -> edit details -> confirm selectable in POS header dropdown and Sales form. | [ ] |
| 8 | **Suppliers** | Sidebar -> Suppliers | [ ] | Create a supplier -> test CSV import with 2+ rows -> confirm duplicate email is rejected and valid rows persist. | [ ] |
| 9 | **Warehouses & Multiple Stores** | Sidebar -> Warehouses / Stores (top bar) | [ ] | Create a 2nd warehouse and a 2nd store -> switch active store -> confirm products/sales/warehouses isolate cleanly per store. | [ ] |
| 10 | **Purchases** | Sidebar -> Purchases | [ ] | Create a `PENDING` purchase (verify stock does **NOT** increase) -> create a `RECEIVED` purchase (verify stock increases in `ManageStock` and `stock_movements`). | [ ] |
| 11 | **Purchase Returns** | Sidebar -> Purchase Returns | [ ] | Create a purchase return against the `RECEIVED` purchase -> confirm warehouse stock decreases accurately. | [ ] |
| 12 | **POS Checkout** | `http://127.0.0.1:8000/app/user/pos` | [ ] | Search/scan product -> adjust qty -> apply decimal % discount -> test **cash overpayment** (confirm status = `PAID`, change calculated) -> test **split payment** (Cash + Card) -> verify stock deducted -> print receipt preview -> test negative stock rejection (`422`). | [ ] |
| 13 | **Sales** | Sidebar -> Sales | [ ] | Verify POS orders appear in Sales history -> open Sale Details -> add a partial payment on an unpaid/partial sale. | [ ] |
| 14 | **Sales Returns** | Sidebar -> Sales Returns | [ ] | Create a return against a completed sale -> confirm stock is restored in the warehouse. | [ ] |
| 15 | **Stock Adjustments** | Sidebar -> Adjustments | [ ] | Create an Addition adjustment (stock increases) and Subtraction adjustment (stock decreases; excess subtraction rejected). | [ ] |
| 16 | **Expenses** | Sidebar -> Expense Categories / Expenses | [ ] | Create an expense category and expense entry -> verify it appears in Profit & Loss / Dashboard counts. | [ ] |
| 17 | **Reports** | Sidebar -> Reports | [ ] | Verify Warehouse Report, Stock Report, Top Selling, Profit/Loss, Customer Report, Supplier Report, and `GET /api/stock-valuation` + `GET /api/stock-movements`. | [ ] |
| 18 | **PDF & Excel Exports** | Sales / Purchases / Reports | [ ] | Download Sale PDF, Purchase PDF, Stock Report Excel, and Sales Report Excel -> confirm valid files download cleanly without 500 errors. | [ ] |
| 19 | **User Roles & Permissions** | Sidebar -> Roles / Users | [ ] | Create a restricted role (e.g., Cashier without `manage_reports`) -> assign to a new user -> log in as that user -> confirm Reports / Settings / Superadmin endpoints return `403`. | [ ] |
| 20 | **Multi-Tenant Isolation** | Register 2nd tenant or use 2 stores | [ ] | Confirm Tenant A cannot view/switch to Tenant B's store (`403`), cannot view Tenant B's purchases/sales/stock valuation, and cannot access Tenant B's private files (`403`). | [ ] |
