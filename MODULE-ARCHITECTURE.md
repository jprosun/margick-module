# Margick — Kiến trúc MODULE dùng chung & cách ráp vào template

> **Mục đích:** giải thích trọn vẹn *ý tưởng + kiến trúc + cách dùng* của phương pháp "module dùng chung" — sao cho mỗi template ship ra là **một container WordPress standalone hoàn toàn**, mà vẫn **không phải viết/đập lại** phần lõi mỗi lần.
>
> Bộ tài liệu liên quan:
> - `DESIGN-PRINCIPLES.md` — **vì sao** (4 trục biến thiên, mối nối đa hình).
> - `TEMPLATE-BACKEND-PLAYBOOK.md` — **quy trình** DB/BE (3 tầng, 2 làn, luật chống rebuild).
> - `MODULE-ARCHITECTURE.md` ← (file này) — **module đóng gói & ráp vào template như thế nào**.
> - `margick-schema.sql` — north-star schema giao dịch.

Cập nhật: 2026-06-20

---

## 0. TL;DR — đọc 60 giây

1. **Module = code generic, sống MỘT chỗ** (`shared/`, có version). Template = **config mỏng** (tham số + data + giao diện). Module không nằm trong template ở dạng nguồn.
2. **"Link" chỉ tồn tại lúc author** (1 dòng khai báo trong manifest). **Build XÓA link bằng cách COPY file module vào** template → bake thành container.
3. **Container ship ra = standalone tuyệt đối** (WP + DB + plugin + module baked). Trên platform **không có link nào** ra ngoài. Đúng mô hình của bạn.
4. **Bạn không sửa module — bạn đút tham số cho nó.** Khác template = khác config, **không** khác code module.
5. **Dùng vào template mới:** cùng ngành = đổi *bộ mặt* (re-skin, backend 0 đụng); khác ngành = giữ *lõi giao dịch* + thay *áo ngành* + tham số mới.

---

## 1. Ý tưởng cốt lõi

### 1.1. Một nguồn, nhiều bản copy — copy do MÁY làm, không phải tay
Vấn đề gốc: nếu mỗi template **tự ôm một bản** module rồi sửa trong đó → N bản phân kỳ → sửa 1 bug phải sửa N nơi → "đập đi xây lại".

Lời giải: module có **đúng một nguồn sự thật** ở `shared/`. Mỗi container vẫn chứa **bản copy đầy đủ** của module (vì container standalone) — nhưng bản copy đó là **artifact do build tạo ra từ một nguồn có version**, không phải bản fork-bằng-tay.

> Build-once-ship-many, **không** copy-paste-and-edit.

### 1.2. "Hai trạng thái của cùng một thứ"

| | Module ↔ Template |
|---|---|
| **Ở nguồn (author, máy dev)** | TÁCH RỜI — nối bằng *link* (1 dòng khai báo version) |
| **Trong container (ship, platform)** | DÍNH LIỀN — build đã *bake* bản copy vào, không còn link |

Container trọn gói (đúng ý bạn) **+** một nguồn module (khỏi rebuild) → cây cầu nối là **pipeline build**.

### 1.3. Giống y cách WordPress bundle jQuery
WP core có sẵn jQuery trong `wp-includes/js/jquery/`. Mỗi bản WP tải về là **standalone** — không link ra ngoài. Nhưng team WP **maintain jQuery từ 1 nguồn upstream**, bake bản pinned vào mỗi release. Module của bạn = **y hệt**: 1 nguồn ở `shared/`, bake bản pinned vào mỗi container. Container không hề biết `shared/` tồn tại.

---

## 2. Kiến trúc tổng thể

```
MONOREPO (máy dev Margick — KHÔNG bao giờ ship)
├── shared/                         ← OUTPUT 1: thư viện module = "cục dùng chung", có version
│     margick-commerce/   (order · payment · cart · discount)   v1.2
│     margick-booking/                                          v1.0
│     margick-edu/        (course · tutor · enrolment)          v1.1
│     margick-beauty/     (service · staff)                     v0.9
│
├── templates/                      ← OUTPUT 2: config MỎNG mỗi template (KHÔNG chứa code module)
│     tutor-blue/
│        manifest.json   → modules: {commerce:1.2, booking:1.0, edu:1.1}
│        schemas/edu.php → compose + relabel + taxonomy
│        seam.php        → item_type + pricing resolver + fulfillment hook
│        seed-*.php      → data mẫu
│        tokens.css + layout
│     tutor-green/        (cùng ngành)
│     spa-luxe/           (khác ngành)
│              │
│              │  build-template.sh  (link → bake)
│              ▼
└── CONTAINERS                      ← OUTPUT 3: artifact ship lên platform (standalone trọn gói)
      tutor-blue.img   spa-luxe.img …   (WP + DB + plugin + module baked)
```

