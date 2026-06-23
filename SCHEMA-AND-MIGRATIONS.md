# Margick — Schema & Migrations (BẢN CHỐT cho dev triển khai)

> Đây là bản **đã-chống-thoái-hóa**: tư duy schema + 5 luật siết để nó không gãy ở năm thứ hai.
> Slot vào `MODULE-ARCHITECTURE.md`. Bộ tài liệu: `DESIGN-PRINCIPLES.md` (vì sao) · `margick-schema.sql` (north-star đầy đủ) · `TEMPLATE-BACKEND-PLAYBOOK.md` (quy trình) · file này (**luật schema + thứ tự build**).

Cập nhật: 2026-06-22

---

## 0. Mô hình một dòng + cổng quyết định

- **Mỗi bảng có đúng một chủ, đóng dấu vào tiền tố tên bảng.** `*_core_*` = lõi · `*_<module>_*` = module ngành · **template = 0 bảng**.
- **Số bảng = hàm của số NGÀNH (~6–10), không phải số TEMPLATE (~2000).** Thêm template ⇒ 0 schema.
- **Lõi mù ngành:** lõi không bao giờ chứa cột mang dáng dấp ngành. Nó chỉ trỏ tới *thứ được bán* qua seam.
- **Cổng mỗi việc:** cần *bảng / cột / state-machine / luồng-payment mới* không? → *Không* = Làn A (config). → *Có* = Làn B (extend capability, hiếm, có kỷ luật migration).

> Lưu ý prefix: engine edu đang dùng `mgk_*`. **Đừng rename bảng đã ship** (rename = migration rủi ro, vô ích). Giữ `mgk_*`, mở rộng convention thành `mgk_core_*` / `mgk_<module>_*`. Tên `cc_*` trong `margick-schema.sql` là north-star, map 1:1 sang `mgk_*` của bạn. Luật ownership-theo-tiền-tố đúng bất kể chữ cái.

---

## 1. BỐN LUẬT SCHEMA (gân — không thương lượng)

### LUẬT 1 — Hướng FK có hai chiều khác nhau
"No-FK" **không** phải toàn cục. Phân biệt rõ:

| Chiều tham chiếu | Cơ chế | Lý do |
|---|---|---|
| **lõi → thứ-được-bán** (`order_items.item_ref_id`) | **đa hình, KHÔNG FK** + index `(item_type, item_ref_id)` | lõi không được biết bảng ngành |
| **ngành/crosscut → lõi** (`booking_reservations.customer_id`, `enrollments.order_id`) | **FK thật** hướng về lõi | cùng chiều "ngành cắm vào lõi" → giữ được integrity |

> Sai lầm cần tránh: phát biểu "no-FK" toàn cục rồi vứt luôn integrity ở chỗ đáng ra giữ được. Chỉ cột đa hình (`item_ref_id`, `ref_id`) mới no-FK.

### LUẬT 2 — `order_items` phải TỰ-ĐỦ (orphan-survivable)
Vì seam no-FK, hàng gốc (`courses`, `variants`) có thể bị xóa/đổi giá sau khi đặt. **Mọi field cần để xử lý đơn khi hàng gốc biến mất phải nằm trong snapshot.** Tối thiểu mỗi `order_item` chụp:

```
item_type · item_ref_id · name · sku · unit · unit_price · qty
options_json · line_discount · line_tax · line_total
```

Phép thử: *render hóa đơn + tính refund + lên báo cáo* phải làm được **chỉ từ `order_items`**, không truy hàng gốc. Thiếu field nào để làm 3 việc đó → field đó phải vào snapshot.

### LUẬT 3 — Template chỉ chạm DB qua Repository; CI chặn raw SQL
Tiền tố là *quy ước*, không phải *rào*. Không gì cản code template chạy `ALTER TABLE mgk_core_orders`. Cần cặp ràng buộc cứng:

- **Template không bao giờ gọi `$wpdb` thẳng vào bảng.** Mỗi capability expose một **Repository / helper** (hằng số tên-bảng + hàm CRUD có guard). Template chỉ gọi qua đó.
- **CI gate (fail build):** quét thư mục template tìm `CREATE TABLE` · `ALTER TABLE` · `$wpdb->query` nghiệp vụ · tên bảng `*_core_*` hardcode · raw meta-key. Bắt được → fail.

> Không có cái này, "template sở hữu 0 bảng" là *lời hứa*, không phải *ràng buộc*. Ở 2000 template, kiểm tay chắc chắn lọt.

