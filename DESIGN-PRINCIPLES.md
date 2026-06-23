# Margick Commerce — Tư duy thiết kế Database (DESIGN PRINCIPLES)

> Tài liệu này giải thích **VÌ SAO** schema trông như vậy, không phải *nó là gì*.
> Dành cho dev mới và bất kỳ ai sắp sửa core — đọc trước khi đụng vào schema.
>
> Bộ tài liệu liên quan:
> - `DESIGN-PRINCIPLES.md` ← (file này) — **tại sao** (nguyên tắc, tư duy)
> - `margick-database-design.md` — **cái gì, chi tiết** (DDL theo nhóm, manifest, thứ tự build)
> - `margick-schema.sql` — **chạy được** (schema thuần custom, không gồm WordPress)

---

## 0. Luận điểm gốc

Thiết kế này **không** bắt đầu từ "cần bảng gì, cột nào". Nó bắt đầu từ một câu hỏi:

> *Hệ thống biến thiên theo những trục nào — và làm sao để các trục đó không đụng vào nhau?*

Bảng và cột chỉ là phần ngọn rơi ra từ câu trả lời. Có **bốn trục biến thiên độc lập**. Toàn bộ thiết kế là nỗ lực giữ chúng **vuông góc** với nhau.

| # | Trục | Câu hỏi cốt lõi | Ranh giới | Hiện thực trong schema |
|---|---|---|---|---|
| 1 | **Chung / Riêng** | Cái gì bất biến qua mọi ngành? | core ↔ module | `cc_core_*` vs `cc_<module>_*` |
| 2 | **Cô lập** | Ai sở hữu dữ liệu? | **tenant** (khách hàng/site) | per-site DB (Multisite prefix → shard) |
| 3 | **Hình thức** | Trông như thế nào? | template = **config** | manifest + `cc_builder_bindings` |
| 4 | **Nền tảng** | Xây trên gì? | WordPress ↔ custom | WP infra vs custom tables |

**Sai lầm gốc** — và là cái giết hầu hết kiến trúc đa ngành — là *gộp hai trục vào một ranh giới*. "Mỗi ngành một database" chính là gộp **trục 1** (khác biệt theo ngành) đè lên **trục 2** (cô lập dữ liệu). Khi hai trục dính nhau, sửa một bên (thêm cổng thanh toán) buộc phải sửa bên kia (mọi ngành). Giữ bốn trục tách rời = giữ chi phí thay đổi không lan.

---

## 1. Core vs Module — nhận diện cái bất biến

**Tư duy:** hỏi *cái gì đúng với mọi ngành?* Một đơn hàng là một đơn hàng, dù bán giày hay bán khóa học. Thanh toán là thanh toán. Khách hàng, giỏ hàng, mã giảm giá, thông báo — không quan tâm thứ được bán là gì. Đó là **commerce primitive**, bất biến → gom vào một **CORE định nghĩa một lần**.

Cái *thực sự khác nhau* là **thứ được bán và vòng đời của nó**: khóa học có enrollment + tiến độ + kỳ thi; sản phẩm có SKU + variant + tồn kho; đồ uống có topping + công thức. Cái đó → đẩy ra **MODULE theo ngành**.

**Quy tắc phân loại** không phải "thuộc ngành nào" mà là:

> Có thay đổi theo ngành không? *Không* → core. *Có* → module.

Đây cũng là cách commerce trưởng thành tư duy: hệ con order là industry-agnostic (Woo HPOS, Shopify đều tách order ra khỏi thứ được bán).

---

## 2. Mối nối đa hình (polymorphic seam) — và lý do nó "mềm"

Đây là quyết định **chịu lực nhất** của cả thiết kế.

Core phải tham chiếu được tới *bất kỳ* thực thể module nào, nhưng core **không được biết** module nào tồn tại — nếu core biết, chiều phụ thuộc đảo ngược và ta lại dính trục 1 vào core.

**Giải pháp:** `order_item` (và `cart_item`) trỏ đa hình bằng `item_type + item_ref_id` — một liên kết *mềm* do app ràng buộc, **không FK cứng**.