**3 output:**
1. **Thư viện module** (`shared/*`) — tài sản dùng lại, 1 bản, có version. Chỉ sống ở dev.
2. **Config template** (`templates/*`) — phần mỏng riêng từng template; *khai báo* module, không chứa code module.
3. **Container** — sản phẩm cuối ship lên platform; standalone hoàn toàn.

---

## 3. Vòng đời "link → bake → standalone"

| Giai đoạn | "Link" lúc này LÀ GÌ | Phụ thuộc ngoài? |
|---|---|---|
| **Author** (monorepo) | 1 dòng text trong manifest: `"commerce": "1.2"` — chỉ là **khai báo** | Không |
| **Build** (đóng gói) | script đọc dòng đó → **COPY** file PHP từ `shared/` vào template | Không (copy xong là rời nguồn) |
| **Ship** (container) | **KHÔNG còn link** — chỉ còn file PHP nằm sẵn trong WP | Không |
| **Run** (platform) | **KHÔNG có gì để link** — WP chạy file local, đứng một mình | Không — standalone 100% |

→ "Link" **sống và chết trên máy dev**. Tới container nó đã biến thành **file thật trong WP**. Bạn không đánh đổi tính standalone để lấy tái dùng — được cả hai, vì chúng ở hai thời điểm khác nhau.

---

## 4. Phân loại module (4 hạng — đã có trong `ctrl_module_catalog.category`)

| Hạng | Là gì | Cài ở site nào | Ví dụ |
|---|---|---|---|
| **core** | commerce primitive bất biến mọi ngành | **mọi** site | order, payment, cart, discount, customer, notification |
| **crosscut** | dùng lại bởi nhiều ngành, không phải tất cả | khi cần | **booking** (edu/beauty/fnb) |
| **industry** | sở hữu *thứ được bán + vòng đời* một ngành | 1 ngành | retail, beauty, edu |
| **extension** | mở rộng một industry khác, không copy | kèm ngành gốc | fnb (mở rộng retail) |

**Luật thiết kế bất di:**
- **core + crosscut thiết kế MÙ NGÀNH** — trỏ tới thứ được bán qua `item_type + item_ref_id` đa hình (no FK). Không bao giờ có `if (industry == 'edu')` trong core.
- **Mở rộng qua CONTRACT, không sửa core:** core *gọi ra* 3 điểm cắm, ngành *cài vào* → `ItemTypeRegistry`, `PricingResolver`, `FulfillmentHandler`.
- **Industry/extension CẮM VÀO core**, core không bao giờ import ngược → "thêm ngành mới = zero sửa core".

---

## 5. Thiết kế một module (library) — 6 kỷ luật

Module phải theo 6 kỷ luật này thì "library trước, plugin sau" mới không rebuild:

1. **PSR-4 namespace + autoload** — `Margick\Commerce\…`. Biến "đống include" thành library.
2. **Domain thuần PHP, WP ở rìa** — logic cốt (toán tiền, state machine, rules) là class PHP **không gọi hàm WP**; cái gì đụng WP (`$wpdb`, hook, option) nhét vào *adapter mỏng*.
3. **Include KHÔNG side-effect** — `require` chỉ *định nghĩa* class, **không** tự `add_action`. Gắn hook = một lời gọi `bootstrap()` tường minh.
4. **Phụ thuộc một chiều** — core-lib ⟵ industry-lib; core-lib **không biết WP**.
5. **Contract = interface** — `PricingResolver`, `FulfillmentHandler`, `PaymentGateway`, `Repository`. Core phụ thuộc interface, không phụ thuộc bản cài.
6. **Có version** — template pin được; biết container nào bake version nào.