### LUẬT 4 — JSON không được thành cột-rác
`attributes_json {size, màu}` là cửa hậu để "schema theo template" lẻn về (mỗi template nhồi key khác → fork trong một cột, không query/validate được).

- JSON **chỉ** cho túi thuộc tính thật sự mở, *không* query/filter/dùng-cho-logic (vd: xuất xứ, chất liệu hiển thị).
- Key nào bắt đầu được **query / filter / dùng trong logic** → **promote lên cột thật** (additive migration).
- Mỗi capability giữ một **registry key đã-promote** để biết cái gì là cột, cái gì là túi.

> Đổi "phình bảng" lấy "phình key JSON" vẫn là phình. JSON là túi, không phải bảng ẩn.

---

## 2. Quy ước cột — đóng vào core v1.0 NGAY (primitive hiển nhiên, không phải suy đoán)

```
id            BIGINT UNSIGNED AUTO_INCREMENT PK
created_at / updated_at   TIMESTAMP
tiền          DECIMAL(15,2) + cột currency        -- không FLOAT
enum/trạng thái VARCHAR + comment                  -- không kiểu ENUM (thêm state khỏi ALTER)
qty           DECIMAL(12,3)                         -- bán theo cân/lít/mét: 1.5 kg, 0.250 kg, 2 cái
sell_unit     VARCHAR  ('piece'|'kg'|'g'|'l'|'m')   -- đơn vị bán
attributes_json JSON                                -- túi mở (LUẬT 4)
```

Seam (lõi):
```
order_items.item_type    VARCHAR        -- 'edu_course' | 'retail_variant' | ...
order_items.item_ref_id  BIGINT         -- = id bảng module, KHÔNG FK
+ snapshot đầy đủ (LUẬT 2)
INDEX (item_type, item_ref_id)
```

> `qty` DECIMAL + `sell_unit` phủ trọn thịt(kg)/vàng(chỉ)/vải(mét)/giày(cái) **mà không đụng schema lần nào nữa** — meat-vs-shoe chỉ khác giá trị, là config. Đây là ranh giới "build primitive hiển nhiên trước" vs "để dành cấu trúc suy đoán".

---

## 3. Kỷ luật Migration

1. **Thứ tự boot:** chạy migration theo `core → crosscut (booking) → industry`. Industry FK vào core nên core phải đi trước.
2. **Schema-version ≠ code-version.** Gate migrate key theo **schema version riêng từng capability** (như edu đang làm với `mgk_booking_schema_version`). KHÔNG trộn vào version composer/plugin.
3. **Per-site applied-version ledger (bản thu nhỏ):** mỗi site lưu capability nào đã ở schema-version nào → biết bước nào còn thiếu, chạy idempotent, không phá data.
4. **Additive-only (public API):** chỉ `ADD COLUMN` / `CREATE TABLE`. Không `RENAME`/`DROP`. Cần bỏ → *deprecate*, để yên. (Đặc biệt vì site standalone — bạn không với tới site đã ship để sửa.)
5. **Đổi *hình dạng* dùng expand/contract** (xem §5), không sửa tại chỗ.

---

## 4. Thứ tự BUILD — bắt đầu từ đâu

