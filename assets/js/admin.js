/**
 * Restaurant Booking - Admin JavaScript
 */

(function($) {
    'use strict';
    
    $(document).ready(function() {
        
        // Initialize tooltips
        initTooltips();
        
        // Booking actions
        initBookingActions();
        
        // Table management
        initTableManagement();
        
        // Filters
        initFilters();
        
        // Bulk actions
        initBulkActions();
        
        // Settings page
        initSettings();
        
        // Charts and reports
        initReports();
        
        /**
         * Initialize tooltips
         */
        function initTooltips() {
            $('.rb-tooltip').hover(function() {
                var title = $(this).attr('title');
                $(this).data('tipText', title).removeAttr('title');
                $('<p class="rb-tooltip-text"></p>')
                    .text(title)
                    .appendTo('body')
                    .fadeIn('slow');
            }, function() {
                $(this).attr('title', $(this).data('tipText'));
                $('.rb-tooltip-text').remove();
            }).mousemove(function(e) {
                var mousex = e.pageX + 20;
                var mousey = e.pageY + 10;
                $('.rb-tooltip-text').css({ top: mousey, left: mousex });
            });
        }
        
        /**
         * Initialize booking actions
         */
        function initBookingActions() {
            // Quick confirm booking
            $('.rb-quick-confirm').on('click', function(e) {
                e.preventDefault();
                var bookingId = $(this).data('booking-id');
                confirmBooking(bookingId);
            });
            
            // Quick cancel booking
            $('.rb-quick-cancel').on('click', function(e) {
                e.preventDefault();
                var bookingId = $(this).data('booking-id');
                if (confirm('Bạn có chắc muốn hủy đặt bàn này?')) {
                    cancelBooking(bookingId);
                }
            });
            
            // Quick complete booking
            $('.rb-quick-complete').on('click', function(e) {
                e.preventDefault();
                var bookingId = $(this).data('booking-id');
                completeBooking(bookingId);
            });
            
            // View booking details
            $('.rb-view-details').on('click', function(e) {
                e.preventDefault();
                var bookingId = $(this).data('booking-id');
                viewBookingDetails(bookingId);
            });
            
            // Edit booking
            $('.rb-edit-booking').on('click', function(e) {
                e.preventDefault();
                var bookingId = $(this).data('booking-id');
                editBooking(bookingId);
            });
        }
        
        /**
         * Confirm booking
         */
        function confirmBooking(bookingId) {
            var $row = $('tr[data-booking-id="' + bookingId + '"]');
            $row.addClass('rb-loading');
            
            $.ajax({
                url: rb_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'rb_admin_confirm_booking',
                    booking_id: bookingId,
                    nonce: rb_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Update status in table
                        $row.find('.rb-status')
                            .removeClass('rb-status-pending')
                            .addClass('rb-status-confirmed')
                            .text('Đã xác nhận');
                        
                        // Update actions
                        $row.find('.rb-quick-confirm').remove();
                        
                        // Show success message
                        showNotice('success', 'Đặt bàn đã được xác nhận thành công!');
                    } else {
                        showNotice('error', response.data.message);
                    }
                },
                error: function() {
                    showNotice('error', 'Có lỗi xảy ra. Vui lòng thử lại.');
                },
                complete: function() {
                    $row.removeClass('rb-loading');
                }
            });
        }
        
        /**
         * Cancel booking
         */
        function cancelBooking(bookingId) {
            var $row = $('tr[data-booking-id="' + bookingId + '"]');
            $row.addClass('rb-loading');
            
            $.ajax({
                url: rb_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'rb_admin_cancel_booking',
                    booking_id: bookingId,
                    nonce: rb_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Update status
                        $row.find('.rb-status')
                            .removeClass('rb-status-pending rb-status-confirmed')
                            .addClass('rb-status-cancelled')
                            .text('Đã hủy');
                        
                        // Update actions
                        $row.find('.rb-quick-confirm, .rb-quick-cancel, .rb-quick-complete').remove();
                        
                        showNotice('success', 'Đặt bàn đã được hủy!');
                    } else {
                        showNotice('error', response.data.message);
                    }
                },
                error: function() {
                    showNotice('error', 'Có lỗi xảy ra. Vui lòng thử lại.');
                },
                complete: function() {
                    $row.removeClass('rb-loading');
                }
            });
        }
        
        /**
         * Complete booking
         */
        function completeBooking(bookingId) {
            var $row = $('tr[data-booking-id="' + bookingId + '"]');
            $row.addClass('rb-loading');
            
            $.ajax({
                url: rb_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'rb_admin_complete_booking',
                    booking_id: bookingId,
                    nonce: rb_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Update status
                        $row.find('.rb-status')
                            .removeClass('rb-status-confirmed')
                            .addClass('rb-status-completed')
                            .text('Hoàn thành');
                        
                        // Update actions
                        $row.find('.rb-quick-complete').remove();
                        
                        showNotice('success', 'Đặt bàn đã được đánh dấu hoàn thành!');
                    } else {
                        showNotice('error', response.data.message);
                    }
                },
                error: function() {
                    showNotice('error', 'Có lỗi xảy ra. Vui lòng thử lại.');
                },
                complete: function() {
                    $row.removeClass('rb-loading');
                }
            });
        }
        
        /**
         * View booking details
         */
        function viewBookingDetails(bookingId) {
            $.ajax({
                url: rb_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'rb_admin_get_booking',
                    booking_id: bookingId,
                    nonce: rb_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        var booking = response.data.booking;
                        showBookingModal(booking);
                    } else {
                        showNotice('error', response.data.message);
                    }
                }
            });
        }
        
        /**
         * Show booking modal
         */
        function showBookingModal(booking) {
            var modalHtml = `
                <div class="rb-modal-overlay">
                    <div class="rb-modal">
                        <div class="rb-modal-header">
                            <h2>Chi tiết đặt bàn #${booking.id}</h2>
                            <button class="rb-modal-close">&times;</button>
                        </div>
                        <div class="rb-modal-body">
                            <div class="rb-booking-details">
                                <table>
                                    <tr>
                                        <td>Khách hàng:</td>
                                        <td>${booking.customer_name}</td>
                                    </tr>
                                    <tr>
                                        <td>Điện thoại:</td>
                                        <td>${booking.customer_phone}</td>
                                    </tr>
                                    <tr>
                                        <td>Email:</td>
                                        <td>${booking.customer_email}</td>
                                    </tr>
                                    <tr>
                                        <td>Số khách:</td>
                                        <td>${booking.guest_count} người</td>
                                    </tr>
                                    <tr>
                                        <td>Ngày:</td>
                                        <td>${formatDate(booking.booking_date)}</td>
                                    </tr>
                                    <tr>
                                        <td>Giờ:</td>
                                        <td>${booking.booking_time}</td>
                                    </tr>
                                    <tr>
                                        <td>Bàn số:</td>
                                        <td>${booking.table_number || 'Chưa phân bàn'}</td>
                                    </tr>
                                    <tr>
                                        <td>Trạng thái:</td>
                                        <td><span class="rb-status rb-status-${booking.status}">${formatStatus(booking.status)}</span></td>
                                    </tr>
                                    ${booking.special_requests ? `
                                    <tr>
                                        <td>Yêu cầu đặc biệt:</td>
                                        <td>${booking.special_requests}</td>
                                    </tr>
                                    ` : ''}
                                    <tr>
                                        <td>Thời gian tạo:</td>
                                        <td>${formatDateTime(booking.created_at)}</td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                        <div class="rb-modal-footer">
                            <button class="button rb-modal-close">Đóng</button>
                        </div>
                    </div>
                </div>
            `;
            
            $('body').append(modalHtml);
            
            // Close modal events
            $('.rb-modal-close, .rb-modal-overlay').on('click', function(e) {
                if (e.target === this) {
                    $('.rb-modal-overlay').remove();
                }
            });
        }
        
        /**
         * Initialize table management
         */
        function initTableManagement() {
            // Add new table
            $('#rb-add-table-form').on('submit', function(e) {
                e.preventDefault();
                var formData = $(this).serialize();
                
                $.ajax({
                    url: rb_ajax.ajax_url,
                    type: 'POST',
                    data: formData + '&action=rb_admin_add_table&nonce=' + rb_ajax.nonce,
                    success: function(response) {
                        if (response.success) {
                            showNotice('success', response.data.message);
                            location.reload();
                        } else {
                            showNotice('error', response.data.message);
                        }
                    }
                });
            });
            
            // Toggle table availability
            $('.rb-toggle-table').on('click', function(e) {
                e.preventDefault();
                var tableId = $(this).data('table-id');
                var isAvailable = $(this).data('available') === 1 ? 0 : 1;
                
                $.ajax({
                    url: rb_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'rb_admin_toggle_table',
                        table_id: tableId,
                        is_available: isAvailable,
                        nonce: rb_ajax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        }
                    }
                });
            });
        }
        
        /**
         * Initialize filters
         */
        function initFilters() {
            // Date range picker
            $('#rb-date-filter').on('change', function() {
                var selectedDate = $(this).val();
                if (selectedDate) {
                    window.location.href = updateQueryStringParameter(window.location.href, 'date', selectedDate);
                }
            });
            
            // Status filter
            $('#rb-status-filter').on('change', function() {
                var selectedStatus = $(this).val();
                window.location.href = updateQueryStringParameter(window.location.href, 'status', selectedStatus);
            });
            
            // Clear filters
            $('#rb-clear-filters').on('click', function(e) {
                e.preventDefault();
                var baseUrl = window.location.href.split('?')[0];
                window.location.href = baseUrl + '?page=restaurant-booking';
            });
        }
        
        /**
         * Initialize bulk actions
         */
        function initBulkActions() {
            // Select all checkboxes
            $('#rb-select-all').on('change', function() {
                $('.rb-booking-checkbox').prop('checked', $(this).is(':checked'));
            });
            
            // Bulk action submit
            $('#rb-bulk-action-submit').on('click', function(e) {
                e.preventDefault();
                var action = $('#rb-bulk-action').val();
                var selectedIds = [];
                
                $('.rb-booking-checkbox:checked').each(function() {
                    selectedIds.push($(this).val());
                });
                
                if (selectedIds.length === 0) {
                    alert('Vui lòng chọn ít nhất một đặt bàn.');
                    return;
                }
                
                if (action === 'delete' && !confirm('Bạn có chắc muốn xóa các đặt bàn đã chọn?')) {
                    return;
                }
                
                // Process bulk action
                processBulkAction(action, selectedIds);
            });
        }
        
        /**
         * Process bulk action
         */
        function processBulkAction(action, ids) {
            $.ajax({
                url: rb_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'rb_admin_bulk_action',
                    bulk_action: action,
                    booking_ids: ids,
                    nonce: rb_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        showNotice('success', response.data.message);
                        location.reload();
                    } else {
                        showNotice('error', response.data.message);
                    }
                }
            });
        }
        
        /**
         * Initialize settings
         */
        function initSettings() {
            // Time slot preview
            $('#rb-time-slot-interval').on('change', function() {
                var interval = $(this).val();
                var openingTime = $('#rb-opening-time').val();
                var closingTime = $('#rb-closing-time').val();
                
                if (openingTime && closingTime) {
                    generateTimeSlotPreview(openingTime, closingTime, interval);
                }
            });
            
            // Test email
            $('#rb-test-email').on('click', function(e) {
                e.preventDefault();
                sendTestEmail();
            });
        }
        
        /**
         * Initialize reports
         */
        function initReports() {
            // Export reports
            $('#rb-export-csv').on('click', function(e) {
                e.preventDefault();
                exportToCSV();
            });
            
            $('#rb-export-pdf').on('click', function(e) {
                e.preventDefault();
                exportToPDF();
            });
            
            // Refresh stats
            $('#rb-refresh-stats').on('click', function(e) {
                e.preventDefault();
                refreshStatistics();
            });
        }
        
        /**
         * Helper Functions
         */
        
        function showNotice(type, message) {
            var noticeHtml = `
                <div class="notice notice-${type} is-dismissible rb-admin-notice">
                    <p>${message}</p>
                </div>
            `;
            
            $('.wrap h1').after(noticeHtml);
            
            setTimeout(function() {
                $('.rb-admin-notice').fadeOut();
            }, 5000);
        }
        
        function formatDate(dateStr) {
            var date = new Date(dateStr);
            return date.toLocaleDateString('vi-VN');
        }
        
        function formatDateTime(dateTimeStr) {
            var date = new Date(dateTimeStr);
            return date.toLocaleString('vi-VN');
        }
        
        function formatStatus(status) {
            var statuses = {
                'pending': 'Chờ xác nhận',
                'confirmed': 'Đã xác nhận',
                'cancelled': 'Đã hủy',
                'completed': 'Hoàn thành'
            };
            return statuses[status] || status;
        }
        
        function updateQueryStringParameter(uri, key, value) {
            var re = new RegExp("([?&])" + key + "=.*?(&|$)", "i");
            var separator = uri.indexOf('?') !== -1 ? "&" : "?";
            
            if (uri.match(re)) {
                return uri.replace(re, '$1' + key + "=" + value + '$2');
            } else {
                return uri + separator + key + "=" + value;
            }
        }
        
        // Add modal styles
        var modalStyles = `
            <style>
                .rb-modal-overlay {
                    position: fixed;
                    top: 0;
                    left: 0;
                    right: 0;
                    bottom: 0;
                    background: rgba(0,0,0,0.5);
                    z-index: 100000;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }
                .rb-modal {
                    background: white;
                    border-radius: 5px;
                    width: 90%;
                    max-width: 600px;
                    max-height: 90vh;
                    overflow: auto;
                }
                .rb-modal-header {
                    padding: 20px;
                    border-bottom: 1px solid #ddd;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                }
                .rb-modal-header h2 {
                    margin: 0;
                }
                .rb-modal-close {
                    background: none;
                    border: none;
                    font-size: 24px;
                    cursor: pointer;
                }
                .rb-modal-body {
                    padding: 20px;
                }
                .rb-modal-footer {
                    padding: 20px;
                    border-top: 1px solid #ddd;
                    text-align: right;
                }
            </style>
        `;
        
        $('head').append(modalStyles);
        
    });
    
})(jQuery);