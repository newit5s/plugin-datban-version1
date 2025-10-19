# Plugin Capability Evaluation

## Yêu cầu: Tách 2 trang (frontend & backend location)
- Plugin cung cấp shortcode `[restaurant_booking_portal]` với flow 3 bước: chọn chi nhánh & ngôn ngữ, kiểm tra chỗ trống, rồi nhập chi tiết đặt bàn cho khách. 【F:public/class-frontend-public.php†L314-L540】
- Shortcode `[restaurant_booking_manager]` hiển thị portal đăng nhập và bảng điều khiển riêng cho từng location, chỉ dành cho các tài khoản portal được tạo trong phần cài đặt plugin. 【F:public/class-frontend-manager.php†L120-L340】【F:admin/class-admin.php†L1586-L1706】

## Yêu cầu: 3 location (HCM, HN, JP)
- Khi kích hoạt plugin, bảng `rb_locations` được tạo kèm dữ liệu mẫu cho 3 chi nhánh HCM, HN, JP, đồng thời tạo bàn mặc định cho từng chi nhánh. 【F:includes/class-database.php†L203-L305】
- Lớp `RB_Location` hỗ trợ lấy danh sách và cấu hình chi nhánh để frontend/backend sử dụng. 【F:includes/class-location.php†L19-L106】

## Yêu cầu: Frontend kiểm tra bàn trống và gợi ý thời gian khác
- Bước kiểm tra gọi AJAX `rb_check_availability`; nếu hết chỗ sẽ trả về danh sách khung giờ đề xuất trong ±30 phút để khách chọn. 【F:public/class-frontend-public.php†L682-L729】【F:includes/class-booking.php†L342-L384】

## Yêu cầu: Email xác nhận với link auto confirm và hotline nếu không có email
- Sau khi khách gửi form, hệ thống tạo booking ở trạng thái pending, gửi email xác nhận và email cho admin; nội dung message trên frontend nhắc khách bấm link, hoặc gọi hotline nếu không có email. 【F:public/class-frontend-public.php†L543-L679】【F:includes/class-email.php†L30-L83】
- Email pending chứa link `rb_confirm_token` để khách tự động xác nhận. 【F:includes/class-email.php†L49-L66】

## Yêu cầu: Backend theo location và admin tổng
- Các tài khoản portal được gán chi nhánh nào thì chỉ xem/duyệt đơn trong chi nhánh đó; admin WordPress quản lý tài khoản thông qua tab Portal Accounts thay vì dùng vai trò riêng. 【F:includes/class-portal-account.php†L31-L199】【F:public/class-frontend-manager.php†L49-L488】

## Yêu cầu: Đa ngôn ngữ
- Lớp `RB_I18n` quản lý ngôn ngữ (Vi/En/Ja), lưu vào session/cookie/user meta và cung cấp switcher cho frontend/backend. 【F:includes/class-i18n.php†L15-L131】【F:includes/class-language-switcher.php†L12-L103】
- Mỗi ngôn ngữ có gói dịch riêng trong thư mục `languages` (ví dụ `languages/vi_VN/translations.php`, `languages/en_US/translations.php`, `languages/ja_JP/translations.php`) bao phủ toàn bộ nhãn ở cả frontend lẫn backend. 【F:languages/vi_VN/translations.php†L7-L90】【F:languages/en_US/translations.php†L7-L80】【F:languages/ja_JP/translations.php†L7-L82】
- Frontend portal tích hợp bộ chọn ngôn ngữ ngay trong bước đầu và lưu lựa chọn để bước backend cũng hiển thị đúng ngôn ngữ, đồng thời mọi thông báo JS lấy từ gói dịch. 【F:public/class-frontend-public.php†L314-L511】【F:includes/class-i18n.php†L33-L112】【F:assets/js/frontend.js†L147-L257】【F:restaurant-booking-manager.php†L179-L199】

## Kết luận
Plugin đáp ứng đầy đủ các tiêu chí mô tả (flow đặt bàn, gợi ý giờ khác, email xác nhận, quản lý đa chi nhánh với quyền riêng, và đa ngôn ngữ). Người dùng chỉ cần cấu hình thêm nội dung thực tế (giờ mở cửa, hotline, dịch thuật) trong admin.
