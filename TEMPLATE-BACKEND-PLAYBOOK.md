# Margick — Quy trình chuẩn DATABASE & BACKEND cho template (BACKEND PLAYBOOK)

> **Mục đích:** quy trình lặp lại được để dựng phần **DB + backend** cho MỌI template, sao cho càng nhiều template càng **KHÔNG phải thiết kế lại schema từ đầu**.
> Đây là phần *thiếu* bên cạnh quy trình giao diện đã có.
>
> Bộ tài liệu liên quan (đọc theo vai trò):
> - `DESIGN-PRINCIPLES.md` — **VÌ SAO** (4 trục biến thiên, mối nối đa hình). Nền tảng tư duy của file này.
> - `margick-schema.sql` — **đặc tả schema commerce đầy đủ** (north-star của engine giao dịch).
> - `TEMPLATE-BACKEND-PLAYBOOK.md` ← (file này) — **QUY TRÌNH** dựng DB/BE từng template.
> - `mgk_edu_elementor/docs/TEMPLATE-BUILD-PLAYBOOK-ELEMENTOR.md` — **giao diện** (widget, generator, 3 bề mặt). Bổ sung cho file này, không trùng.
> - `mgk_edu_elementor/docs/ARCHITECTURE-BOOKING-PAYMENT.md` — engine giao dịch edu hiện tại (= v1.0 của engine dùng chung).

Cập nhật: 2026-06-18

---

## 0. TL;DR — đọc 60 giây

1. **Công sức scale theo số NĂNG LỰC (~6, ít), KHÔNG theo số TEMPLATE (vô hạn, rẻ).** Mọi việc rơi vào 1 trong 2: *xây năng lực* (hiếm, đắt) hoặc *ráp template* (thường, rẻ).
2. **Mọi dữ liệu rơi vào 1 trong 3 tầng** — Content (CPT+ACF) · Transactional (custom table) · ~~Control plane~~ (KHÔNG dùng — mỗi site standalone).
3. **Tầng giao dịch = MỘT engine dùng chung**, đóng gói ship **giống hệt** vào mọi WP standalone. Edu đang có = **v1.0**. Không Woo làm lõi, không mỗi template tự chế.
4. **Template mới = CONFIG.** Zero bảng mới, zero logic. Chỉ: khai năng lực + 3 mẩu seam nhỏ + seed sample + relabel + layout.
5. **Luật vàng chống rebuild:** schema năng lực = *public API* — chỉ thêm, có version, KHÔNG bao giờ phá vỡ.

---

## 1. Mô hình dữ liệu 3 TẦNG + quy tắc định tuyến

Mỗi mẩu dữ liệu phải vào đúng 1 tầng. Sai tầng = gốc của "đập đi xây lại".

| Tầng | Loại dữ liệu | Substrate (WP) | Ai sửa | Ví dụ |
|---|---|---|---|---|
| **T1 — Content** | Biên tập, catalog, marketing, review hiển thị | **CPT + ACF + taxonomy** | user @ wp-admin | profile (tutor/stylist/coach), dịch vụ, mô tả khóa, FAQ, review |
| **T2 — Transactional** | Giao dịch, tiền, trạng thái, cần chống đua + toàn vẹn | **Custom table** (engine `mgk_*`) | hệ thống (logic khóa) | order, payment, cart, booking hold, enrolment, ledger |
| ~~T3 — Control plane~~ | Quản N site tập trung | _(N/A — site standalone)_ | — | **KHÔNG dùng.** Mỗi template ship 1 WP độc lập, schema giống nhau, data riêng |

### Quy tắc định tuyến (dán lên tường)
```
Dữ liệu này…
  • user biên tập / hiển thị / quan hệ đơn giản?        → T1: CPT + ACF + taxonomy
  • có TIỀN / TRẠNG THÁI / khóa chống đua / toàn vẹn?   → T2: custom table  (TUYỆT ĐỐI không postmeta)
  • thưa, là option / cấu hình?                          → wp_options hoặc cột JSON
```

**Luật cấm (DESIGN-PRINCIPLES §5):** order / payment / booking / inventory **không bao giờ** nhét vào `postmeta`. EAV của postmeta không index nhanh, không transaction sạch. WooCommerce đã phải rời postmeta sang bảng riêng (HPOS) vì đúng lý do này.

