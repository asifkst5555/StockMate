# StockMate — NorthStar Market (USA) Visual Quality Assurance Report

**Date:** 2026-11-23  
**Environment:** Local Docker (`http://127.0.0.1:8000`) — Logged in as `admin@stockmate.com` (ID `2`)  
**PHPUnit:** `39 passed, 0 failed` (`1.86s`) | **React Build:** `npm run prod` `0` errors

---

## 1. Responsive, Currency & Broken-Image DOM Audit (All 4 Viewports)

| # | Screen | Viewport | `scrollWidth ≤ innerWidth` | Visible `<img>` | Broken `<img>` | USD (`$`) Verified | BDT (`৳`) Absent | Screenshot |
|---|---|---|---|---:|---:|:---:|:---:|---|
| 1 | **Dashboard** | `1440×900` | `1435 ≤ 1440` (`0px`) | `5` | `0` | Yes | Yes | [usa-01-dashboard-1440x900.png](file:///Users/macminim4/Projects/digital-inventory-with-pos/docs/northstar-usa-qa/usa-01-dashboard-1440x900.png) |
| 2 | **Store Switching (3 NYC Branches)** | `1440×900` | `1435 ≤ 1440` (`0px`) | `5` | `0` | Yes | Yes | [usa-02-store-switching-1440x900.png](file:///Users/macminim4/Projects/digital-inventory-with-pos/docs/northstar-usa-qa/usa-02-store-switching-1440x900.png) |
| 3 | **POS Screen** | `1440×900` | `1440 ≤ 1440` (`0px`) | `30` | `0` | Yes | Yes | [usa-03-pos-1440x900.png](file:///Users/macminim4/Projects/digital-inventory-with-pos/docs/northstar-usa-qa/usa-03-pos-1440x900.png) |
| 4 | **Product Catalog** | `1440×900` | `1435 ≤ 1440` (`0px`) | `10` | `0` | Yes | Yes | [usa-04-catalog-1440x900.png](file:///Users/macminim4/Projects/digital-inventory-with-pos/docs/northstar-usa-qa/usa-04-catalog-1440x900.png) |
| 5 | **Product Detail (`Coca-Cola Original Taste`)** | `1440×900` | `1440 ≤ 1440` (`0px`) | `1` | `0` | Yes | Yes | [usa-05-product-detail-1440x900.png](file:///Users/macminim4/Projects/digital-inventory-with-pos/docs/northstar-usa-qa/usa-05-product-detail-1440x900.png) |
| 6 | **Category Listing (14 US Categories)** | `1440×900` | `1435 ≤ 1440` (`0px`) | `10` | `0` | N/A | Yes | [usa-06-categories-1440x900.png](file:///Users/macminim4/Projects/digital-inventory-with-pos/docs/northstar-usa-qa/usa-06-categories-1440x900.png) |
| 7 | **Sales Invoice (`NS_111363`)** | `1440×900` | `1435 ≤ 1440` (`0px`) | `0` | `0` | Yes | Yes | [usa-07-sales-invoice-1440x900.png](file:///Users/macminim4/Projects/digital-inventory-with-pos/docs/northstar-usa-qa/usa-07-sales-invoice-1440x900.png) |
| 8 | **Purchase Invoice (`PO_111183`)** | `1440×900` | `1435 ≤ 1440` (`0px`) | `0` | `0` | Yes | Yes | [usa-08-purchase-invoice-1440x900.png](file:///Users/macminim4/Projects/digital-inventory-with-pos/docs/northstar-usa-qa/usa-08-purchase-invoice-1440x900.png) |
| 9 | **POS Screen (Laptop)** | `1280×800` | `1275 ≤ 1280` (`0px`) | `30` | `0` | Yes | Yes | [usa-09-laptop-pos-1280x800.png](file:///Users/macminim4/Projects/digital-inventory-with-pos/docs/northstar-usa-qa/usa-09-laptop-pos-1280x800.png) |
| 10 | **Dashboard (Tablet)** | `768×1024` | `763 ≤ 768` (`0px`) | `5` | `0` | Yes | Yes | [usa-10-tablet-dashboard-768x1024.png](file:///Users/macminim4/Projects/digital-inventory-with-pos/docs/northstar-usa-qa/usa-10-tablet-dashboard-768x1024.png) |
| 11 | **Product Catalog (Mobile)** | `390×844` | `390 === 390` (`0px`) | `10` | `0` | Yes | Yes | [usa-11-mobile-products-390x844.png](file:///Users/macminim4/Projects/digital-inventory-with-pos/docs/northstar-usa-qa/usa-11-mobile-products-390x844.png) |

## 2. Manual Visual Inspection Notes

- **Contact Sheet Inspection:** Manually reviewed 72+ downloaded Open Food Facts packaging images via visual contact sheets ([usa_contact_sheet_1.png](file:///Users/macminim4/.gemini/antigravity/brain/87e83e56-7d9d-40db-b3c3-38695000f7a3/scratch/usa_contact_sheet_1.png), [usa_contact_sheet_2.png](file:///Users/macminim4/.gemini/antigravity/brain/87e83e56-7d9d-40db-b3c3-38695000f7a3/scratch/usa_contact_sheet_2.png)). Confirmed authentic real-world US brand packaging (Coca-Cola, Pepsi, Sprite, Dr Pepper, Lay's, Doritos, Cheetos, Oreo, Ritz, Cheerios, Nutella, Jif, Heinz, Kraft, Campbell's, Barilla, DiGiorno, Eggo, Tide, etc.) and replaced a Reese's Peanut Butter jar mismatch with the exact Reese's Cups UPC photo.
- **Live Screen Inspection:** Verified `usa-02-store-switching`, `usa-03-pos`, `usa-05-product-detail`, and `usa-07-sales-invoice` directly — crisp white `contain` framing, zero distortion, `$` USD formatting everywhere, and all 3 NYC stores (`NorthStar Market – Manhattan`, `NorthStar Market – Brooklyn`, `NorthStar Market – Queens`) selectable in the top-left Store Switcher.