**Vì sao cố tình bỏ FK:**
- FK cứng buộc core phải biết mọi bảng module → đúng cái coupling cần tránh.
- Site chỉ bật một phần module → bảng đích có thể không tồn tại → FK cứng sẽ vỡ.

**Đánh đổi có chủ đích:** mất tính toàn vẹn tham chiếu ở tầng DB cho *riêng cột đó*, đổi lấy khả năng tách rời và mở rộng ngành vô hạn. Bù lại bằng ràng buộc ở app layer + index `(item_type, item_ref_id)`.

> ⚠️ Dev hay tưởng đây là thiếu sót và thêm FK vào → **sẽ vỡ**. Cột đa hình (`item_ref_id`, `ref_id`) **không bao giờ** có FK.

**Cùng tư duy đó → SNAPSHOT.** Lưu `name/price/sku` tại thời điểm mua thẳng vào order item. Lịch sử phải *bất biến* kể cả khi sản phẩm đổi giá sau này. Đây là phi-chuẩn-hóa có chủ đích: *đúng theo thời gian* quan trọng hơn *gọn về lý thuyết*.

---

## 3. Cô lập theo tenant — vẽ ranh giới ở đúng chỗ đau

**Tư duy:** ranh giới cô lập cứng phải nằm ở nơi tập trung nhu cầu bảo mật / backup / xóa theo yêu cầu / scale — và tất cả đều theo **khách hàng**, không bao giờ theo *ngành*.

- Ngành = thuộc tính *mềm* (site này bật module nào).
- Khách hàng = lằn ranh *cứng* → mỗi site một DB (Multisite prefix trước, HyperDB shard sau).

**Hệ quả đẹp:** chọn đúng trục này khiến câu hỏi "DB theo ngành" *tan biến* — trong một per-site DB, "ngành" chỉ còn là "site này có những bảng module nào". Và nó xử lý *miễn phí* khách đa ngành: quán cà phê bán hạt + mở lớp + bán bánh = **một site, ba module, một dòng đơn hàng, một danh tính khách** (không join chéo DB).

---

## 4. Template = config — tách hình thức khỏi cả code lẫn schema

**Tư duy:** con số 1000–2000 thuộc về *chiều trình bày*, phải cắt rời khỏi code và schema, nếu không công sức nổ tung.

**Nguyên tắc:** template **không mang logic, không mang bảng** — chỉ là `layout + style + sample data + manifest` (khai báo *cần module nào* + *bind dữ liệu ra sao*).

**Hệ quả trực tiếp:**

> Công sức code scale theo số **MODULE** (ít, ~6), **không** theo số **TEMPLATE** (vô hạn, rẻ).

**Kỷ luật giữ điều này đúng:** khoảnh khắc một template chứa một câu query hay một nhánh `if`, sự tách rời vỡ → logic bị *cấm* trong template về mặt thiết kế. Lớp binding (query khai báo bằng config, `cc_builder_bindings`) là thứ cho phép *một* renderer chung phục vụ *mọi* template.

---

## 5. WordPress vs Custom — lằn ranh dữ liệu giao dịch

**Tư duy:** "custom" **không** có nghĩa "viết lại tất cả". Cưỡi WordPress cho mọi hạ tầng đã được giải quyết tốt; chỉ giữ custom đúng phần dữ liệu giao dịch.

| Cưỡi WordPress | Giữ custom |
|---|---|
| Đa site/tenant, auth/phân quyền, media, content/SEO, builder/FSE, REST, cron, cache, CLI, dbDelta | Order · Payment · Cart · Inventory · Booking · Enrollment (bảng + logic) |

**Vì sao giao dịch phải custom:** cần query nhanh có index + transaction sạch — thứ mô hình EAV của `wp_postmeta` không kham nổi. Chính WooCommerce đã phải rời `postmeta` sang bảng riêng (HPOS).

**Lằn ranh là một nguyên tắc, không tùy hứng:**

> Content/biên tập → WordPress (CPT/postmeta). Giao dịch → custom table.

Chống lại cám dỗ nhét dữ liệu giao dịch ngược vào postmeta cũng là một phần của tư duy.

---

## 6. Hai tầng vật lý — hai loại dữ liệu, hai vòng đời

**Tư duy:** có hai loại dữ liệu khác bản chất.