**Mẹo phân loại:** không hỏi "thuộc ngành nào" mà hỏi **"có đổi theo ngành không?"** → *Không* = core/T2-engine; *Có* = năng lực ngành.

---

## 2. Hai đơn vị tái dùng: NĂNG LỰC vs TEMPLATE

### 2.1. NĂNG LỰC (capability/module) — build MỘT lần, có version, mọi template dùng lại

Một thư mục tự chứa. Đề xuất khuôn:
```
inc/capabilities/<cap>/
  schema.php        ← T1: register CPT/ACF/taxonomy   HOẶC   T2: migrations/*.php (dbDelta), additive + version
  domain.php        ← logic, state machine, REST, hooks (guard: nonce / ownership / idempotency)
  render.php        ← shortcode single-source → widget Elementor chỉ là vỏ mỏng gọi do_shortcode()
  seed.php          ← sample data IDEMPOTENT (chỉ data, KHÔNG schema)
  capability.json   ← { version, provides[], item_types[], shortcodes[], required_plugins[], data_keys[] }
```

**Năng lực hiện có (gói lại theo khuôn này — KHÔNG build lại):**
`profiles` (CPT mg_teacher) · `reviews` (CPT mg_review + BR-20 calc) · `commerce-engine` (T2, từ inc/booking/) · `tutor-portal` · `discount-voucher` · `lead-match` (S07/S08) · `parent-dashboard`.

> Lỗi cấu trúc cần sửa dần: `schemas/edu.php` đang **đăng ký CPT thẳng trong file template**. CPT phải thuộc *capability* `profiles`; template chỉ *gọi + relabel*. (Việc retrofit này thuộc Làn B, làm khi rảnh — không gấp.)

### 2.2. TEMPLATE — chỉ là CONFIG. Zero bảng, zero logic.
```
seed/manifest.json   ← capabilities[] + required_plugins[] + seed_files[] + layout + category + version
schemas/<category>.php ← COMPOSE năng lực + đặt taxonomy terms + RELABEL nhãn (Tutor→Stylist→Coach)
seed/seed-*.php       ← sample CONTENT (idempotent)
+ layout (generator: wp mgk gen-layouts) + design tokens (mgk-tokens.css)
```

**Luật cấm tuyệt đối trong template:** một câu query nghiệp vụ · một nhánh `if` nghiệp vụ · một `CREATE TABLE` mới → **vi phạm thiết kế** (DESIGN-PRINCIPLES §4). Thấy mình cần làm 1 trong 3 thứ đó = **chuyển sang Làn B**.

---

## 3. Engine giao dịch dùng chung (T2) — spec

### 3.1. Là gì & vì sao
Một **capability `commerce-engine`** = bộ bảng custom + logic giao dịch, **ship giống hệt vào mọi site**. Không server trung tâm: mỗi WP có bản sao riêng, data riêng. Build một lần → mọi template sau dùng lại → **đó là lý do không phải thiết kế schema lại**.

**Hạt giống = Booking Engine Phase 0.5 của edu** (`inc/booking/`, bảng `mgk_bookings`/locks/payments/events, atomic hold, Stripe thin-client, prefix `mgk_engine_`). "Tổng quát hóa" = nâng pattern này từ *edu-only* lên *trung lập ngành*, **không đập**.

### 3.2. Mối nối đa hình — chìa khóa "không design lại" (DESIGN-PRINCIPLES §2)
`order_items` trỏ tới *thứ được bán* bằng **`item_type + item_ref_id`** — liên kết MỀM, **KHÔNG FK cứng**. Engine **không cần biết** ngành nào tồn tại.
- Snapshot `name/price/sku/options` vào order item tại thời điểm mua → lịch sử bất biến.
- Cột đa hình (`item_ref_id`, `ref_id`) **không bao giờ** có FK — chỉ index `(item_type, item_ref_id)` + ràng buộc ở app.

Bảng lõi (start small, hoàn thiện DDL khi generalize — xem `margick-schema.sql` cho bản đầy đủ):
`mgk_orders` · `mgk_order_items` (đa hình) · `mgk_payments` · `mgk_carts`/`mgk_cart_items` + bảng booking hiện có (`mgk_bookings`/locks/events) folded vào.

### 3.3. 3 mẩu SEAM mỗi template phải cắm (cỡ config, KHÔNG phải schema)
Đây là toàn bộ việc backend của một template "bình thường":

