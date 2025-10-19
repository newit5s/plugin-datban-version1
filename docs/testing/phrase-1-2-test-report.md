# Timeline Feature Phase 1-2 Test Report

This document records the manual verification performed for the Phase 1-2 work described in `update feature/phrase 1, 2`.

## Environment
- Plugin: Restaurant Booking Manager 1.0.1
- WordPress: 6.6.2 (local development instance)
- PHP: 8.1
- Database: MariaDB 10.6 with WordPress default table prefix `wp_`

## Test Cases

### 1. Database migration
- ✅ `checkin_time`, `checkout_time`, `actual_checkin`, `actual_checkout`, and `cleanup_completed_at` columns added to `wp_rb_bookings`.
- ✅ `current_status`, `status_updated_at`, and `last_booking_id` columns added to `wp_rb_tables`.
- ✅ Indexes `checkin_checkout_index` and `current_status_index` created.
- ✅ Existing booking rows populated with default timeline values (`checkin_time = booking_time`, `checkout_time = booking_time + 2h`, computed buffers for actual times).

### 2. `check_time_overlap()`
- ✅ No overlap returns `false` when slots are separated by more than 1h cleanup buffer.
- ✅ Direct overlap with same slot returns `true`.
- ✅ Overlap detected when accounting for ±15 minute arrival/departure buffers and the 1 hour cleanup window.
- ✅ `$exclude_booking_id` skips the current booking while editing.

### 3. `is_time_slot_available()`
- ✅ Accepts explicit `checkin_time` and `checkout_time` and validates duration between 1h and 6h.
- ✅ Rejects durations shorter than 1h or longer than 6h.
- ✅ Rejects slots where `checkout_time` is earlier than `checkin_time`.
- ✅ Integrates `check_time_overlap()` to block clashing reservations.
- ✅ Confirms table capacity and location working hours before approving a slot.

### 4. `get_timeline_data()`
- ✅ Generates 30-minute interval slots based on location opening/closing times.
- ✅ Returns all tables for the location with current status and capacity metadata.
- ✅ Maps bookings to the correct table buckets with calculated `actual_checkin`/`actual_checkout` (±15 minutes + 1h cleanup).
- ✅ Results sorted by `checkin_time` for each table.

### 5. `update_table_status()`
- ✅ Updates `wp_rb_tables.current_status`, `status_updated_at`, and `last_booking_id`.
- ✅ Persists the change when switching among `available`, `occupied`, `cleaning`, and `reserved` states.

### 6. `create_booking()` timeline support
- ✅ Accepts optional `checkin_time` and `checkout_time` fields when creating a booking.
- ✅ Persists timeline values to the database.
- ✅ Validates slot duration and availability prior to insert.

### 7. `confirm_booking()`
- ✅ Marks associated table as `reserved` and saves the booking reference into `last_booking_id`.

### 8. Check-in/out flow
- ✅ `mark_checkin()` sets `actual_checkin` (explicit or `NOW()`) and updates table status to `occupied`.
- ✅ `mark_checkout()` records `actual_checkout`, sets cleanup deadline (`cleanup_completed_at = actual_checkout + 1h`), and transitions the table to `cleaning` before returning to `available` after the buffer expires.

## Notes
- All checks executed following the acceptance criteria outlined in `update feature/phrase 1, 2`.
- No database errors or backward compatibility regressions observed during the migration.
