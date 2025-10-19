# Restaurant Booking Manager

Plugin WordPress giúp nhà hàng quản lý đặt bàn từ khâu tiếp nhận của khách đến xử lý nội bộ. Hệ thống gồm giao diện đặt chỗ hiện đại cho khách, portal riêng cho nhân viên cùng bộ công cụ quản trị, email và đa ngôn ngữ được tích hợp sẵn.

## 📚 Tổng quan kiến trúc

- **Bootstrap**: `restaurant-booking-manager.php` khởi tạo plugin, đăng ký shortcode, enqueue assets kế thừa và gọi toàn bộ lớp trong `includes/`, `admin/`, `public/`.
- **Business layer**: các lớp trong `includes/` phụ trách đặt bàn (`RB_Booking`), khách hàng (`RB_Customer`), chi nhánh & bàn (`RB_Location`), tài khoản portal (`RB_Portal_Account_Manager`), email (`RB_Email`), AJAX (`RB_Ajax`) và đa ngôn ngữ (`RB_I18n`).
- **Frontend**: `assets/css/new-frontend.css` + `assets/js/new-booking.js` cho widget đặt bàn modal 3 bước; `public/class-frontend-*.php` dựng portal quản lý, chia sẻ logic chung qua `RB_Frontend_Base`.
- **Admin**: `admin/class-admin.php` tạo menu **Đặt bàn**, cấu hình chung, quản lý chi nhánh, bàn, portal accounts, dọn dữ liệu.
- **Mẫu MVC nhẹ**: mỗi module có lớp controller (AJAX/REST), service (xử lý logic) và repository (làm việc với `RB_Database`). Các service nên đặt trong `includes/services/` (nếu cần thêm) để tái sử dụng cho cả frontend và admin.

## 📦 Cấu trúc thư mục

```
restaurant-booking-manager/
├── restaurant-booking-manager.php      # Bootstrap plugin & loader chính
├── admin/
│   ├── class-admin.php                 # Trang quản trị + settings tabs
│   └── partials/                       # Template admin (dashboard, tables...)
├── includes/
│   ├── class-ajax.php                  # Endpoint AJAX cho frontend & portal
│   ├── class-assets-manager.php        # Enqueue giao diện đặt bàn, portal, admin
│   ├── class-booking.php               # Business logic đặt bàn + trạng thái
│   ├── class-booking-manager.php       # Điều phối workflow đặt bàn nâng cao
│   ├── class-customer.php              # Quản lý khách hàng & lịch sử
│   ├── class-database.php              # Tạo & migrate bảng dữ liệu
│   ├── class-email.php                 # Gửi email xác nhận & thông báo
│   ├── class-i18n.php                  # Đa ngôn ngữ & bản dịch
│   ├── class-language-switcher.php     # Shortcode + widget đổi ngôn ngữ
│   ├── class-location.php              # Quản lý chi nhánh & bàn
│   ├── class-portal-account-manager.php # CRUD tài khoản portal + phân quyền
│   └── services/                       # Service mở rộng dùng chung (tùy chọn)
├── public/
│   ├── class-frontend-base.php         # Logic chia sẻ giữa frontend/portal
│   ├── class-frontend-public.php       # Widget đặt bàn (customer facing)
│   ├── class-frontend-manager.php      # Portal quản lý cho nhân viên
│   ├── partials/                       # Template frontend & portal
│   └── class-frontend.php              # Facade nạp các bề mặt frontend
├── assets/
│   ├── css/
│   │   ├── admin.css                   # Giao diện trang quản trị plugin
│   │   ├── frontend.css                # Portal quản lý (legacy layout)
│   │   └── new-frontend.css            # Giao diện đặt bàn mới dạng modal
│   └── js/
│       ├── admin.js                    # Tương tác CRUD trong trang admin
│       ├── frontend.js                 # Portal quản lý & bảng điều khiển
│       └── new-booking.js              # Luồng đặt bàn mới 3 bước
├── languages/                          # File bản dịch (vi, en, ja...)
├── docs/                               # Tài liệu bổ sung (hướng dẫn, API)
└── vendor/                             # Thư viện Composer (nếu bật)
```

> **Ghi chú:** Nếu tạo lớp/feature mới, hãy đặt đúng thư mục tương ứng và cập nhật lại sơ đồ trên để các thành viên khác dễ theo dõi.

### Danh sách lớp quan trọng