**Anatomy thư mục một module:**
```
shared/margick-<cap>/
  composer.json                ← name, version, autoload PSR-4
  src/
    Domain/                    ← PHP thuần (không WP): rules, value objects, state machine
    Contracts/                 ← interface (seam)
    Wp/                        ← adapter: Repository ($wpdb), hooks, options, REST
    bootstrap.php              ← hàm bootstrap() tường minh (theme/plugin gọi)
  migrations/                  ← dbDelta nếu là T2 (additive + version)
  README.md                   ← version log + cách cài
```

> Khi gói thành plugin về sau: bê *nguyên* `shared/margick-<cap>/`, file plugin chính gọi *cùng* `bootstrap()` → zero rewrite.

---

## 6. Cơ chế build (link → container) — tới mức lệnh

```
shared/margick-commerce/   (v1.2)            ← NGUỒN (chỉ trên dev)
shared/margick-booking/    (v1.0)

templates/spa-luxe/manifest.json:
    "modules": { "commerce": "1.2", "booking": "1.0", "beauty": "0.9" }   ← "LINK" = đúng các dòng này

build-template.sh spa-luxe:
    1. đọc manifest.modules
    2. vendor: cp -r shared/margick-commerce@1.2 → templates/spa-luxe/wp-content/mu-plugins/
               cp -r shared/margick-booking@1.0  → …
               cp -r shared/margick-beauty@0.9   → …
    3. wp setup + chạy migrations từng module + seed DB (data mẫu)
    4. ghi bảng "module versions đã bake" vào site (để sau biết container nào cần update)
    5. docker build → spa-luxe.img         (file module ĐÃ nằm trong image, không link gì)
    6. push registry.margick.com → platform
```

- Bước (2) — cái `cp -r` — **chính là "liên kết"**: nó *giải* dòng khai báo thành file thật. Sau bước này không còn link.
- Vendor vào **mu-plugins** được khuyến nghị cho engine giao dịch (sống sót khi đổi theme); module T1 (CPT/ACF) có thể vào theme.
- Công cụ không bắt buộc là `cp` — có thể là composer (path repository) hoặc chính `new-template.sh` (đã clone từ master). Bản chất: *một nguồn + build tự lắp đúng version*.

> **Bạn ĐÃ làm kiểu này:** `new-template.sh` hiện clone cả master vào template mới = đã là copy-at-build, container đã standalone. Cải tiến duy nhất: code module sống ở `shared/` (1 nguồn, có version) thay vì mỗi clone tự ôm bản dễ sửa lệch.

---

## 7. ✦ Dùng module vào TỪNG template (phần quan trọng nhất)

Nguyên tắc nền: **bạn không sửa module — bạn đút tham số cho nó.** "Tham số" nằm ở `manifest` / `schemas/<cat>` / CPT-ACF / `options` / `seam.php` / `seed`. Code module y nguyên.

### 7.1. TH1 — Cùng ngành, chỉ đổi CSS/giao diện
Ví dụ: `tutor-blue` → `tutor-green` (vẫn gia sư, khác bộ mặt).

| Tái dùng 100% (KHÔNG đụng) | Chỉ thay đổi |
|---|---|
| `shared/*` (commerce, booking, edu) | `tokens.css` (màu/font/spacing) |
| `schemas/edu.php` (CPT, field, taxonomy) | Elementor Style từng phần tử |
| `seam.php` (item_type, pricing, fulfillment) | layout / thứ tự section |
| cấu trúc seed | logo + content copy mẫu |

**Các bước:**
1. `new-template.sh tutor-green edu`
2. Sửa `tokens.css` + layout Elementor + content seed.
3. Build → container.

→ **Backend đụng = 0.** Đây là **re-skin**, tính bằng giờ. Đúng cái 3-bề-mặt (CONTENT/DATA-SHELL/STYLE-SHELL) đã xây để mở.

### 7.2. TH2 — Khác hẳn ngành, tham số cũng khác
Ví dụ: `tutor` (gia sư) → `spa-luxe` (làm đẹp). **Không phải đổi hết** — tách 3 lớp:

| Lớp | Khác ngành thì sao |
|---|---|
| **core + crosscut** (commerce: order/payment/cart/discount · booking) | **TÁI DÙNG NGUYÊN** — phần đắt nhất, không viết lại |
| **industry module** (edu → **beauty**) | **SWAP** sang module ngành |
| **config + tham số + giao diện** | viết mới phần MỎNG (xem dưới) |

