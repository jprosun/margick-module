-- =====================================================================
-- MARGICK COMMERCE — DATABASE SCHEMA (lớp custom, KHÔNG gồm WordPress)
-- Engine: InnoDB · Charset: utf8mb4 · Target: MySQL 8.0.16+ / MariaDB 10.4+
--
-- 2 cơ sở dữ liệu tách biệt:
--   (A) margick_control        — 1 instance trung tâm: registry tenant, catalog
--                                template/module, billing, ledger migration.
--   (B) margick_site_template  — KHUÔN cho per-site DB (SUPERSET đủ mọi module).
--                                Provisioner tạo per-site DB gồm core + SUBSET module
--                                mà tenant cần (theo ctrl_template_modules / manifest).
--
-- QUY ƯỚC (bắt buộc):
--   • PK         : id BIGINT UNSIGNED AUTO_INCREMENT
--   • Tiền       : DECIMAL(15,2) + cột currency. TUYỆT ĐỐI không FLOAT.
--   • Enum       : VARCHAR + comment liệt kê giá trị (thêm state khỏi ALTER).
--   • JSON       : snapshot / options / config.
--   • Thời gian  : created_at, updated_at. Soft-delete = deleted_at (nơi cần).
--   • FK         : có ở quan hệ chính; MỌI cột FK đều có index.
--                  Cột ĐA HÌNH (item_ref_id, ref_id) KHÔNG có FK — ràng buộc ở app + index.
--   • WP refs    : wp_user_id / *_media_id / content_ref = ref ngoài tới WordPress,
--                  NULLABLE, KHÔNG FK (giữ schema này độc lập, chạy standalone được).
--   • Tạo module : module phụ thuộc nhau (vd fnb cần retail) — tạo theo đúng thứ tự deps.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;


-- #####################################################################
-- (A) CONTROL PLANE DB
-- #####################################################################
CREATE DATABASE IF NOT EXISTS margick_control
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE margick_control;