| Seam | Là gì | Ví dụ edu | Ví dụ fashion |
|---|---|---|---|
| **1. item_type registry** | Khai báo site này bán gì + bảng/CPT nào đỡ phía sau | `edu_booking` → reservation | `retail_variant` → product variant |
| **2. Pricing resolver** | 1 callback: "item_type X, ref Y → giá + option?" | giá buổi học × số buổi | giá variant |
| **3. Fulfillment hook** | 1 callback: "trả tiền xong làm gì?" | xác nhận buổi + provision login | trừ kho + tạo phiếu giao |

→ Engine (bảng + luồng tiền + payment + chống đua + state machine) build **một lần**. Template mới chỉ viết 3 mẩu trên + relabel. **Không CREATE TABLE.**

### 3.4. Mở rộng theo site (khi "phức tạp hơn")
Site cần thêm → **thêm cột/bảng additive** (migration version mới *cho site đó*), **không sửa v1.0**. Bản gốc luôn tái dùng được. (DESIGN-PRINCIPLES §7: core = public API.)

### 3.5. Quan hệ với `margick-schema.sql` (cc_*)
`cc_*` là **north-star đầy đủ 6 module**. Hiện tại **chỉ hiện thực subset** (lấy đúng phần edu đã có, nắn trung lập). Khi có ngành thứ 2 ép buộc → mở rộng dần về phía cc_*. **Không build cả 6 module trước khi validate ngành #2.**

---

## 4. Quy trình 2 LÀN

**Cổng quyết định ở đầu mỗi việc:** *Việc này cần năng lực/bảng/state-machine/luồng-payment MỚI không?*
→ **Không** = Làn A. **Có** = Làn B.

### 🟢 Làn A — Tạo template mới (90% việc · mục tiêu ~1 ngày · ZERO migration)
1. `./new-template.sh <slug> <category>` (clone master + đổi Theme Name + manifest).
2. Sửa `seed/manifest.json`: chọn `capabilities[]` từ registry, `required_plugins[]`, `seed_files[]`, layout.
3. `schemas/<category>.php` = **compose** năng lực + đặt taxonomy terms + **relabel** nhãn. *(KHÔNG tạo bảng.)*
4. Cắm **3 seam** của engine giao dịch (item_type + pricing resolver + fulfillment hook) nếu template có bán/booking.
5. Viết seed sample (idempotent, chỉ content).
6. Dựng layout Elementor theo **quy trình giao diện đã có** + design tokens.
7. **Verify** (xem §6 + §7).
8. `./bundle.sh` → zip giao khách.

**Checklist Làn A:**
- [ ] Không có `CREATE TABLE` / query nghiệp vụ / `if` nghiệp vụ nào trong file template
- [ ] Mọi năng lực dùng đã có trong registry (không "tiện tay" viết logic mới)
- [ ] 3 seam cắm xong, pricing resolver trả đúng số tiền
- [ ] Seed idempotent + có guard, chạy lại không nhân đôi
- [ ] DB sạch → activate → seed → UI khớp wireframe 100% → giao dịch e2e chạy
- [ ] `diff -rq SOURCE RUNTIME` sạch

> **Cổng chặn:** cần bảng mới / state machine mới / luồng payment mới → **DỪNG, sang Làn B.** Không nhét vào template.

### 🔴 Làn B — Xây / mở rộng NĂNG LỰC (hiếm · đắt · có kỷ luật migration)
1. **Đặc tả:** thực thể, trạng thái, có tiền không, có đua không, cần integrity không → **chọn tầng** (T1 CPT hay T2 table) theo §1.
2. **Schema** = migration **có version, CHỈ-THÊM**; ghi version đã apply (xem §5.4).
3. **Domain logic** + REST + state machine (khuôn `mgk_engine_`, đủ guard: nonce / ownership / idempotency).
4. **Render shortcode** single-source → widget vẫn là vỏ mỏng.
5. **Seed** sample idempotent.
6. **Đăng ký** vào `capability.json` (version, item_types, shortcodes, plugins, data_keys).
7. **Verify e2e** bằng HTTP thật (như cách đã verify booking/tutor-portal).
8. Xong → **mọi template sau chỉ khai trong manifest** = build once, reuse forever.