| Khu vực | Lớp chính | Vai trò | Gợi ý mở rộng |
| --- | --- | --- | --- |
| Bootstrap | `RB_Loader` | Đăng ký hook/action/filter | Thêm hook mới bằng `add_action`/`add_filter` tại loader để quản lý tập trung |
| Đặt bàn | `RB_Booking`, `RB_Booking_Manager` | CRUD đặt bàn, validate, workflow trạng thái | Thêm trạng thái mới bằng cách mở rộng `RB_Booking::get_statuses()` |
| Portal | `RB_Portal_Controller`, `RB_Portal_Session_Manager` | Xử lý đăng nhập, session, trang nội bộ | Tạo module portal mới bằng cách thêm phương thức render và template tương ứng trong `public/partials/` |
| Admin | `RB_Admin_Settings`, `RB_Admin_Tables` | Tab cấu hình, quản lý bàn | Thêm tab bằng hook `rb_admin_tabs` và render partial tại `admin/partials/` |
| Email | `RB_Email` | Render template, gửi mail | Tạo template mới trong `includes/emails/` và đăng ký qua filter `rb_email_templates` |

## ✨ Tính năng chính

### Dành cho khách
- Chọn chi nhánh, ngày, giờ, số khách với gợi ý khung giờ thay thế nếu hết chỗ.
- Xác thực dữ liệu đầu vào (email, điện thoại), hỗ trợ đa ngôn ngữ và chuyển ngôn ngữ ngay trên widget.
- Gửi email xác nhận kèm token, cho phép khách cập nhật trạng thái qua đường dẫn bảo mật.

### Dành cho nhân viên (Portal)
- Đăng nhập bằng tài khoản nội bộ, giới hạn chi nhánh theo phân quyền.
- Dashboard thống kê trạng thái, lọc đặt bàn theo ngày/nguồn, xem lịch sử khách hàng.
- Thao tác nhanh: xác nhận/huỷ/hoàn tất đặt bàn, gán bàn, đánh dấu VIP/Blacklist, ghi chú nội bộ.
- Quản lý bàn theo chi nhánh (thêm/xoá/bật tắt), cập nhật cấu hình giờ mở cửa, buffer time.

### Dành cho quản trị viên WordPress
- Giao diện quản trị riêng với tab **General**, **Locations**, **Portal Accounts**, **Email & Automation**, **Tools**.
- Sinh portal account, đặt lại mật khẩu, gán nhiều chi nhánh trên cùng một tài khoản.
- Công cụ dọn lịch cũ và reset plugin (tùy chọn) qua AJAX có nonce bảo vệ.

### Hệ thống nền tảng
- Tạo bảng dữ liệu khi kích hoạt (`RB_Database::create_tables`), đảm bảo schema portal (`ensure_portal_schema`).
- Lớp i18n tự động phát hiện ngôn ngữ từ session → cookie → URL → meta người dùng → locale WP, fallback `vi_VN`.
- Enqueue assets có điều kiện dựa trên shortcode ở trang hiện tại, giảm tải cho theme.

## ✅ Yêu cầu hệ thống

- WordPress 5.8 trở lên.
- PHP 7.0+ với quyền tạo bảng trong MySQL.
- wp-cron hoạt động để gửi email, nhắc lịch.

## 🚀 Cài đặt & nâng cấp

1. Upload thư mục `restaurant-booking-manager` vào `wp-content/plugins/` hoặc cài đặt qua Composer/SFTP.
2. Kích hoạt plugin tại **Plugins → Installed Plugins**.
3. Khi nâng cấp, plugin tự migrate bảng mới (bao gồm bảng portal) và giữ nguyên dữ liệu hiện có.

## 👨‍💻 Thiết lập môi trường phát triển

1. Khởi tạo site WordPress cục bộ (LocalWP, Docker, Laravel Valet...).
2. Clone repo vào `wp-content/plugins/restaurant-booking-manager`.
3. Chạy `composer install` (nếu dùng) để lấy autoload và công cụ hỗ trợ.
4. Bật `WP_DEBUG`, `WP_DEBUG_LOG` trong `wp-config.php`.
5. Tạo file `.env.local` (không commit) để lưu API key, SMTP...; đọc trong code bằng `getenv()` hoặc `wp_parse_args` với mặc định.

> **Tip:** Có thể dùng `wp-env` của WordPress hoặc Docker Compose: mount plugin vào container PHP-FPM, chạy MySQL và mailhog để kiểm tra email.

## 🛠️ Cấu hình sau khi kích hoạt

1. **General**: số bàn tối đa, giờ mở/đóng cửa mặc định, khoảng cách ca (`time_slot_interval`), email nhận thông báo, bật tắt email tự động.
2. **Locations**: tạo chi nhánh với địa chỉ, hotline, khoảng cách đặt trước/đặt muộn, ngôn ngữ hỗ trợ, cấu hình bàn theo chi nhánh.
3. **Portal Accounts**: khởi tạo tài khoản, gán nhiều chi nhánh, đặt mật khẩu, bật/tắt trạng thái. Có thể reset mật khẩu từng tài khoản.
4. **Email & Automation**: bật cron gửi email xác nhận/nhắc nhở, cấu hình nội dung email và buffer time.
5. **Tools**: dọn lịch cũ theo mốc thời gian, reset plugin về trạng thái ban đầu (xóa dữ liệu – cẩn trọng).