**Phần mỏng phải viết:**
1. `manifest.json` → `modules: {commerce, booking, beauty}`
2. `schemas/beauty.php` → CPT service/staff, taxonomy, **relabel** nhãn
3. `seam.php` → item_type `beauty_service` + pricing resolver (giá theo dịch vụ) + fulfillment hook (đặt lịch + giữ chỗ)
4. `seed-*.php` → vài dịch vụ + nhân viên mẫu
5. `tokens.css` + layout

**"Tham số khác" nằm ở đâu?** Ở config — `schemas/<cat>`, CPT/ACF, `options` (giá, hoa hồng, discount rules), seam callback. **KHÔNG ở code module.** Module đọc tham số từ ngoài → đút tham số khác, code y nguyên.

**Hai nhánh chi phí:**
- **Module beauty ĐÃ có** trong `shared/` → chỉ là **Làn A** (khai + seam + schema + seed + design). Gần như rẻ ngang TH1.
- **Module beauty CHƯA có** → build module beauty **một lần** (Làn B, có migration). Xong là xong vĩnh viễn — mọi template spa sau rớt về Làn A rẻ.

Dù là lần đầu, **vẫn không viết lại payment/cart/discount/booking** — tái dùng từ `shared/`.

### 7.3. So sánh nhanh

| | TH1 cùng ngành | TH2 khác ngành |
|---|---|---|
| core + commerce + booking | tái dùng | **tái dùng** |
| industry module | tái dùng | **swap** (sẵn → rẻ / chưa có → build 1 lần) |
| schemas / seam / tham số | gần như giữ | viết mới (mỏng) |
| giao diện (tokens/layout) | **đổi** | đổi |
| backend phải viết | **0** | chỉ phần ngành mỏng (nếu module sẵn) |
| Làn | A (re-skin) | A nếu module sẵn · B một lần nếu chưa |

**Một câu:** cùng ngành = đổi *bộ mặt*, giữ *cả cục*. Khác ngành = giữ *lõi giao dịch*, thay *áo ngành* + đút *tham số mới*. Phần đắt (payment/cart/discount/booking) **không bao giờ viết lại**.

---

## 8. Luật vàng giữ cho mô hình không thoái hóa

1. **Một nguồn module.** Sửa module → sửa ở `shared/`, **không bao giờ** mở bản đã bake trong template/container ra sửa.
2. **Custom riêng site = viết THÊM ở phần template** (item_type mới, callback thêm, migration riêng) — không đụng module chung. Nếu dùng chung được → đẩy ngược lên module (Làn B) + bump version.
3. **Version + ghi nhận.** Mỗi container ghi đã bake module version nào → fix bảo mật biết container nào cần rebuild.
4. **Schema module = public API:** chỉ thêm, có version, không phá vỡ (DESIGN-PRINCIPLES §7).
5. **Relabel ≠ fork.** Đa ngành = đổi nhãn/taxonomy/option, không sao chép logic.

---

## 9. Trạng thái hiện tại & bước kế

**Đã có (proven, là nguồn để extract — không viết mới):**
- Discount engine (`inc/pricing/`, `mgk_quote`) · Booking engine (`inc/booking/`) · Payment (Stripe thin-client) · `new-template.sh`/`bundle.sh` + Docker image pipeline (đã copy-at-build).

**Build được NGAY (đủ nghiệp vụ, generic sẵn):**
- Skeleton `shared/margick-commerce/` (composer + namespace + contracts + bootstrap).
- Module **Discount** đầy đủ (Money + domain thuần + WP adapter + interface + version).
- `build-template.sh` mẫu (link → bake) khớp pipeline hiện có.

**Đợi ngành #2 (để khỏi rebuild):**
- Generic hóa Commerce seam (Order/OrderItem đa hình) — cần phác thảo ngành #2 (bán gì / fulfillment / cách tính giá) để kiểm chứng seam không méo theo edu.
- Module ngành mới (retail/beauty/fnb) — build khi có nghiệp vụ ngành.

---

## Kết

Phương pháp này = **một nguồn module + build bake vào container standalone**. Bạn giữ nguyên tư duy "đóng gói trọn gói lên platform" — chỉ thêm kỷ luật *nguồn module ở một chỗ, có version*. Đổi lại: thêm template thứ 100 không phải viết lại lõi, và fix một bug lan tới mọi container qua một lần sửa + rebuild.