> Nguyên tắc: **tách "dựng lõi" (làm ngay) khỏi "tổng quát hóa resource_*" (đợi ngành #2).** Đừng tưởng phải giải resource_* mới bắt đầu được.

### 🟦 Phase 0 — Dựng RANH GIỚI lõi từ engine edu (làm ngay · KHÔNG đụng resource_*)
1. **Bọc engine giao dịch edu hiện có** (`inc/booking/`: orders/payments/booking) sau **Repository**. Khai chúng là **lõi v1.0**. Giữ prefix `mgk_*`, KHÔNG rename.
2. **Đóng primitive vào order_items:** `qty` DECIMAL · `sell_unit` · **snapshot đầy đủ** (LUẬT 2).
3. **Khẳng định seam:** `item_type + item_ref_id` no-FK + index. FK ngành→core theo LUẬT 1.
4. **Dựng CI gate** (LUẬT 3) + **schema-version gate per-capability** (§3.2, tái dùng pattern `mgk_*_schema_version`).
5. **Acceptance (cổng quan trọng nhất):** ngoài luồng edu booking, đăng ký **một `item_type` thứ hai phi-booking** (vd một sellable phẳng) và cho nó chạy `đặt → trả → fulfill` qua **cùng** `mgk_core_orders` / `payments`. ✅ = lõi đã thật sự mù-ngành, không phải edu trá hình.

### 🟩 Phase 1 — Module ngành #2 = RETAIL (khi template retail đầu tiên xuất hiện)
1. Dựng capability `retail` **một lần**: `products`, `product_variants`, `categories`, `inventory` (+movements).
2. Cắm **3 seam**: `item_type='retail_variant'` · pricing resolver (`unit_price × qty`) · fulfillment hook (trừ kho).
3. **Validate đa dạng:** template **giày** (`sell_unit='piece'`, qty nguyên, `attributes_json={size}`) và **thịt** (`sell_unit='kg'`, qty=1.5) chạy trên **cùng một** module retail — **0 schema mới** cho meat-vs-shoe. Đạt = kiến trúc đúng.

### 🟪 Phase 2 — Tổng quát hóa BOOKING resource_* (khi resource phi-WP-post xuất hiện = spa/nhà hàng)
Đợi tới đây *có chủ đích* (xem §5). Không làm sớm.

### ⬜ Phase 3+ — Extension theo nhu cầu thật
`fnb` (topping/recipe), `food` (lô/HSD), `beauty`, `edu-exam`… mỗi cái dựng **một lần khi template đầu tiên của hình-dạng đó ép buộc**, dùng lại mãi.

---

## 5. CÚ MÌN: generalize edu = expand/contract, KHÔNG phải rename

Bảng booking edu đang **coupling cứng vào WP post**: `tutor_post_id` trỏ `mg_teacher`, "resource" = một WP post. Ngành khác (ghế spa, bàn nhà hàng) resource **không** phải WP post. Nên việc tổng quát hóa thực chất là **data-migration expand/contract**, không phải đổi tên cột:

```
EXPAND   : ADD COLUMN resource_type, resource_id (nullable). Giữ tutor_post_id.
BACKFILL : data edu cũ → resource_type='wp_post', resource_id=tutor_post_id.
DUAL-WRITE: code ghi cả cũ lẫn mới trong giai đoạn chuyển.
SHIFT    : chuyển mọi read sang resource_type/resource_id.
CONTRACT : ngừng dùng tutor_post_id (deprecate; không drop vì public-API + site standalone).
```

**Vì sao đợi ngành #2:** hình dạng `resource_*` chỉ *lộ ra đúng* khi một resource phi-WP-post ép nó. Generalize sớm = **đoán**, đoán sai chính là "đập đi xây lại" mà ta sợ. Đợi ở đây là *kỹ thuật*, không phải câu giờ.

---

## 6. Definition of Done — Phase 0 (cổng để bắt đầu Phase 1)

- [ ] Engine edu chạy qua Repository; **không còn** chỗ nào trong code template gọi `$wpdb` thẳng vào bảng giao dịch.
- [ ] `order_items` snapshot đủ để render hóa đơn + tính refund **khi xóa hàng gốc** (test thật: xóa course → đơn cũ vẫn đúng).
- [ ] `qty` DECIMAL + `sell_unit` có mặt; một đơn `qty=1.5` tính `line_total` đúng.
- [ ] Seam no-FK + index `(item_type, item_ref_id)`; FK ngành→core đúng LUẬT 1.
- [ ] **item_type thứ hai** chạy e2e qua cùng orders/payments (acceptance §4-Phase0).
- [ ] CI gate bật, cố tình thêm `CREATE TABLE` vào thư mục template → **build fail**.
- [ ] Schema-version gate per-capability hoạt động; re-activate không nhân đôi, không phá data.

---

## 7. Câu chốt

> **Ownership theo capability đóng vào tiền tố là XƯƠNG. Nó chỉ đứng vững nhờ 4 GÂN:**
> (a) lõi→sellable đa hình / mọi cái khác FK về lõi ·
> (b) `order_items` tự-đủ (orphan-survivable) ·
> (c) template chỉ qua Repository + CI chặn raw SQL ·
> (d) JSON không được thành cột-rác.
>
> Thiếu gân, cái xương đẹp vẫn gãy ở năm thứ hai. Build primitive hiển nhiên trước (qty thập phân, đơn vị, snapshot); để dành cấu trúc suy đoán cho tới khi template thật ép buộc; tổng quát hóa resource bằng expand/contract khi ngành #2 xuất hiện — không sớm hơn.
