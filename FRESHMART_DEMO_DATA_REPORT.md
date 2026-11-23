# FreshMart Grocery — Demo Data Report

**Date:** 2026-11-23  
**Seeder:** `database/seeders/FreshMartDemoSeeder.php` (100% idempotent, transaction-safe)  
**Owner Account:** `owner@freshmart.demo` (`store_limit_override = 3`, active Subscription)  
**Branch Currency:** `BDT (৳)` (Global USD `$` default and existing StockMate accounts untouched)

---

## 1. Branch-Wise Dataset Summary

| Metric | FreshMart Central (Kushtia — Main) | FreshMart Bheramara (Bheramara, Kushtia) | FreshMart Mirpur (Mirpur, Kushtia) | FreshMart Total |
|---|---:|---:|---:|---:|
| **Store ID** | `474` | `475` | `476` | `3` Branches |
| **Warehouses** | `2` (Main Warehouse + Retail Floor) | `2` (Main Store + Backroom) | `2` (Main Store + Backroom) | `6` |
| **Categories** | `18` | `18` | `18` | `18` Grocery Categories |
| **Products (Unique SKUs)** | `162` (`FMC-001`..`FMC-162`) | `162` (`FMB-001`..`FMB-162`) | `162` (`FMM-001`..`FMM-162`) | `486` Branch SKUs |
| **Low-Stock Alerts** | `11` | `11` | `11` | `33` |
| **Out-of-Stock Items** | `5` | `5` | `5` | `15` |
| **Suppliers** | `12` | `12` | `12` | `36` |
| **Customers** | `15` | `15` | `15` | `45` |
| **Purchases** (8 Received, 2 Pending, 2 Ordered) | `12` (`৳212,648`) | `12` (`৳87,076`) | `12` (`৳118,523`) | `36` (`৳418,247`) |
| **Sales & POS** (Paid + Partial, Cash/bKash/Card) | `40` (`৳40,333`) | `40` (`৳40,762`) | `40` (`৳39,869`) | `120` (`৳120,964`) |
| **Sales Returns** | `2` | `2` | `2` | `6` |
| **Warehouse Transfers** (Completed, Sent, Pending) | `3` | `3` | `3` | `9` |
| **Stock Adjustments** (Damage + Audit Corrections) | `3` | `3` | `3` | `9` |
| **Branch Expenses** (6 Categories) | `12` (`৳174,300`) | `12` (`৳103,860`) | `12` (`৳111,700`) | `36` (`৳389,860`) |
| **Stock Movement Ledger Rows** | `305` | `305` | `305` | `915` |
| **Current Inventory Valuation (Cost)** | **`৳1,617,194`** | **`৳631,287`** | **`৳881,381`** | **`৳3,129,862`** |

---

## 2. Grocery Categories Seeded (18 Categories × 9 SKUs = 162 Products per Branch)
1. Rice & Grains (`Miniket Rice 1kg/5kg`, `Nazirshail`, `Chinigura`, `Basmati`, `BR-28`, `Muri`, `Chira`)
2. Flour & Atta (`Teer Atta 1kg/2kg`, `Fresh Maida`, `Suji`, `Besan`, `Chaler Gura`, `Corn Flour`, `Brown Atta`)
3. Pulses & Lentils (`Moshur Dal`, `Mung Dal`, `Chola`, `Boot Dal`, `Khesari`, `Anchor`, `Mashkalai`, `Motor`)
4. Cooking Oil (`Rupchanda/Teer Soybean Oil 1L/2L/5L`, `Mustard Oil`, `Rice Bran`, `Sunflower`, `Aarong Ghee`, `Olive Oil`)
5. Salt & Sugar (`Fresh/Teer Sugar`, `ACI Iodized Salt`, `Brown Sugar`, `Khejur Gur`, `Bit Lobon`, `Rock Salt`)
6. Spices & Masala (`Radhuni/ACI Turmeric, Chili, Coriander, Cumin, Biryani, Garam, Beef Masala`)
7. Dairy Products (`Marks Milk Powder`, `Aarong Liquid Milk 1L [4d expiry]`, `UHT Milk`, `Curd`, `Butter`, `Cheese`)
8. Eggs (`Kazi Farms Brown/White/Omega-3 Eggs 12 pcs`, `Duck`, `Deshi`, `Quail`, `30-pc Tray`)
9. Beverages (`Ispahani Mirzapore Tea`, `Coffee`, `Mango Drink`, `Mineral Water`, `Cola`, `Green Tea`)
10. Snacks & Biscuits (`Olympic Energy Plus`, `Tip`, `Dry Cake`, `Crackers`, `Chanachur`, `Wafers`)
11. Noodles & Pasta (`Maggi/Mr. Noodles 8-Pack`, `Stick/Cup Noodles`, `Macaroni`, `Penne`, `Spaghetti`, `Semai`)
12. Frozen Foods (`Kazi Farms Nuggets`, `Sausages`, `Paratha`, `Dal Puri`, `Samosa`, `French Fries`, `Fish Fingers`)
13. Fruits (`Apples`, `Malta`, `Banana`, `Guava`, `Papaya`, `Grapes`, `Pomegranate`, `Dates`, `Lemon`)
14. Vegetables (`Potato`, `Red Onion`, `Garlic`, `Ginger`, `Green Chili`, `Tomato`, `Cucumber`, `Carrot`, `Eggplant`)
15. Personal Care (`Bath Soap`, `Shampoo`, `Toothpaste`, `Toothbrush`, `Hair Oil`, `Face Wash`, `Lotion`, `Handwash`)
16. Household Cleaning (`Detergent`, `Dishwash`, `Toilet/Floor/Glass Cleaner`, `Tissue`, `Aerosol`, `Garbage Bags`)
17. Baby Products (`Diapers M/L`, `Wet Wipes`, `Milk Cereal`, `Baby Soap/Lotion/Shampoo/Olive Oil/Powder`)
18. Bakery Products (`Sandwich/Brown Bread [4d expiry]`, `Milk Bun`, `Pound Cake`, `Muffin`, `Croissant`, `Rusk`)

---

## 3. Re-Run & Safety Verification
- Re-running `docker exec digital_inventory_pos_app php artisan db:seed --class=FreshMartDemoSeeder` safely detects existing FreshMart data and skips without duplicating records.
- Existing StockMate accounts (`superadmin@stockmate.com`, `admin@stockmate.com`, `My Store`, `Store 02`) and global `Default Plan` were **100% preserved untouched**.
