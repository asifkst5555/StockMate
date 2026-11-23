# StockMate Business Contact Information — Audit Report

**Date:** 2026-11-23  
**Project:** `/Users/macminim4/Projects/digital-inventory-with-pos`  
**Brand:** StockMate

---

## 1. Official Business Contact Information (Target State)

- **Business Name:** StockMate
- **Primary Phone:** `01749-560393` (`tel:+8801749560393`)
- **Secondary Phone:** `01931-336664` (`tel:+8801931336664`)
- **WhatsApp #1:** `https://wa.me/8801749560393` (`+8801749560393`)
- **WhatsApp #2:** `https://wa.me/8801931336664` (`+8801931336664`)
- **Email:** `dynamic.digital.ltd@gmail.com` (`mailto:dynamic.digital.ltd@gmail.com`)
- **Address:** `Mirpur, Kushtia, Bangladesh - 7030`

---

## 2. Files & Database Tables Inspected

| Area | Location Inspected | Pre-Update Findings | Action Required |
|---|---|---|---|
| Super Admin Settings DB | `sadmin_settings` table | `phone => "+91 70963 36561"`, `email => "support@stockmate.app"`, `address => "C-303, Atlanta Shopping Mall, ... Surat, Gujarat, India."`, `country => "India"`, `state => "Gujarat"`, `city => "Surat"`, `postcode => "395007"`, no secondary phone | Update to official StockMate details & add `phone_secondary` |
| Tenant Settings DB | `settings` table | `country => "India"`, `state => "Gujarat"`, `city => "Surat"` | Update default store location to Bangladesh / Khulna / Kushtia |
| Public FAQs DB | `faqs` table (ID 11) | Referenced `support@stockmate.app` | Update to `dynamic.digital.ltd@gmail.com` & both phone/WhatsApp numbers |
| Landing Page Contact Section | `resources/views/web/home.blade.php` | Displayed single phone number without WhatsApp links | Update to display Address, both clickable phones with separate `https://wa.me/...` links (`target="_blank"`, `rel="noopener noreferrer"`, accessible `aria-label`s), and clickable email |
| Public Website Footer | `resources/views/web/layouts/footer.blade.php` | Displayed logo, legal links, copyright, and social icons only (no contact block) | Add responsive StockMate contact bar with both phones + separate WhatsApp links, email, and address |
| Default Settings Seeder | `database/seeders/DefaultSettingSeeder.php` | Hardcoded legacy `+91 70963 36561`, `Surat, Gujarat, India`, `support@stockmate.app`; used non-idempotent `create()` | Update to official StockMate contact info and convert to idempotent `firstOrCreate()` |
| Default Front Data Seeder | `database/seeders/DefaultFrontDataSeeder.php` | FAQ #11 referenced `support@stockmate.app` | Update FAQ #11 to official email & phone/WhatsApp details |
| Default User Seeder | `database/seeders/DefaultUserSeeder.php` | Used non-idempotent `create()` and legacy placeholder emails/phone | Convert to idempotent `firstOrCreate()` preserving existing admin accounts & passwords |
| Tenant Registration Repository | `app/Repositories/AdminRepository.php` | Hardcoded `India / Gujarat / Surat` for new tenant default settings | Update to `Bangladesh / Khulna / Kushtia` |
| Customers / Suppliers / Stores | `customers`, `suppliers`, `stores`, `users` tables | Contains tenant/customer/store-owner data | **Preserved untouched** per scope rules |
| PDF / Invoice Templates | `resources/views/pdf/*.blade.php` | Pull company contact dynamically from settings | No hardcoded legacy StockMate contact info found |