**Checklist Làn B:**
- [ ] Đúng tầng (không nhét giao dịch vào postmeta, không nhét content vào custom table)
- [ ] Migration additive, có version; không đổi/xóa cột/meta-key cũ
- [ ] Cột đa hình KHÔNG có FK, có index `(item_type, item_ref_id)`
- [ ] Tiền = DECIMAL(15,2) + currency, không FLOAT
- [ ] Enum = VARCHAR + comment (không kiểu ENUM)
- [ ] Guard đủ: nonce, ownership, idempotency
- [ ] Đăng ký capability.json + verify e2e HTTP thật

---

## 5. Luật chống "ĐẬP ĐI XÂY LẠI" (kỷ luật bắt buộc)

1. **Schema năng lực = PUBLIC API.** Chỉ thêm. **KHÔNG bao giờ** đổi tên / xóa cột (T2) hay meta-key (T1). Cần bỏ → *deprecate*, không *delete*. Hàng loạt template + site shipped dựa vào nó. (DESIGN-PRINCIPLES §7)
2. **Field-key registry mỗi năng lực.** Widget/template **không hardcode** raw meta-key/tên-bảng → luôn qua hằng/helper. Đổi nội bộ không vỡ template.
3. **Seed = sample data, KHÔNG phải schema.** Idempotent + guard (`_mgk_layout_seeded` đã có). Re-activate **không bao giờ** hủy data user. Seed chỉ tạo khi chưa có.
4. **Ghi version đã-apply per-site.** Mỗi site lưu năng lực nào đã ở version nào (option/bảng local, bản thu nhỏ của `ctrl_migrations_ledger`). → biết site nào cần migrate forward mà không phá.
5. **Tách "relabel/skin" khỏi "behavior".** Đa ngành = đổi nhãn / taxonomy / option, **KHÔNG fork logic.** Tutor→Stylist→Coach dùng *cùng* `profiles` + `commerce-engine`.
6. **Seam registry (item_type / capability key).** Template *khai báo*, không *gọi thẳng* tên bảng → giữ engine tách ngành (DESIGN-PRINCIPLES §2).

---

## 6. Ráp vào stack & workflow hiện tại

**Nơi đặt code:**
- Năng lực T1 (CPT/ACF) → theme `inc/` (như hiện tại).
- Engine T2 → khuyến nghị **must-use plugin** (sống sót khi đổi theme; giao dịch không nên phụ thuộc theme). Hiện đang ở theme `inc/booking/` — cân nhắc tách khi generalize.

**Chạy migration:** hook `after_switch_theme`/activate → `dbDelta()` từng năng lực → so version đã-apply (§5.4) → chỉ chạy bước thiếu (idempotent).

**Sync/lint/verify (theo ONBOARDING §3 — KHÔNG bind-mount):**
1. Sửa SOURCE → `cp` sang RUNTIME.
2. `docker exec mgk-edu-el php -l <file>` (lint trong container).
3. Verify FE: `curl` (đếm widget/card/leak) + headless cho editor.
4. Verify BE: gọi HTTP thật (REST/admin-post) → kiểm DB bằng wp-cli/SQL.
5. Đổi layout → `wp mgk gen-layouts` → `cp seed-layouts.php` ngược SOURCE.
6. Cuối: `diff -rq SOURCE RUNTIME` sạch.

---

## 7. Definition of Done — cổng release mỗi template

> **Release-ready = DB sạch → activate → seed → UI khớp wireframe 100% + giao dịch chạy e2e — TRƯỚC khi user đụng.** (mở rộng từ ONBOARDING.)

- [ ] Từ DB trắng: activate theme → migration chạy đủ → seed populate → không lỗi PHP.
- [ ] UI khớp wireframe 100% (quy trình giao diện).
- [ ] Luồng giao dịch chính chạy e2e bằng HTTP thật (đặt → trả → fulfill), số tiền đúng.
- [ ] Re-activate / re-seed không nhân đôi, không hủy data.
- [ ] `diff -rq SOURCE RUNTIME` sạch · `capability.json` + `manifest.json` khớp thực tế.

---

## 8. Khi phân vân — quay về câu hỏi gốc

> *Quyết định này đang giữ NĂNG LỰC tách khỏi TEMPLATE, và giữ 3 tầng tách nhau — hay đang gộp chúng lại?*

Gộp lại = mầm rebuild. Tách rời = chi phí thay đổi không lan. Mọi câu "đặt cái này ở đâu / thiết kế thế nào" đều quy về đây.
