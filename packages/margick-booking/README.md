# margick/booking

**Crosscut** module — reused by several industries (edu · beauty · fnb table-reservation), **not** every site. Installed per-template via manifest; never baked into a pure-retail template.

> Hạng module: `crosscut` (xem `MODULE-ARCHITECTURE.md` §4, `ctrl_module_catalog`). Không phải core (không ép mọi site), không phải industry (không khóa 1 ngành).

## Trạng thái (v0.1.0)
- `src/Domain/SlotMath.php` — **PURE** block-expansion + slot-key (toán thời gian; nền của lock chống overbooking). `blockMinutes` là tham số (bỏ hằng `MGK_BLOCK_MINUTES`). Test 8/8.

## CHƯA extract (cố ý — đợi ngành #2, tránh rebuild)
- **Atomic hold engine** — dựa `$wpdb` transaction + UNIQUE index; *atomicity chính là DB*, không thể thành class thuần. Hiện ở edu `inc/booking/booking-locks.php`.
- **Bảng bookings/locks/events** — đang edu-shaped (`tutor_post_id`).
- **Tổng quát resource** `tutor → resource_type/resource_id` (đa hình) — cần ngành thứ 2 (spa staff / bàn nhà hàng) kiểm chứng seam trước khi đóng băng.

## Vì sao chỉ extract SlotMath bây giờ
Khác Discount/Payment-mechanics (thuần, tách dễ), giá trị lõi của Booking là *concurrency DB-coupled*. Theo kỷ luật chống rebuild: extract phần thuần proven ngay (SlotMath), hoãn phần đa-hình tới khi có ngành thật ép hình dạng.
