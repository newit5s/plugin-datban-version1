# Plugin-Scoped Manager Accounts Roadmap

This document outlines how to introduce standalone booking manager accounts that are configured inside the Restaurant Booking Manager plugin instead of reusing native WordPress users.

## Goals
- Allow administrators to create credentials per location directly from the plugin settings screen.
- Prevent access to WordPress admin for these accounts.
- Restrict each account to the locations assigned in plugin settings.
- Ensure the existing shortcode `[restaurant_booking_manager]` chỉ cần xác thực và phân quyền cho các tài khoản portal nội bộ, không phụ thuộc vào vai trò WordPress.

## Data Model
1. **Custom table:** Add a table (e.g., `{$wpdb->prefix}rb_portal_accounts`) during plugin activation to store:
   - `id`, `username`, `password_hash`, `display_name`, `email` (optional)
   - `created_at`, `updated_at`, `last_login_at`, `status`
2. **Location linkage:** Create a mapping table `{$wpdb->prefix}rb_portal_account_locations` to associate accounts with location IDs (many-to-many).
3. **Password storage:** Use `wp_hash_password()` for consistency with WordPress hashing algorithms.

## Admin UI Changes
- Add a new "Portal Accounts" tab within the plugin settings (under `admin/class-admin.php`).
- Provide CRUD operations:
  - Create/update accounts with username, temp password, display name, email, status toggle.
  - Assign allowed locations via multi-select sourced from `RB_Location_Helper::all()`.
  - Reset password action that sends a temporary password via email (optional first iteration: display generated password once).
- Reuse WordPress nonces and capability checks so only administrators can manage these records.

## Authentication Flow Adjustments
1. Extend the login handler in `public/class-frontend-manager.php`:
   - Chuẩn hóa input username/email và tìm portal account tương ứng.
   - Xác thực mật khẩu bằng `wp_check_password()` và đảm bảo trạng thái tài khoản là active.
   - Khi thành công, tạo token phiên (cookie + transient) chỉ dùng cho portal.
   - Lưu location đang hoạt động vào cột `last_location_id` của tài khoản.
2. Toàn bộ kiểm tra phân quyền của shortcode dựa vào portal session đang hoạt động.

## Authorization & Session Handling
- Replace the current `get_allowed_locations()` logic để chỉ lấy từ bảng mapping của portal accounts.
- Store the last selected location per account (custom table column) instead of `user_meta`.
- Implement logout endpoint that clears custom cookies/transients.

## Email / Notifications (Optional)
- Provide hooks or template emails for account creation and password resets.
- Allow administrators to resend invitation emails from the settings screen.

## Migration Considerations
- Activation hook must create tables with `dbDelta()`.
- Provide tools to import existing WP Location Managers into the new account system (optional).
- Ensure deactivation cleans transient session data but leaves tables intact (so data persists across upgrades).

## Testing Strategy
- Unit test account CRUD logic and authentication helpers (using WordPress test suite).
- Manual QA: create account, assign single/multiple locations, verify login and restriction, test invalid credentials, reset password, ensure WordPress admin login unaffected.

## Rollout Steps
1. Scaffold database tables and data access layer (DAL) classes inside `includes/`.
2. Build admin UI components with AJAX endpoints for account management.
3. Update frontend shortcode authentication/authorization flow.
4. Add documentation for administrators covering how to manage plugin-level accounts.
