# Frontend surface split evaluation

## Implementation status
- Public booking flows now live in `public/class-frontend-public.php` while the manager portal logic moved into `public/class-frontend-manager.php`. A lightweight facade (`public/class-frontend.php`) keeps the legacy `RB_Frontend` entrypoint intact and instantiates both controllers once per request.
- Shared helpers (location lookup, booking window validation, time-slot generation) sit inside `public/class-frontend-base.php` so both surfaces reuse the same calculations without duplication.
- Ajax registrations are now scoped to each controller: customer endpoints are wired by `RB_Frontend_Public`, whereas manager-only updates are registered by `RB_Frontend_Manager`.

## Current structure
- `RB_Frontend` hiện đóng vai trò facade nhẹ, chỉ chịu trách nhiệm nạp và giữ singleton cho hai controller mới. 【F:public/class-frontend.php†L22-L41】
- `RB_Frontend_Public` quản lý toàn bộ flow đặt bàn của khách, bao gồm shortcode hiển thị widget/portal và các AJAX `rb_submit_booking`, `rb_check_availability`, `rb_get_time_slots`. 【F:public/class-frontend-public.php†L24-L794】
- `RB_Frontend_Manager` phụ trách đăng nhập portal, lưu session/location, render dashboard và cập nhật trạng thái booking qua AJAX `rb_manager_update_booking`. 【F:public/class-frontend-manager.php†L30-L488】

## Legacy pain points (trước khi tách)
1. **Lifecycle collisions** – Manager-only hooks (login handler, portal Ajax) từng chạy trên trang khách vì dùng chung lớp frontend duy nhất, tăng rủi ro chồng chéo.
2. **Complex constructor** – Hàm khởi tạo phải khởi động cả hai flow nên khó mở rộng mà không ảnh hưởng lẫn nhau.
3. **Testing friction** – Việc gom shortcode, AJAX và login vào một lớp khiến khó tách bối cảnh khi kiểm thử.

## Proposed split
- Create two dedicated frontend entry points:
  - `RB_Frontend_Public` responsible for booking widgets, multi-location portal, and public Ajax endpoints (`rb_submit_booking`, `rb_check_availability`, `rb_get_time_slots`).
  - `RB_Frontend_Manager` responsible for manager login/session bootstrap and portal-only Ajax (`rb_manager_update_booking`).
- Extract shared helpers (location lookup, session/account access) into reusable traits or small services so both classes do not duplicate logic.
- Update bootstrap to load both classes and only register hooks when the relevant shortcode or Ajax action is used, reducing overhead.

## Expected benefits
- Cleaner separation of concerns, making it easier to reason about changes for each audience independently.
- Reduced accidental coupling between customer and manager flows since their hooks are registered in their own classes.
- Easier unit/integration testing for each surface due to smaller, more focused classes.

## Considerations before implementation
- Ensure session handling still occurs early enough for manager pages (e.g. keep `template_redirect` listener in manager controller).
- Audit shortcodes to confirm which pages need each controller so that assets/enqueues remain intact.
- Plan migration carefully to avoid breaking existing shortcode names or Ajax endpoints relied upon by current pages.
