# StockMate — NorthStar Market (USA) Pre-Transformation Audit & Backup Verification

**Date:** 2026-11-23  
**Project:** `/Users/macminim4/Projects/digital-inventory-with-pos`  
**Target Account:** Existing `admin@stockmate.com` (User ID `2`, password unchanged)  
**Target Stores:** Existing Store IDs `1`, `387`, `816`

---

## 1. Verified Database Backup (Taken Prior to Any Modification)

- **Backup File:** `/Users/macminim4/.gemini/antigravity/brain/87e83e56-7d9d-40db-b3c3-38695000f7a3/scratch/backups/pre_usa_demo_20261123.sql`
- **File Size:** `1.3 MB` (verified non-empty full `mysqldump` of `digital_inventory_pos`)
- **Reversibility:** Full rollback possible at any time via `mysql < pre_usa_demo_20261123.sql`.

## 2. Pre-Transformation Ownership & Safety Audit

| Store ID | Previous Name | Tenant ID | Existing Products | Non-Demo SKUs | Ownership Verdict |
|---:|---|---|---:|---:|---|
| `1` | `FreshMart Central – Main Branch, Kushtia` | `f58e82d5-2dd0-460a-be33-2a6b8cda9ad1` | `156` | **`0`** | 100% Demo-Owned — Safe to Transform |
| `387` | `FreshMart Bheramara – Bheramara, Kushtia` | `5f26af71-9a8a-4492-9717-ae613911a96c` | `156` | **`0`** | 100% Demo-Owned — Safe to Transform |
| `816` | `FreshMart Mirpur – Mirpur, Kushtia` | `42446254-63cf-46dc-8840-28fc99262d5e` | `156` | **`0`** | 100% Demo-Owned — Safe to Transform |

## 3. Preserved Accounts & Unrelated Tenants

- `superadmin@stockmate.com` (ID `1`): **Untouched**
- `admin@stockmate.com` (ID `2`): **Password, roles, permissions, and ID preserved; zero new users created**
- `owner@freshmart.demo` (Stores `474`, `475`, `476`): **Untouched**
- Global `Default Plan` (`no_of_stores = 10`): **Untouched**
- Global `currencies`: `USD` (`id = 2`, `$`) verified present without duplicates.