## 🧭 Shortcode & Block

| Mục đích | Shortcode | Ghi chú |
| --- | --- | --- |
| Form đặt bàn cho khách | `[restaurant_booking]` hoặc `[restaurant_booking_portal]` | Tự nạp `new-frontend.css` & `new-booking.js`, hỗ trợ chuyển ngôn ngữ tức thì.
| Portal quản lý cho nhân viên | `[restaurant_booking_manager]` | Nạp legacy assets (`frontend.css`, `frontend.js`, `manager-gmail` nếu có) và hiển thị giao diện đăng nhập/portal.
| Bộ chọn ngôn ngữ | `[rb_language_switcher style="flags"]` | Sử dụng `RB_Language_Switcher`, đồng bộ session/cookie và AJAX `rb_switch_language`.

> **Tip:** Có thể nhúng shortcode vào Gutenberg block, widget hoặc template PHP (`echo do_shortcode('[restaurant_booking]');`).

## 🔄 Luồng đặt bàn của khách

1. Người dùng mở widget → `RB_Assets_Manager` chỉ enqueue assets khi trang chứa shortcode.
2. Bước 1: chọn chi nhánh/ngày/giờ → AJAX `rb_check_availability` trả về slot khả dụng, gợi ý giờ lân cận.
3. Bước 2: nhập thông tin khách hàng, validate phía client & server (`RB_Booking::create_booking`).
4. Bước 3: xác nhận → gửi email qua `RB_Email`, sinh token hết hạn trong 24h để khách quản lý đặt bàn.
5. Dữ liệu lưu vào `wp_rb_bookings`, gắn trạng thái `pending` chờ nhân viên xử lý.

## 🧑‍🍳 Portal nhân viên

- Phiên đăng nhập dùng `RB_Portal_Session_Manager`, lưu session và ghi nhớ chi nhánh cuối cùng.
- Sau khi đăng nhập, portal cung cấp:
  - Bảng điều khiển thống kê từ `RB_Booking::get_location_stats` và `get_source_stats`.
  - Bộ lọc đặt bàn (theo ngày, trạng thái, nguồn, text search) dùng các AJAX `rb_manager_*`.
  - Quản lý bàn & khách hàng: toggle bàn, thêm bàn, đánh dấu VIP/Blacklist, xem lịch sử đặt (`RB_Ajax::get_customer_history`).
  - Cập nhật cấu hình chi nhánh và ghi chú khách qua AJAX với nonce `rb_frontend_nonce`.
- Portal hỗ trợ đa ngôn ngữ, đồng bộ với lựa chọn khi đăng nhập.

## 🗂️ Trang quản trị WordPress

- Menu **Đặt bàn** (icon calendar) gồm Dashboard, Create Booking, Tables, Customers, Settings.
- Sử dụng `admin.css` & `admin.js` để tạo trải nghiệm AJAX (confirm/cancel, bulk actions, inline edit).
- Tất cả endpoint admin yêu cầu quyền `manage_options` và nonce `rb_admin_nonce`/`rb_language_nonce`.

## 🌐 Đa ngôn ngữ

- `RB_I18n` tải gói dịch từ `languages/{locale}/translations.php`, filter `rb_translations` cho phép override.
- Ngôn ngữ được lưu vào session (nếu khả dụng) và cookie; có thể đổi bằng AJAX `rb_switch_language` hoặc tham số `rb_lang`.
- Thêm class theo ngôn ngữ vào `<body>` backend/frontend, hỗ trợ theme tùy biến CSS.

## 🧩 Hooks & mở rộng

- **Filters:** `rb_should_enqueue_new_frontend_assets`, `rb_enqueue_legacy_frontend_assets`, `rb_translations`, `rb_available_languages`.
- **Actions/AJAX:** `rb_admin_*` cho thao tác quản trị, `rb_manager_*` cho portal, `rb_cleanup_old_bookings`, `rb_reset_plugin` cho công cụ bảo trì.
- **Helper:** `rb_t()` để lấy chuỗi bản địa hóa, `rb_get_current_language()` và `rb_get_available_languages()` cho dev.

## 💾 Cơ sở dữ liệu chính

| Bảng | Nội dung |
| --- | --- |
| `wp_rb_bookings` | Đặt bàn, trạng thái, nguồn, token xác nhận, thời gian tạo/cập nhật.
| `wp_rb_tables` | Bàn theo chi nhánh, sức chứa, trạng thái hoạt động.
| `wp_rb_customers` | Hồ sơ khách hàng, lịch sử, điểm VIP/Blacklist, ghi chú.
| `wp_rb_locations` | Chi nhánh, giờ mở cửa, ngôn ngữ hỗ trợ, email/hotline.
| `wp_rb_portal_accounts` & `wp_rb_portal_account_locations` | Tài khoản portal, hashed password, chi nhánh được phép.

