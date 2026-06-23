# Margick — Thiết kế hệ thống DISCOUNT (tham khảo hệ thống thực tế)

> Trả lời 2 câu: (1) các nền tảng commerce thật mô hình hóa discount thế nào, áp vào ta ra sao; (2) discount là module **chung** hay **riêng**.

Cập nhật: 2026-06-22 · Liên quan: `MODULE-ARCHITECTURE.md`, `margick-modules/packages/margick-commerce/`.

---

## 1. Các hệ thống thật làm discount thế nào

| Hệ thống | Mô hình cốt lõi |
|---|---|
| **Shopify** | `PriceRule` + `DiscountCode`. value_type (percentage/fixed_amount), target (line_item/shipping), allocation (across/each), **prerequisites** (min subtotal, customer, prerequisite product/qty), **entitlements** (product/collection áp vào), usage limit, once_per_customer, **Discount Combinations** (product/order/shipping — mỗi loại có cờ "combines with"). Logic tùy biến = **Shopify Functions**. |
| **WooCommerce** | Coupon: type (percent / fixed_cart / fixed_product), usage_limit + per_user, **individual_use** (độc quyền, không stack), min/max spend, product/category include/exclude, exclude_sale_items, free_shipping, expiry. |
| **commercetools** | `CartDiscount` = **predicate** (DSL điều kiện) + **target** + **value** + **sortOrder** (ưu tiên, duy nhất) + **stackingMode** (`Stacking` \| `StopAfterThisDiscount`) + requiresDiscountCode. `DiscountCode` bọc cart-discount. Tách `ProductDiscount`. ← mô hình sạch & mạnh nhất. |
| **Stripe** | `Coupon` (percent_off/amount_off, duration) + `PromotionCode` (code + restrictions: minimum_amount, first_time_transaction, expires_at, max_redemptions). |
| **Medusa/Saleor** | Promotion = rules(conditions) + application method (target + allocation + value). |

**Điểm chung tất cả** → một promotion luôn tách thành **6 phần**:

```
1. SOURCE      tự động (rule) | theo mã (voucher/promo code)
2. CONDITION   khi nào đủ điều kiện? (min/max spend · segment/first-purchase · có item tiên quyết · cửa sổ ngày · usage limit global+per-customer)
3. TARGET      áp vào đâu? (cả đơn · line item nào · shipping)
4. EFFECT      giảm bao nhiêu? (% · số tiền cố định · giá cố định · tặng item/BOGO · free ship)
5. PRIORITY    nhiều cái cùng đủ → thứ tự áp (sortOrder)
6. STACKING    có cộng dồn? (stackable | exclusive/individual_use/StopAfter) + combination group + CAP tổng
```

---

## 2. Khoảng cách với `mgk_quote` hiện tại

| Có rồi | Thiếu (so best-practice) |
|---|---|
| headline (auto theo item_type) · loyalty (sibling/returning) · voucher (code) | discount kinds **hardcode** trong code |
| cap 25% · GST · conflict "best-for-customer" (BR-11) | điều kiện **hardcode** (sibling/returning) — không phải data |
| display===charge, frozen `discounts_applied` | không có **rule list** data-driven · không priority/target per-rule · stacking nghèo |

→ Hiện tại là **pipeline cứng**. Best-practice là **engine chạy theo RULE data-driven**.

---

## 3. Hướng nâng cấp: từ pipeline cứng → rule-based engine

Biến discount thành danh sách **DiscountRule** (DATA), engine chỉ *evaluate + combine*:

```
DiscountRule {
  id, source(auto|code), code?,
  condition: Predicate,      // min_spend | date_window | usage_limit | segment | custom(industry)
  target:    order|line|shipping,
  effect:    percent|fixed|fixed_price|free_item|free_ship,
  priority:  int,            // thứ tự áp (deterministic)
  stacking:  stackable|exclusive,
  caps:      max_amount?, per_customer_limit?, global_limit?
}
```

Engine (đã có nền tảng — `DiscountEngine`):
```
context (cart/customer/codes) + rules[]
  → lọc rule ĐỦ ĐIỀU KIỆN (eval condition)
  → sort theo priority
  → áp lần lượt, tôn trọng stacking/exclusive + CAP tổng
  → ra breakdown (rows + applied[] + total)   ← display===charge giữ nguyên
```

Đây đúng mô hình commercetools (predicate + target + value + sortOrder + stackingMode). `DiscountEngine` hiện tại đã làm bước **combine** (stack/cap/GST/conflict); việc còn lại là làm **rule list + condition** thành data + seam.

**Tiến hóa KHÔNG phá vỡ (incremental):**
1. *(đã xong)* engine combine = generic, parity byte-identical.
2. Đưa headline/loyalty/voucher hiện tại thành 3 **rule** trong một danh sách (vẫn như cũ về số).
3. Mở `mgk_discount_rules` (option) thành **rule list** admin sửa được (thêm rule % theo min-spend, theo ngày…).
4. Thêm seam `DiscountCondition` cho điều kiện ngành (sibling=edu, BOGO=fnb).

---

## 4. Discount là CHUNG hay RIÊNG?

**CHUNG (core) — nhưng có 3 lớp, mỗi lớp ở đúng chỗ:**

| Lớp | Chung/Riêng | Ở đâu |
|---|---|---|
| **Engine** (evaluate + stack + cap + GST + conflict) | **CHUNG (core)** | `margick-commerce` — bất biến mọi ngành |
| **Rule list** (% / fixed / voucher / min-spend / date / usage) | **CHUNG + CONFIG** | agency sửa ở wp-admin (option `mgk_discount_rules` → rule list); core đọc |
| **Điều kiện/Effect đặc thù ngành** (sibling, returning, BOGO topping, combo) | **RIÊNG (seam)** | industry module cài qua contract `DiscountCondition` / `DiscountEffect`; engine *gọi*, không hardcode |

> **Kết:** Discount = **module CHUNG** (core, `margick-commerce`). Rule = **config** agency tự sửa. Cái *riêng theo ngành* chỉ là **điều kiện/hiệu ứng exotic** cắm vào qua seam — **không** tách discount thành module riêng. Giống hệt cách Shopify/commercetools làm: nền tảng cung cấp engine + predicate vocabulary (chung), merchant cấu hình rule (config), logic lạ = function/extension (seam).

**Quy tắc vàng:** điều kiện ngành (sibling…) **không bao giờ** nằm trong core engine — nó là một `DiscountCondition` do module ngành cung cấp. Core chỉ biết "có một điều kiện trả true/false", không biết "sibling" là gì. Đó là điều giữ engine dùng được cho fashion/spa mà không sửa.

---

## 5. Không làm (anti-pattern)
- ❌ Hardcode loại discount / điều kiện trong engine core (vỡ tính đa ngành).
- ❌ Mỗi ngành một engine discount riêng (nhân bản → rebuild).
- ❌ Tính tiền hiển thị ≠ tiền charge (luôn cùng `mgk_quote`/engine).
- ❌ Stacking không cap (lỗ hổng giảm giá vô hạn).
