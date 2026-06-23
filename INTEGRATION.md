# Margick Modules — Cách áp module vào một template (cho dev tích hợp)

> **Đối tượng:** dev xây/maintain một template WordPress và muốn dùng module dùng chung
> (commerce, booking…) trong repo này.
> **Bộ tài liệu liên quan:** `MODULE-ARCHITECTURE.md` (kiến trúc đầy đủ) · `SCHEMA-AND-MIGRATIONS.md`
> (luật schema) · `DESIGN-PRINCIPLES.md` (vì sao) · `margick-schema.sql` (DDL tham chiếu).

---

## 0. Mô hình phải hiểu trước: **link → bake → standalone**

Module **không** phải dependency runtime. Quy trình:

```
khai báo version (manifest)  →  build COPY package vào wp-content/mu-plugins/  →  image chạy standalone
```

Tức "áp module" = **vendor (copy) code module vào template + nạp nó**. Tới container thì không
còn "link" gì nữa — chỉ là file PHP nằm sẵn trong WordPress.

**Yêu cầu:** PHP ≥ 8.1, WordPress. *Không* cần Composer lúc chạy (package có sẵn PSR-4
autoloader riêng); Composer chỉ tiện khi phát triển.

---

## 1. Vendor package vào template

Copy nguyên thư mục package từ repo vào `wp-content/mu-plugins/` của template. Dùng
**mu-plugins** cho engine giao dịch để nó sống sót khi đổi/đụng theme:

```bash
cp -r margick-modules/packages/margick-commerce \
      <template>/wp-content/mu-plugins/margick-commerce
```

> Nếu template dùng `new-template.sh` / `build-template.sh` của factory, bước này được tự
> động hoá từ `manifest.json` (`"modules": { "commerce": "0.5.0" }`). Làm tay thì chỉ là
> lệnh `cp` ở trên.

---

## 2. Thêm file loader ở **top-level** mu-plugins

WordPress **không** tự nạp file PHP nằm trong thư mục con của `mu-plugins` → cần một file
`.php` ở cấp trên cùng. Tạo `wp-content/mu-plugins/00-margick-commerce-loader.php`
(tiền tố `00-` để nạp sớm, trước các mu-plugin khác):

```php
<?php
/**
 * Plugin Name: Margick Commerce Loader (vendored)
 * Description: Loads the vendored margick/commerce package. DO NOT edit the
 *              package here — edit the source repo and re-vendor (build step).
 */
defined('ABSPATH') || exit;

$autoload = __DIR__ . '/margick-commerce/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
    if (function_exists('Margick\\Commerce\\bootstrap')) {
        \Margick\Commerce\bootstrap();   // wiring tường minh
    }
}
```

`autoload.php` tự đăng ký PSR-4 (`Margick\Commerce\ → src/`) và chạy được kể cả khi không có
Composer. Khi có Composer thì `vendor/autoload.php` thay thế — hành vi y hệt.

---

## 3. `bootstrap()` làm gì (dev **không** phải làm thêm)

Include chỉ *định nghĩa* class — **không** side-effect. Chỉ khi gọi `bootstrap()` mới wire:

- `add_action('init', SchemaMigrator::maybeMigrate, 5)` → **tự tạo/migrate bảng core** khi
  version bump (additive `dbDelta`). **Template không bao giờ tự tạo bảng.**
- Lên cron `mgk_voucher_cleanup` dọn voucher reservation hết hạn.

→ Sau bước này, các bảng tự sinh trong DB:
`{prefix}mgk_core_orders`, `{prefix}mgk_core_order_items`,
`{prefix}mgk_core_vouchers`, `{prefix}mgk_core_voucher_redemptions`.

---

## 4. Gọi API trong code theme/template

**Tính tiền (discount + voucher)** — ngành đút "ứng viên" vào, engine combine ra số tính tiền
(display === charge):

```php
use Margick\Commerce\Domain\Money;
use Margick\Commerce\Domain\Discount\{DiscountEngine, DiscountLine, QuoteRequest};

$r = (new DiscountEngine())->quote(new QuoteRequest(
    base:     Money::ofMajor(65, 'SGD'),
    headline: new DiscountLine('headline:trial', 'Trial 40%', 40, Money::ofMajor(25, 'SGD')),
    loyalty:  [ new DiscountLine('sibling', 'Sibling', 3, Money::ofMajor(1.20, 'SGD')) ],
    capPct: 25, gstPct: 9, gstInclusive: true, lineLabel: 'Trial lesson'
));
$r->total->toMajor();      // số tiền tính thật
$r->appliedToArray();      // lưu đông cứng vào order.discount_applied
```

| Cần gì | Dùng API |
|---|---|
| Đọc/ghi đơn hàng core | `Wp\OrderRepository` (KHÔNG `$wpdb` thô vào `mgk_core_*`) |
| Voucher lifecycle | `Wp\VoucherRepository` — `reserve / consume / release`, an toàn đồng thời |
| Luật giảm giá của site | option `mgk_discount_rules` (đọc bởi `WpDiscountRulesProvider`) |

---

## 5. Cắm phần riêng của ngành qua **SEAM** (không sửa module)

Module *gọi ra* các interface trong `src/Contracts`; ngành/template *cài vào*:

| Seam (interface) | Template cài gì |
|---|---|
| `PricingResolver` | giá theo thực thể ngành (course / service / variant) |
| `FulfillmentHandler` | trả tiền xong làm gì: ghi danh / đặt lịch / xuất kho |
| `PaymentGateway` | cổng thanh toán (Stripe — confirm **chỉ** từ webhook) |
| `DiscountRulesProvider` | nguồn luật giảm giá |

Khai `item_type` của ngành (`edu_course`, `beauty_service`, `retail_variant`…) ở lớp template.
**Code module giữ nguyên** — chỉ đút tham số/callback khác. Đây là chỗ mỗi template khác nhau.

---

## 6. Qua cổng ranh giới (bắt buộc — nên gắn CI)

```bash
margick-modules/bin/check-template-boundaries.sh  <theme-or-template-dir>
```

Gate **fail build** nếu template vi phạm:

- **RULE 1** — không `CREATE/ALTER TABLE` trong lớp template (schema thuộc module).
- **RULE 2** — không đụng `mgk_core_*` bằng `$wpdb` thô; **chỉ** qua Repository.

---

## 7. Test nhanh

```bash
php  packages/margick-commerce/tests/DiscountEngineTest.php       # pure PHP, không cần WP
php  packages/margick-commerce/tests/VoucherValidatorTest.php     # pure voucher rules
wp eval-file packages/margick-commerce/tests/VoucherRepositoryWpTest.php   # DB (tự dọn)
```

---

## ⚠️ 3 luật vàng

1. **KHÔNG sửa bản vendored** trong `mu-plugins/margick-commerce/` — sửa ở repo nguồn, bump
   `VERSION` + `composer.json`, rồi re-vendor.
2. **Custom riêng site = viết THÊM ở lớp template** (item_type mới, callback, migration riêng
   của ngành). Dùng chung được thì đẩy ngược lên module (bump version), **đừng fork**.
3. **Pin & ghi version** module đã bake vào từng container — để biết site nào cần rebuild khi có fix.

---

**Một câu cho dev:** copy package vào `mu-plugins`, thêm loader gọi `bootstrap()`, gọi
`DiscountEngine` / `OrderRepository` / `VoucherRepository` qua API, cắm phần ngành qua các
interface `Contracts`, và **đừng bao giờ** tự đụng bảng `mgk_core_*` hay sửa bản đã vendored.