- Dữ liệu *về nền tảng* (tenant, catalog template/module, billing, ledger migration) tồn tại **một lần, tập trung** → **control plane** (`margick_control`).
- Dữ liệu *nghiệp vụ thật* tồn tại **N lần, theo tenant** → **data plane** (per-site DB).

Trộn lại sẽ nhét mối lo nền tảng vào mọi site DB. Tách ra cho một điểm điều phối sạch — provision, đếm, migrate đều đứng ở một chỗ.

---

## 7. Quản trị thay đổi — thiết kế cho cái khó nhất từ ngày đầu

**Tư duy:** với N database, *tiến hóa schema* là bài toán vận hành khó nhất → thiết kế phải làm nó *khả thi ngay từ đầu*, không để dành.

- Mỗi module sở hữu bộ migration **có version**.
- Một **ledger** (`ctrl_migrations_ledger`) ghi "DB nào đã apply version nào" — idempotent, không chạy lại.
- Một runner quét qua tenant; **canary** vài site trước khi diện rộng.

**Luật sống còn:**

> Coi schema `cc_core` như một **PUBLIC API** — chỉ thêm, có version, **KHÔNG bao giờ phá vỡ**.

Vì hàng nghìn dependent (module + site) dựa vào nó. Tương thích-ngược là ràng buộc thiết kế hạng nhất, không phải việc nghĩ sau.

---

## 8. Quy ước cũng là tư duy

Mỗi quy ước mã hóa một nguyên tắc, không phải sở thích:

| Quy ước | Nguyên tắc nó bảo vệ |
|---|---|
| Tiền = `DECIMAL(15,2)`, không `FLOAT` | Đúng về tài chính |
| Enum = `VARCHAR` + comment, không kiểu `ENUM` | Tiến hóa không cần `ALTER` |
| `JSON` cho dữ liệu thưa/option, **không** cho thứ cần lọc | Giữ tính query-được |
| Snapshot tên/giá vào order item | Lịch sử bất biến theo thời gian |
| Index mọi FK + cột đa hình | Truy vấn được, truy ngược được |
| `wp_*` refs nullable, không FK | Schema độc lập, chạy standalone |

---

## 9. Phép thử kiến trúc (falsifiable tests)

Thiết kế tốt cho những phép thử *rẻ* để tự kiểm tra. Fail phép nào → biết ngay trục nào đang dính:

1. **Một retail core phục vụ giày + áo + đồ uống mà không sửa một dòng core** → fail thì **trục 1** (core/module) sai.
2. **Thêm template thứ 2000 = một folder config, zero migration** → fail thì **trục 3** (template) vỡ.
3. **Doanh thu khách đa ngành là một query, không phải join chéo DB** → fail thì **trục 2** (cô lập) vẽ sai chỗ.
4. **Thêm ngành mới = một module mới, zero thay đổi core** → fail thì coupling đã rò vào core.

---

## 10. Anti-patterns (đừng làm)

```text
✗ Database riêng cho mỗi ngành        → core bị nhân bản, khách đa ngành vỡ (gộp trục 1 vào 2)
✗ tenant_id ở mọi bảng (pooled)       → không hợp mô hình WP-per-site
✗ order/product/booking vào postmeta  → query chậm, không index (vi phạm lằn ranh trục 4)
✗ Logic riêng trong từng template      → mất khả năng scale 2000 (vỡ trục 3)
✗ FK thật trên cột đa hình             → không khả thi; ràng buộc ở app + index
✗ Lưu tiền bằng FLOAT                   → sai số tài chính
✗ Mỗi ngành một payment/coupon/customer → sửa 1 thứ phải sửa N nơi
✗ Thay đổi phá vỡ cc_core              → đập trúng toàn bộ module + site
```

---

## Kết

Thiết kế DB này **không phải một sơ đồ bảng** — nó là kết quả của việc giữ **bốn trục biến thiên** (chung/riêng · cô lập · hình thức · nền tảng) độc lập với nhau, nối lại bằng **một mối nối đa hình mềm**, và quản trị thay đổi như quản trị một **public API**.

Khi phân vân "đặt cái này ở đâu / thiết kế thế nào", quay lại hỏi: *quyết định này đang giữ bốn trục tách rời, hay đang gộp chúng lại?*
