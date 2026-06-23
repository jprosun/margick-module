# margick-modules — shared module monorepo

**Một nguồn sự thật** cho các module PHP dùng lại được, mà build sẽ **bake (vendor) vào từng container WP standalone**. Không server runtime — module chỉ là code, copy vào image lúc build.

> Đọc kiến trúc đầy đủ: `margick-template/MODULE-ARCHITECTURE.md` + `TEMPLATE-BACKEND-PLAYBOOK.md` + `DESIGN-PRINCIPLES.md`.

## Cấu trúc
```
margick-modules/
  composer.json            ← path repository → packages/*
  packages/
    margick-commerce/      ← commerce core: order + discount + voucher + payment mechanics
    (margick-booking/ …)   ← thêm dần khi extract
```

## Nguyên tắc (6 kỷ luật — xem MODULE-ARCHITECTURE §5)
1. PSR-4 + composer autoload.
2. Domain thuần PHP (`src/Domain`), WP ở rìa (`src/Wp`).
3. Include KHÔNG side-effect → host gọi `bootstrap()` tường minh.
4. Phụ thuộc một chiều: core ⟵ industry; core không biết WP.
5. Contract = interface (`src/Contracts`).
6. Có version (mỗi package: `VERSION` + `composer.json`).

## Template tiêu thụ module như thế nào
Template **khai báo** (link) version trong manifest → `build-template.sh` **copy** package vào `wp-content/mu-plugins/` của container → bake vào image. Trên platform: standalone, không link.

## Trạng thái
- `margick-commerce` **v0.5.0** — Money + DiscountEngine + Stripe mechanics +
  order schema/repository + voucher lifecycle, explicit campaign cap policy and
  atomic reservation/redemption ledger.
- `margick-booking` **v0.1.0** — pure slot/block math; DB hold/resource schema vẫn đợi ngành thứ hai xác nhận seam.

## Test
```
php packages/margick-commerce/tests/DiscountEngineTest.php   # pure PHP, không cần composer/WP
php packages/margick-commerce/tests/VoucherValidatorTest.php # pure voucher rules
```