-- Gói dịch vụ
CREATE TABLE ctrl_plans (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(40)  NOT NULL,
  name          VARCHAR(120) NOT NULL,
  price_monthly DECIMAL(15,2) NOT NULL DEFAULT 0,
  currency      CHAR(3) NOT NULL DEFAULT 'VND',
  limits_json   JSON NULL,                       -- giới hạn: số sản phẩm, dung lượng, module cho phép
  active        TINYINT(1) NOT NULL DEFAULT 1,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Catalog template (1000–2000 template, chỉ là config)
CREATE TABLE ctrl_template_catalog (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  template_code VARCHAR(80)  NOT NULL,           -- vd: milk_tea_001, tutor_001
  title         VARCHAR(191) NOT NULL,
  industry      VARCHAR(32)  NOT NULL,           -- retail|fnb|beauty|edu
  version       VARCHAR(20)  NOT NULL DEFAULT '1.0.0',
  status        VARCHAR(32)  NOT NULL DEFAULT 'draft',   -- draft|published|deprecated
  manifest_json JSON         NOT NULL,           -- required_modules, pages, bindings, sample_data
  asset_uri     VARCHAR(255) NULL,
  thumbnail_uri VARCHAR(255) NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at    TIMESTAMP NULL,
  UNIQUE KEY uq_code_ver (template_code, version),
  KEY idx_industry_status (industry, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Registry module + version + nguồn migration
CREATE TABLE ctrl_module_catalog (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  module_key     VARCHAR(40)  NOT NULL,          -- core|retail|fnb|beauty|edu|booking
  title          VARCHAR(120) NOT NULL,
  category       VARCHAR(32)  NOT NULL,          -- core|industry|extension|crosscut
  latest_version VARCHAR(20)  NOT NULL,
  depends_on     JSON NULL,                      -- ["core"] / ["core","retail"] ...
  migrations_uri VARCHAR(255) NOT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_module (module_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tenant (site khách hàng)
CREATE TABLE ctrl_tenants (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(191) NOT NULL,
  slug          VARCHAR(120) NOT NULL,           -- subdomain / định danh
  db_dsn        VARCHAR(255) NOT NULL,           -- DSN/tên DB hoặc table-prefix của site
  industry_main VARCHAR(32)  NULL,
  plan_id       BIGINT UNSIGNED NULL,
  status        VARCHAR(32)  NOT NULL DEFAULT 'provisioning', -- provisioning|active|suspended|deleted
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_slug (slug),
  KEY idx_status (status),
  KEY idx_plan (plan_id),
  CONSTRAINT fk_tenant_plan FOREIGN KEY (plan_id) REFERENCES ctrl_plans(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ctrl_subscriptions (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id          BIGINT UNSIGNED NOT NULL,
  plan_id            BIGINT UNSIGNED NOT NULL,
  status             VARCHAR(20) NOT NULL DEFAULT 'active', -- active|past_due|cancelled
  started_at         TIMESTAMP NULL,
  current_period_end TIMESTAMP NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tenant (tenant_id),
  KEY idx_plan (plan_id),
  CONSTRAINT fk_sub_tenant FOREIGN KEY (tenant_id) REFERENCES ctrl_tenants(id),
  CONSTRAINT fk_sub_plan   FOREIGN KEY (plan_id)   REFERENCES ctrl_plans(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- M:N template ↔ module (quan hệ chuẩn hóa; manifest_json là bản dày đủ)
CREATE TABLE ctrl_template_modules (
  template_id BIGINT UNSIGNED NOT NULL,
  module_key  VARCHAR(40) NOT NULL,
  min_version VARCHAR(20) NULL,
  PRIMARY KEY (template_id, module_key),
  KEY idx_module (module_key),
  CONSTRAINT fk_tm_template FOREIGN KEY (template_id) REFERENCES ctrl_template_catalog(id),
  CONSTRAINT fk_tm_module   FOREIGN KEY (module_key)  REFERENCES ctrl_module_catalog(module_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Hàng đợi provisioning (tạo site từ template)
CREATE TABLE ctrl_provisioning_jobs (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  template_code VARCHAR(80) NOT NULL,
  status        VARCHAR(32) NOT NULL DEFAULT 'queued', -- queued|running|done|failed
  steps_json    JSON NULL,
  error         TEXT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_tenant (tenant_id),
  KEY idx_status (status),
  CONSTRAINT fk_job_tenant FOREIGN KEY (tenant_id) REFERENCES ctrl_tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ★ LEDGER MIGRATION — nguồn sự thật: DB nào đã apply version nào (chìa khóa scale N DB)
CREATE TABLE ctrl_migrations_ledger (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id  BIGINT UNSIGNED NOT NULL,
  module_key VARCHAR(40) NOT NULL,
  version    VARCHAR(20) NOT NULL,
  status     VARCHAR(32) NOT NULL DEFAULT 'applied', -- applied|failed|rolled_back
  applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tenant_module_ver (tenant_id, module_key, version),
  KEY idx_tenant_module (tenant_id, module_key),
  CONSTRAINT fk_ledger_tenant FOREIGN KEY (tenant_id) REFERENCES ctrl_tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --- Reference data: đăng ký các module + quan hệ phụ thuộc ---
INSERT INTO ctrl_module_catalog (module_key, title, category, latest_version, depends_on, migrations_uri) VALUES
  ('core',    'Commerce Core',   'core',      '1.0.0', JSON_ARRAY(),                 'modules/core/migrations'),
  ('retail',  'Retail',          'industry',  '1.0.0', JSON_ARRAY('core'),           'modules/retail/migrations'),
  ('fnb',     'F&B Extension',   'extension', '1.0.0', JSON_ARRAY('core','retail'),  'modules/fnb/migrations'),
  ('beauty',  'Beauty',          'industry',  '1.0.0', JSON_ARRAY('core','booking'), 'modules/beauty/migrations'),
  ('edu',     'Education',       'industry',  '1.0.0', JSON_ARRAY('core','booking'), 'modules/edu/migrations'),
  ('booking', 'Booking',         'crosscut',  '1.0.0', JSON_ARRAY('core'),           'modules/booking/migrations');


-- #####################################################################
-- (B) PER-SITE TEMPLATE DB  (superset: core + tất cả module)
-- #####################################################################
CREATE DATABASE IF NOT EXISTS margick_site_template
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE margick_site_template;

-- =====================  COMMERCE CORE (cc_core_*)  ===================
-- Dùng chung cho MỌI ngành. Cài cho MỌI site.

CREATE TABLE cc_core_customers (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  wp_user_id    BIGINT UNSIGNED NULL,            -- ref ngoài tới WP user (no FK)
  email         VARCHAR(191) NULL,
  phone         VARCHAR(32)  NULL,
  full_name     VARCHAR(191) NULL,
  password_hash VARCHAR(255) NULL,               -- NULL nếu guest/social
  locale        VARCHAR(10)  NULL DEFAULT 'vi',
  status        VARCHAR(32)  NOT NULL DEFAULT 'active', -- active|blocked|guest
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at    TIMESTAMP NULL,
  KEY idx_email (email),
  KEY idx_phone (phone),
  KEY idx_wp_user (wp_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cc_core_addresses (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id BIGINT UNSIGNED NOT NULL,
  type        VARCHAR(16) NOT NULL DEFAULT 'shipping', -- shipping|billing
  recipient   VARCHAR(191) NULL,
  phone       VARCHAR(32)  NULL,
  line1       VARCHAR(255) NULL,
  ward        VARCHAR(120) NULL,
  district    VARCHAR(120) NULL,
  province    VARCHAR(120) NULL,
  country     CHAR(2) NOT NULL DEFAULT 'VN',
  postal_code VARCHAR(20) NULL,
  is_default  TINYINT(1) NOT NULL DEFAULT 0,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_customer (customer_id),
  CONSTRAINT fk_addr_customer FOREIGN KEY (customer_id) REFERENCES cc_core_customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cc_core_coupons (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code            VARCHAR(64) NOT NULL,
  type            VARCHAR(20) NOT NULL,           -- percent|fixed|free_ship
  value           DECIMAL(15,2) NOT NULL DEFAULT 0,
  min_order       DECIMAL(15,2) NULL,
  usage_limit     INT NULL,
  usage_per_cust  INT NULL,
  used_count      INT NOT NULL DEFAULT 0,
  applies_to_json JSON NULL,                      -- phạm vi: product/category/module
  starts_at       TIMESTAMP NULL,
  ends_at         TIMESTAMP NULL,
  status          VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cc_core_carts (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id    BIGINT UNSIGNED NULL,            -- NULL = khách vãng lai
  session_token  VARCHAR(64) NULL,
  currency       CHAR(3) NOT NULL DEFAULT 'VND',
  status         VARCHAR(20) NOT NULL DEFAULT 'active', -- active|converted|abandoned
  subtotal       DECIMAL(15,2) NOT NULL DEFAULT 0,
  discount_total DECIMAL(15,2) NOT NULL DEFAULT 0,
  tax_total      DECIMAL(15,2) NOT NULL DEFAULT 0,
  shipping_total DECIMAL(15,2) NOT NULL DEFAULT 0,
  grand_total    DECIMAL(15,2) NOT NULL DEFAULT 0,
  expires_at     TIMESTAMP NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_customer (customer_id),
  KEY idx_session (session_token),
  KEY idx_status (status),
  CONSTRAINT fk_cart_customer FOREIGN KEY (customer_id) REFERENCES cc_core_customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cc_core_cart_items (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cart_id       BIGINT UNSIGNED NOT NULL,
  item_type     VARCHAR(40) NOT NULL,             -- retail_variant|fnb_product|edu_course|edu_exam|booking_slot|beauty_service
  item_ref_id   BIGINT UNSIGNED NOT NULL,         -- ĐA HÌNH: trỏ tới bảng module (no FK)
  name_snapshot VARCHAR(191) NULL,
  unit_price    DECIMAL(15,2) NOT NULL DEFAULT 0,
  qty           INT NOT NULL DEFAULT 1,
  options_json  JSON NULL,                        -- topping, size, slot, addon
  line_total    DECIMAL(15,2) NOT NULL DEFAULT 0,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cart (cart_id),
  KEY idx_ref (item_type, item_ref_id),
  CONSTRAINT fk_cart_item_cart FOREIGN KEY (cart_id) REFERENCES cc_core_carts(id) ON DELETE CASCADE,
  CONSTRAINT chk_cart_qty CHECK (qty > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cc_core_orders (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_no        VARCHAR(32) NOT NULL,
  customer_id     BIGINT UNSIGNED NULL,
  status          VARCHAR(32) NOT NULL DEFAULT 'pending', -- pending|paid|processing|completed|cancelled|refunded
  currency        CHAR(3) NOT NULL DEFAULT 'VND',
  subtotal        DECIMAL(15,2) NOT NULL DEFAULT 0,
  discount_total  DECIMAL(15,2) NOT NULL DEFAULT 0,
  tax_total       DECIMAL(15,2) NOT NULL DEFAULT 0,
  shipping_total  DECIMAL(15,2) NOT NULL DEFAULT 0,
  grand_total     DECIMAL(15,2) NOT NULL DEFAULT 0,
  coupon_id       BIGINT UNSIGNED NULL,
  billing_json    JSON NULL,                       -- snapshot địa chỉ
  shipping_json   JSON NULL,
  source_template VARCHAR(80) NULL,                -- template_code đang dùng (truy vết kênh)
  note            TEXT NULL,
  placed_at       TIMESTAMP NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_order_no (order_no),
  KEY idx_customer (customer_id),
  KEY idx_status (status),
  KEY idx_placed (placed_at),
  KEY idx_coupon (coupon_id),
  CONSTRAINT fk_order_customer FOREIGN KEY (customer_id) REFERENCES cc_core_customers(id),
  CONSTRAINT fk_order_coupon   FOREIGN KEY (coupon_id)   REFERENCES cc_core_coupons(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ★★★ MỐI NỐI CORE ↔ MODULE ★★★ (polymorphic order item)
CREATE TABLE cc_core_order_items (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id       BIGINT UNSIGNED NOT NULL,
  item_type      VARCHAR(40) NOT NULL,            -- xem danh sách ở cart_items
  item_ref_id    BIGINT UNSIGNED NOT NULL,        -- ĐA HÌNH: id thực thể ở bảng module (no FK)
  sku_snapshot   VARCHAR(64) NULL,
  name_snapshot  VARCHAR(191) NULL,
  unit_price     DECIMAL(15,2) NOT NULL DEFAULT 0,
  qty            INT NOT NULL DEFAULT 1,
  options_json   JSON NULL,                       -- lựa chọn tại thời điểm mua
  line_total     DECIMAL(15,2) NOT NULL DEFAULT 0,
  fulfill_status VARCHAR(32) NOT NULL DEFAULT 'pending', -- pending|fulfilled|cancelled
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_order (order_id),
  KEY idx_ref (item_type, item_ref_id),           -- truy ngược về module
  CONSTRAINT fk_order_item_order FOREIGN KEY (order_id) REFERENCES cc_core_orders(id),
  CONSTRAINT chk_order_qty CHECK (qty > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cc_core_payments (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id     BIGINT UNSIGNED NOT NULL,
  gateway      VARCHAR(40) NOT NULL,              -- vnpay|momo|zalopay|stripe|cod|bank_transfer
  method       VARCHAR(40) NULL,
  amount       DECIMAL(15,2) NOT NULL,
  currency     CHAR(3) NOT NULL DEFAULT 'VND',
  status       VARCHAR(32) NOT NULL DEFAULT 'pending', -- pending|authorized|captured|failed|refunded
  txn_ref      VARCHAR(128) NULL,                 -- mã giao dịch phía cổng
  payload_json JSON NULL,                         -- request/response cổng
  captured_at  TIMESTAMP NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_order (order_id),
  KEY idx_status (status),
  UNIQUE KEY uq_gateway_txn (gateway, txn_ref),   -- chống double-callback (idempotency); NULL cho phép nhiều
  CONSTRAINT fk_payment_order FOREIGN KEY (order_id) REFERENCES cc_core_orders(id),
  CONSTRAINT chk_payment_amount CHECK (amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cc_core_refunds (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  payment_id BIGINT UNSIGNED NOT NULL,
  order_id   BIGINT UNSIGNED NOT NULL,
  amount     DECIMAL(15,2) NOT NULL,
  reason     VARCHAR(255) NULL,
  status     VARCHAR(32) NOT NULL DEFAULT 'pending', -- pending|done|failed
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_payment (payment_id),
  KEY idx_order (order_id),
  CONSTRAINT fk_refund_payment FOREIGN KEY (payment_id) REFERENCES cc_core_payments(id),
  CONSTRAINT fk_refund_order   FOREIGN KEY (order_id)   REFERENCES cc_core_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cc_core_coupon_redemptions (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  coupon_id   BIGINT UNSIGNED NOT NULL,
  order_id    BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NULL,
  amount      DECIMAL(15,2) NOT NULL DEFAULT 0,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_coupon (coupon_id),
  KEY idx_order (order_id),
  CONSTRAINT fk_redeem_coupon FOREIGN KEY (coupon_id) REFERENCES cc_core_coupons(id),
  CONSTRAINT fk_redeem_order  FOREIGN KEY (order_id)  REFERENCES cc_core_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cc_core_shipping_methods (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(40) NOT NULL,
  name        VARCHAR(120) NOT NULL,
  calc_type   VARCHAR(20) NOT NULL DEFAULT 'flat', -- flat|weight|zone|free
  config_json JSON NULL,
  active      TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cc_core_tax_rates (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(120) NOT NULL,
  rate            DECIMAL(6,4) NOT NULL DEFAULT 0,  -- 0.1000 = 10%
  country         CHAR(2) NULL,
  inclusive       TINYINT(1) NOT NULL DEFAULT 1,
  applies_to_json JSON NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cc_core_notifications (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id  BIGINT UNSIGNED NULL,
  channel      VARCHAR(20) NOT NULL,              -- email|sms|zalo|push
  template_key VARCHAR(80) NOT NULL,
  payload_json JSON NULL,
  status       VARCHAR(20) NOT NULL DEFAULT 'queued', -- queued|sent|failed
  sent_at      TIMESTAMP NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_customer (customer_id),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Lịch sử chuyển trạng thái GENERIC (order/payment/booking/enrollment ...) — polymorphic, no FK
CREATE TABLE cc_core_workflow_transitions (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entity_type VARCHAR(40) NOT NULL,               -- order|payment|booking|enrollment ...
  entity_id   BIGINT UNSIGNED NOT NULL,
  from_state  VARCHAR(32) NULL,
  to_state    VARCHAR(32) NOT NULL,
  actor_type  VARCHAR(20) NULL,                   -- system|admin|customer
  actor_id    BIGINT UNSIGNED NULL,
  reason      VARCHAR(255) NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Module nào đang bật trên site này (mirror cục bộ của provisioning)
CREATE TABLE cc_core_modules (
  module_key   VARCHAR(40) NOT NULL PRIMARY KEY,  -- retail|fnb|beauty|edu|booking
  version      VARCHAR(20) NOT NULL,
  status       VARCHAR(20) NOT NULL DEFAULT 'active', -- active|inactive
  config_json  JSON NULL,
  activated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cc_core_settings (
  setting_key VARCHAR(120) NOT NULL PRIMARY KEY,
  value_json  JSON NULL,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- =====================  MODULE RETAIL (cc_retail_*)  =================
-- Phục vụ quần áo / giày / đồ uống. F&B & Beauty mở rộng lên trên.

CREATE TABLE cc_retail_brands (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(191) NOT NULL,
  slug          VARCHAR(191) NOT NULL,
  logo_media_id BIGINT UNSIGNED NULL,             -- ref media WP (no FK)
  KEY idx_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cc_retail_categories (
  id        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  parent_id BIGINT UNSIGNED NULL,
  name      VARCHAR(191) NOT NULL,
  slug      VARCHAR(191) NOT NULL,
  position  INT NOT NULL DEFAULT 0,
  KEY idx_parent (parent_id),
  KEY idx_slug (slug),
  CONSTRAINT fk_cat_parent FOREIGN KEY (parent_id) REFERENCES cc_retail_categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cc_retail_products (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type        VARCHAR(20) NOT NULL DEFAULT 'simple', -- simple|variable
  title       VARCHAR(191) NOT NULL,
  slug        VARCHAR(191) NOT NULL,
  description MEDIUMTEXT NULL,
  brand_id    BIGINT UNSIGNED NULL,
  base_price  DECIMAL(15,2) NULL,                 -- với simple; variable lấy theo variant
  tax_class   VARCHAR(40) NULL,
  status      VARCHAR(20) NOT NULL DEFAULT 'draft', -- draft|published|archived
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at  TIMESTAMP NULL,
  KEY idx_slug (slug),
  KEY idx_status (status),
  KEY idx_brand (brand_id),
  CONSTRAINT fk_product_brand FOREIGN KEY (brand_id) REFERENCES cc_retail_brands(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cc_retail_product_variants (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id      BIGINT UNSIGNED NOT NULL,
  sku             VARCHAR(64) NOT NULL,
  attributes_json JSON NULL,                      -- {"size":"M","color":"đỏ"}
  price           DECIMAL(15,2) NOT NULL,
  compare_at      DECIMAL(15,2) NULL,
  weight_gram     INT NULL,
  barcode         VARCHAR(64) NULL,
  status          VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at      TIMESTAMP NULL,
  UNIQUE KEY uq_sku (sku),
  KEY idx_product (product_id),
  CONSTRAINT fk_variant_product FOREIGN KEY (product_id) REFERENCES cc_retail_products(id),
  CONSTRAINT chk_variant_price CHECK (price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cc_retail_product_categories (
  product_id  BIGINT UNSIGNED NOT NULL,
  category_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (product_id, category_id),
  KEY idx_category (category_id),
  CONSTRAINT fk_pc_product  FOREIGN KEY (product_id)  REFERENCES cc_retail_products(id)   ON DELETE CASCADE,
  CONSTRAINT fk_pc_category FOREIGN KEY (category_id) REFERENCES cc_retail_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cc_retail_attributes (
  id   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL,                      -- size|color|material
  name VARCHAR(120) NOT NULL,
  UNIQUE KEY uq_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cc_retail_attribute_values (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  attribute_id BIGINT UNSIGNED NOT NULL,
  value        VARCHAR(120) NOT NULL,
  position     INT NOT NULL DEFAULT 0,
  KEY idx_attribute (attribute_id),
  CONSTRAINT fk_attrval_attr FOREIGN KEY (attribute_id) REFERENCES cc_retail_attributes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cc_retail_locations (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(191) NOT NULL,
  type         VARCHAR(20) NOT NULL DEFAULT 'warehouse', -- warehouse|store
  address_json JSON NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cc_retail_inventory (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  variant_id    BIGINT UNSIGNED NOT NULL,
  location_id   BIGINT UNSIGNED NULL,
  qty_on_hand   INT NOT NULL DEFAULT 0,
  qty_reserved  INT NOT NULL DEFAULT 0,
  reorder_point INT NULL,
  track         TINYINT(1) NOT NULL DEFAULT 1,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_variant_loc (variant_id, location_id),
  KEY idx_variant (variant_id),
  CONSTRAINT fk_inv_variant  FOREIGN KEY (variant_id)  REFERENCES cc_retail_product_variants(id) ON DELETE CASCADE,
  CONSTRAINT fk_inv_location FOREIGN KEY (location_id) REFERENCES cc_retail_locations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cc_retail_inventory_movements (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  variant_id BIGINT UNSIGNED NOT NULL,
  delta      INT NOT NULL,                        -- +nhập / -xuất
  reason     VARCHAR(40) NOT NULL,                -- sale|restock|adjust|return
  ref_type   VARCHAR(40) NULL,                    -- order|manual ...
  ref_id     BIGINT UNSIGNED NULL,                -- ĐA HÌNH (no FK)
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_variant (variant_id),
  CONSTRAINT fk_invmov_variant FOREIGN KEY (variant_id) REFERENCES cc_retail_product_variants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Bán: order_items.item_type='retail_variant', item_ref_id=variant_id


-- =====================  EXTENSION F&B (cc_fnb_*)  ===================
-- Phụ thuộc retail (mở rộng product). KHÔNG sao chép product/cart/order.

CREATE TABLE cc_fnb_ingredients (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(191) NOT NULL,
  unit          VARCHAR(20) NOT NULL,             -- g|ml|cái
  current_stock DECIMAL(15,3) NOT NULL DEFAULT 0,
  reorder_point DECIMAL(15,3) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cc_fnb_option_groups (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id BIGINT UNSIGNED NULL,                -- gắn product retail; NULL = dùng chung
  name       VARCHAR(120) NOT NULL,
  type       VARCHAR(10) NOT NULL DEFAULT 'single', -- single|multi
  required   TINYINT(1) NOT NULL DEFAULT 0,
  min_select INT NOT NULL DEFAULT 0,
  max_select INT NULL,
  position   INT NOT NULL DEFAULT 0,
  KEY idx_product (product_id),
  CONSTRAINT fk_optgrp_product FOREIGN KEY (product_id) REFERENCES cc_retail_products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cc_fnb_option_values (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  group_id    BIGINT UNSIGNED NOT NULL,
  name        VARCHAR(120) NOT NULL,
  price_delta DECIMAL(15,2) NOT NULL DEFAULT 0,
  position    INT NOT NULL DEFAULT 0,
  KEY idx_group (group_id),
  CONSTRAINT fk_optval_group FOREIGN KEY (group_id) REFERENCES cc_fnb_option_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cc_fnb_recipes (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id BIGINT UNSIGNED NOT NULL,            -- product retail
  yield      INT NOT NULL DEFAULT 1,
  KEY idx_product (product_id),
  CONSTRAINT fk_recipe_product FOREIGN KEY (product_id) REFERENCES cc_retail_products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cc_fnb_recipe_items (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  recipe_id     BIGINT UNSIGNED NOT NULL,
  ingredient_id BIGINT UNSIGNED NOT NULL,
  qty           DECIMAL(15,3) NOT NULL,
  unit          VARCHAR(20) NOT NULL,
  KEY idx_recipe (recipe_id),
  KEY idx_ingredient (ingredient_id),
  CONSTRAINT fk_ri_recipe     FOREIGN KEY (recipe_id)     REFERENCES cc_fnb_recipes(id) ON DELETE CASCADE,
  CONSTRAINT fk_ri_ingredient FOREIGN KEY (ingredient_id) REFERENCES cc_fnb_ingredients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Bán: order_items.item_type='fnb_product' (hoặc 'retail_variant'); option lưu options_json


-- =====================  MODULE BOOKING (cc_booking_*)  ==============
-- Cross-cutting: gia sư (edu), hẹn spa (beauty), đặt bàn (fnb). Tạo TRƯỚC beauty/edu.

CREATE TABLE cc_booking_resources (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  resource_type VARCHAR(20) NOT NULL,             -- staff|room|tutor|table
  ref_type      VARCHAR(40) NULL,                 -- beauty_staff|edu_instructor ... (no FK)
  ref_id        BIGINT UNSIGNED NULL,
  name          VARCHAR(191) NOT NULL,
  capacity      INT NOT NULL DEFAULT 1,
  active        TINYINT(1) NOT NULL DEFAULT 1,
  KEY idx_ref (ref_type, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cc_booking_availability_rules (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  resource_id  BIGINT UNSIGNED NOT NULL,
  weekday      TINYINT NOT NULL,                  -- 0=CN ... 6=T7
  start_time   TIME NOT NULL,
  end_time     TIME NOT NULL,
  slot_minutes INT NOT NULL DEFAULT 30,
  valid_from   DATE NULL,
  valid_to     DATE NULL,
  KEY idx_resource (resource_id),
  CONSTRAINT fk_avail_resource FOREIGN KEY (resource_id) REFERENCES cc_booking_resources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cc_booking_slots (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  resource_id  BIGINT UNSIGNED NOT NULL,
  starts_at    TIMESTAMP NOT NULL,
  ends_at      TIMESTAMP NOT NULL,
  capacity     INT NOT NULL DEFAULT 1,
  booked_count INT NOT NULL DEFAULT 0,            -- chống overbooking ở app layer
  status       VARCHAR(20) NOT NULL DEFAULT 'open', -- open|full|blocked
  source       VARCHAR(20) NOT NULL DEFAULT 'rule', -- rule|manual
  KEY idx_resource_time (resource_id, starts_at),
  KEY idx_status (status),
  CONSTRAINT fk_slot_resource FOREIGN KEY (resource_id) REFERENCES cc_booking_resources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cc_booking_reservations (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slot_id     BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  order_id    BIGINT UNSIGNED NULL,               -- liên kết về core order (đặt trước trả sau => NULL ban đầu)
  ref_type    VARCHAR(40) NULL,                   -- beauty_service|edu_course ... (no FK)
  ref_id      BIGINT UNSIGNED NULL,
  party_size  INT NOT NULL DEFAULT 1,
  status      VARCHAR(20) NOT NULL DEFAULT 'pending', -- pending|confirmed|cancelled|no_show|completed
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_slot (slot_id),
  KEY idx_customer (customer_id),
  KEY idx_order (order_id),
  CONSTRAINT fk_resv_slot     FOREIGN KEY (slot_id)     REFERENCES cc_booking_slots(id),
  CONSTRAINT fk_resv_customer FOREIGN KEY (customer_id) REFERENCES cc_core_customers(id),
  CONSTRAINT fk_resv_order    FOREIGN KEY (order_id)    REFERENCES cc_core_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Bán: order_items.item_type='booking_slot', item_ref_id=reservation_id


-- =====================  MODULE BEAUTY (cc_beauty_*)  ================
-- Dịch vụ + nhân viên. Đặt lịch dùng module booking.

CREATE TABLE cc_beauty_services (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title         VARCHAR(191) NOT NULL,
  slug          VARCHAR(191) NOT NULL,
  duration_min  INT NOT NULL DEFAULT 30,
  price         DECIMAL(15,2) NOT NULL,
  category      VARCHAR(120) NULL,
  needs_booking TINYINT(1) NOT NULL DEFAULT 1,
  needs_staff   TINYINT(1) NOT NULL DEFAULT 1,
  status        VARCHAR(20) NOT NULL DEFAULT 'published',
  deleted_at    TIMESTAMP NULL,
  KEY idx_slug (slug),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cc_beauty_service_addons (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  service_id     BIGINT UNSIGNED NOT NULL,
  name           VARCHAR(120) NOT NULL,
  price_delta    DECIMAL(15,2) NOT NULL DEFAULT 0,
  duration_delta INT NOT NULL DEFAULT 0,
  KEY idx_service (service_id),
  CONSTRAINT fk_addon_service FOREIGN KEY (service_id) REFERENCES cc_beauty_services(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cc_beauty_staff (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  wp_user_id  BIGINT UNSIGNED NULL,               -- ref WP user (no FK)
  name        VARCHAR(191) NOT NULL,
  skills_json JSON NULL,
  active      TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cc_beauty_staff_services (
  staff_id   BIGINT UNSIGNED NOT NULL,
  service_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (staff_id, service_id),
  KEY idx_service (service_id),
  CONSTRAINT fk_ss_staff   FOREIGN KEY (staff_id)   REFERENCES cc_beauty_staff(id)    ON DELETE CASCADE,
  CONSTRAINT fk_ss_service FOREIGN KEY (service_id) REFERENCES cc_beauty_services(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cc_beauty_client_profiles (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id   BIGINT UNSIGNED NOT NULL,
  skin_type     VARCHAR(40) NULL,
  concerns_json JSON NULL,
  notes         TEXT NULL,
  KEY idx_customer (customer_id),
  CONSTRAINT fk_clientprof_customer FOREIGN KEY (customer_id) REFERENCES cc_core_customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Bán: order_items.item_type='beauty_service'; nếu cần giờ => thêm cc_booking_reservations


-- =====================  MODULE EDUCATION (cc_edu_*)  ================
-- Gia sư, dạy học, khóa học, đăng ký thi. Dùng lại payment/customer/booking/notification.

CREATE TABLE cc_edu_instructors (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  wp_user_id     BIGINT UNSIGNED NULL,            -- ref WP user (no FK)
  name           VARCHAR(191) NOT NULL,
  bio            TEXT NULL,
  expertise_json JSON NULL,
  hourly_rate    DECIMAL(15,2) NULL,              -- cho gia sư
  rating         DECIMAL(3,2) NULL,
  KEY idx_wp_user (wp_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cc_edu_courses (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type          VARCHAR(20) NOT NULL DEFAULT 'self_paced', -- self_paced|cohort|tutoring
  title         VARCHAR(191) NOT NULL,
  slug          VARCHAR(191) NOT NULL,
  description   MEDIUMTEXT NULL,
  instructor_id BIGINT UNSIGNED NULL,
  price         DECIMAL(15,2) NOT NULL DEFAULT 0,
  level         VARCHAR(40) NULL,
  capacity      INT NULL,                         -- cohort
  starts_at     TIMESTAMP NULL,
  ends_at       TIMESTAMP NULL,
  status        VARCHAR(20) NOT NULL DEFAULT 'draft',
  deleted_at    TIMESTAMP NULL,
  KEY idx_slug (slug),
  KEY idx_status (status),
  KEY idx_instructor (instructor_id),
  CONSTRAINT fk_course_instructor FOREIGN KEY (instructor_id) REFERENCES cc_edu_instructors(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cc_edu_lessons (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  course_id   BIGINT UNSIGNED NOT NULL,
  title       VARCHAR(191) NOT NULL,
  position    INT NOT NULL DEFAULT 0,
  content_ref BIGINT UNSIGNED NULL,               -- ref WP post/media (no FK)
  duration    INT NULL,
  is_preview  TINYINT(1) NOT NULL DEFAULT 0,
  KEY idx_course (course_id),
  CONSTRAINT fk_lesson_course FOREIGN KEY (course_id) REFERENCES cc_edu_courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cc_edu_students (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id BIGINT UNSIGNED NOT NULL,           -- student = customer
  code        VARCHAR(40) NULL,
  notes       TEXT NULL,
  KEY idx_customer (customer_id),
  CONSTRAINT fk_student_customer FOREIGN KEY (customer_id) REFERENCES cc_core_customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cc_edu_enrollments (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  course_id    BIGINT UNSIGNED NOT NULL,
  student_id   BIGINT UNSIGNED NOT NULL,
  order_id     BIGINT UNSIGNED NULL,              -- liên kết core order
  status       VARCHAR(20) NOT NULL DEFAULT 'active', -- active|completed|refunded
  progress     DECIMAL(5,2) NOT NULL DEFAULT 0,
  enrolled_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at TIMESTAMP NULL,
  KEY idx_course (course_id),
  KEY idx_student (student_id),
  KEY idx_order (order_id),
  CONSTRAINT fk_enroll_course  FOREIGN KEY (course_id)  REFERENCES cc_edu_courses(id),
  CONSTRAINT fk_enroll_student FOREIGN KEY (student_id) REFERENCES cc_edu_students(id),
  CONSTRAINT fk_enroll_order   FOREIGN KEY (order_id)   REFERENCES cc_core_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cc_edu_exams (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title                 VARCHAR(191) NOT NULL,
  slug                  VARCHAR(191) NOT NULL,
  subject               VARCHAR(120) NULL,
  fee                   DECIMAL(15,2) NOT NULL DEFAULT 0,
  schedule_at           TIMESTAMP NULL,
  location              VARCHAR(191) NULL,
  capacity              INT NULL,
  registration_deadline TIMESTAMP NULL,
  status                VARCHAR(20) NOT NULL DEFAULT 'open',
  KEY idx_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cc_edu_exam_registrations (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  exam_id       BIGINT UNSIGNED NOT NULL,
  student_id    BIGINT UNSIGNED NOT NULL,
  order_id      BIGINT UNSIGNED NULL,
  status        VARCHAR(20) NOT NULL DEFAULT 'registered', -- registered|paid|cancelled|attended
  seat_no       VARCHAR(20) NULL,
  registered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_exam (exam_id),
  KEY idx_student (student_id),
  KEY idx_order (order_id),
  CONSTRAINT fk_examreg_exam    FOREIGN KEY (exam_id)    REFERENCES cc_edu_exams(id),
  CONSTRAINT fk_examreg_student FOREIGN KEY (student_id) REFERENCES cc_edu_students(id),
  CONSTRAINT fk_examreg_order   FOREIGN KEY (order_id)   REFERENCES cc_core_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Bán: khóa học => item_type='edu_course'; đăng ký thi => item_type='edu_exam'


-- =====================  BUILDER LINK (cc_builder_*)  ================
-- Nối content WP ↔ dữ liệu module để template KHÔNG cần code.

CREATE TABLE cc_builder_template_instance (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  template_code VARCHAR(80) NOT NULL,
  version       VARCHAR(20) NOT NULL,
  manifest_json JSON NOT NULL,
  installed_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cc_builder_bindings (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  wp_object_type VARCHAR(20) NOT NULL,            -- page|post|block
  wp_object_id   BIGINT UNSIGNED NOT NULL,        -- ref id post/page WP (no FK)
  binding_key    VARCHAR(80) NOT NULL,            -- product_list|course_grid ...
  source_module  VARCHAR(40) NOT NULL,            -- retail|edu ...
  query_json     JSON NOT NULL,                   -- filter, sort, limit
  params_json    JSON NULL,
  KEY idx_object (wp_object_type, wp_object_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- HẾT. Lưu ý vận hành:
--   • File này = SUPERSET tham chiếu. Provisioner tạo per-site DB gồm
--     cc_core_* + SUBSET module theo ctrl_template_modules của tenant.
--   • Mỗi section module = một bộ migration riêng (theo ctrl_module_catalog.migrations_uri).
--   • cc_core là "public API": chỉ thêm cột/bảng, có version, KHÔNG phá vỡ.
--   • Nếu Multisite/dbDelta xung đột CONSTRAINT: bỏ FK nhưng GIỮ index.
--   • CHECK cần MySQL 8.0.16+ / MariaDB 10.2.1+ (bản cũ sẽ bỏ qua, không lỗi).
-- =====================================================================
