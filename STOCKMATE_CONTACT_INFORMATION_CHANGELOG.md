# StockMate Business Contact Information — Changelog

**Date:** 2026-11-23  
**Project:** `/Users/macminim4/Projects/digital-inventory-with-pos`

---

## 1. Database Records Updated (Transactional)

- **`sadmin_settings`**:
  - `email`: `support@stockmate.app` → `dynamic.digital.ltd@gmail.com`
  - `phone`: `+91 70963 36561` → `01749-560393`
  - `phone_secondary` (added): `01931-336664`
  - `address`: `C-303, Atlanta Shopping Mall, ... Surat, Gujarat, India.` → `Mirpur, Kushtia, Bangladesh - 7030`
  - `country`: `India` → `Bangladesh`
  - `state`: `Gujarat` → `Khulna`
  - `city`: `Surat` → `Kushtia`
  - `postcode`: `395007` → `7030`
- **`settings`** (local default tenant):
  - `country`: `India` → `Bangladesh`
  - `state`: `Gujarat` → `Khulna`
  - `city`: `Surat` → `Kushtia`
- **`faqs`** (ID 11 — *"How do I contact customer support?"*):
  - Updated to reference `dynamic.digital.ltd@gmail.com` and `01749-560393 / 01931-336664`.
- **Untouched:** `customers`, `suppliers`, `users`, `stores`, `sales`, `purchases`, and `transactions`.

---

## 2. Files Modified

1. **`resources/views/web/home.blade.php`**  
   - Updated `#contact-us` section with StockMate Orange (`#F47716`) and Deep Navy (`#092B48`) styling on `#F8FAFC` cards.
   - Displays official Address (`Mirpur, Kushtia, Bangladesh - 7030`), Email (`mailto:dynamic.digital.ltd@gmail.com`), and both Phone numbers (`tel:+8801749560393` → `01749-560393` and `tel:+8801931336664` → `01931-336664`) each paired with a dedicated WhatsApp button (`https://wa.me/8801749560393` and `https://wa.me/8801931336664`, `target="_blank"`, `rel="noopener noreferrer"`, accessible `aria-label`s).
2. **`resources/views/web/layouts/footer.blade.php`**  
   - Added a responsive 3-column contact strip inside the existing Deep Navy footer displaying both clickable phone numbers with separate WhatsApp links, clickable email, and official address.
3. **`database/seeders/DefaultSettingSeeder.php`**  
   - Updated Super Admin and default tenant contact/location seed values to official StockMate details and converted record creation to idempotent `firstOrCreate()`.
4. **`database/seeders/DefaultFrontDataSeeder.php`**  
   - Updated FAQ #11 with official StockMate email and phone/WhatsApp details.
5. **`database/seeders/DefaultUserSeeder.php`**  
   - Made role, admin user, store, and tenant seeding idempotent so re-running never resets admin accounts or passwords.
6. **`app/Repositories/AdminRepository.php`**  
   - Updated newly provisioned tenant default location (`Bangladesh`, `Khulna`, `Kushtia`).
7. **`tests/Feature/SecurityAndPosAuditTest.php`**  
   - Added automated feature test `test_stockmate_contact_information_and_whatsapp_links_render_on_landing_page`.

---

## 3. Verification Summary

- **WhatsApp Links Verified in Live DOM (`http://127.0.0.1:8000/`):**
  - `https://wa.me/8801749560393` (Contact section + Footer, `target="_blank"`, `rel="noopener noreferrer"`)
  - `https://wa.me/8801931336664` (Contact section + Footer, `target="_blank"`, `rel="noopener noreferrer"`)
- **Email & Phone Links Verified:** `mailto:dynamic.digital.ltd@gmail.com`, `tel:+8801749560393`, `tel:+8801931336664`.
- **Legacy Contact Remnants:** `0` (`hasOldPhone: false`, `hasOldAddress: false`).
- **Screenshots Captured:**
  - [cc-01-contact-1440.png](file:///Users/macminim4/.gemini/antigravity/brain/87e83e56-7d9d-40db-b3c3-38695000f7a3/scratch/cc-01-contact-1440.png)
  - [cc-02-footer-1440.png](file:///Users/macminim4/.gemini/antigravity/brain/87e83e56-7d9d-40db-b3c3-38695000f7a3/scratch/cc-02-footer-1440.png)
  - [cc-03-contact-mobile-390.png](file:///Users/macminim4/.gemini/antigravity/brain/87e83e56-7d9d-40db-b3c3-38695000f7a3/scratch/cc-03-contact-mobile-390.png)