`RB_Database` tự tạo/migrate các bảng này khi kích hoạt plugin và đảm bảo khóa ngoại logic thông qua PHP.

## 🧪 Kiểm thử & phát triển

- Sử dụng WordPress debug log để theo dõi AJAX (`wp-config.php` bật `WP_DEBUG_LOG`).
- Có thể seed dữ liệu bằng cách nhập CSV vào bảng tương ứng hoặc viết WP-CLI command tùy biến.
- Khi phát triển giao diện, chạy `wp_enqueue_script` và `wp_enqueue_style` với phiên bản filemtime để tránh cache (đã được triển khai sẵn trong plugin).
- Thêm PHPUnit/Codeception test vào `tests/` (tạo thư mục nếu chưa có), khởi chạy bằng `composer test` hoặc `wp scaffold plugin-tests`.
- Dùng `npm run dev` (tự tạo script) để build SCSS/JS nếu chuyển sang bundler như Vite/Webpack; cập nhật enqueue version theo `filemtime`.

## 🔄 Quy trình cập nhật tính năng

1. **Xác định layer**: xác nhận thay đổi nằm ở frontend (JS/CSS + shortcode), portal (public class) hay admin (`admin/`).
2. **Thiết kế dữ liệu**: cập nhật schema trong `includes/class-rb-database.php` và viết hàm migrate trong `activate()` nếu thêm trường/bảng.
3. **Business logic**: mở rộng service tương ứng trong `includes/` (ví dụ thêm phương thức vào `RB_Booking_Manager`). Ưu tiên viết hàm thuần (pure function) để dễ test.
4. **Endpoint**: đăng ký AJAX mới bằng `add_action( 'wp_ajax_rb_xxx', ... )` trong loader và viết handler tại `includes/class-rb-ajax.php`.
5. **Giao diện**: cập nhật template trong `public/partials/` hoặc `admin/partials/`. Với JS, thêm module tại `assets/js/` và enqueue thông qua `RB_Assets_Manager`.
6. **Hook & filter**: nếu expose tính năng cho developer khác, thêm filter/action mới và ghi lại trong mục [Hooks & mở rộng](#-hooks--mở-rộng).
7. **Bản dịch**: thêm chuỗi mới vào file `languages/{locale}/translations.php` và gọi `rb_t( 'key' )` trong code.
8. **Kiểm thử**: chạy unit test, test thủ công portal/admin, kiểm tra email và WP-CLI nếu có lệnh mới.

### Ví dụ: thêm trạng thái "no-show"

1. Thêm trạng thái vào `RB_Booking::get_statuses()` và cập nhật các hằng số liên quan.
2. Điều chỉnh `RB_Booking_Manager::transition_rules()` để cho phép chuyển đổi tới/trở lại.
3. Bổ sung filter UI trong `admin/partials/bookings-list.php` và `public/partials/portal-bookings.php`.
4. Cập nhật email template trong `includes/emails/booking-status-updated.php`.
5. Cập nhật bản dịch `languages/vi_VN/translations.php` và `en_US/translations.php`.

### Ví dụ: thêm field "Ghi chú dị ứng" trong form

1. Thêm input vào `public/partials/booking-form-step2.php` và map dữ liệu tại `assets/js/new-booking.js`.
2. Validate server trong `RB_Booking::validate_payload()`.
3. Lưu xuống DB qua `RB_Booking::create_booking()` (cần column mới -> migrate DB).
4. Hiển thị field trong portal (`public/partials/portal-booking-detail.php`) và email.

## 🧱 Chuẩn code & review

- Tuân thủ PSR-12 cho PHP, sử dụng namespace `Restaurant_Booking\` nếu tạo class mới.
- Đặt tên file theo class (ví dụ `class-rb-waitlist-manager.php`).
- Viết docblock cho method public, mô tả param và return.
- Với JS, dùng ES6 module, tránh global. Tên function dạng `camelCase`, constant dạng `SCREAMING_SNAKE_CASE`.
- CSS ưu tiên BEM (`.rb-widget__form`, `.rb-widget__button--primary`).
- Trước khi mở PR: chạy `composer lint` (nếu có), `npm run lint` (tự thêm), cập nhật README/Hooks khi thêm API mới.

## 📄 Giấy phép

Phát hành theo GPL v2 hoặc mới hơn. Bạn có thể tự do chỉnh sửa và phân phối lại theo điều khoản GPL.

---

**Made with ❤️ for Vietnamese Restaurants**
