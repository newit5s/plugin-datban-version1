(function ($) {
    'use strict';

    const allowedStatuses = ['available', 'occupied', 'cleaning', 'reserved'];

    class RBTimelineView {
        constructor(options) {
            this.$root = options.root;
            this.$container = this.$root.find('#rb-timeline-container');
            this.$loading = this.$root.find('#rb-timeline-loading');
            this.$notices = this.$root.find('#rb-timeline-notices');
            this.$locationSelect = this.$root.find('#rb-timeline-location');
            this.$dateInput = this.$root.find('#rb-timeline-date');
            this.$autoRefreshToggle = this.$root.find('#rb-timeline-auto-refresh');
            this.$refreshButton = this.$root.find('#rb-timeline-refresh');
            this.$prevDay = this.$root.find('#rb-timeline-prev-day');
            this.$nextDay = this.$root.find('#rb-timeline-next-day');
            this.$lastRefreshed = this.$root.find('#rb-last-refreshed');
            this.$summary = this.$root.find('[data-rb-timeline-summary]');

            this.ajaxUrl = options.ajaxUrl;
            this.nonce = options.nonce;
            this.currentDate = options.date;
            this.currentLocationId = options.locationId;
            this.autoRefresh = options.autoRefresh;
            this.refreshInterval = options.refreshInterval;
            this.slotHeight = options.slotHeight;
            this.labels = options.labels || {};
            this.messages = options.messages || {};
            this.statusLabels = options.statuses || {};
            this.ajaxAction = options.ajaxAction || 'rb_get_timeline_data';
            this.statusAction = options.statusAction || 'rb_update_table_status';
            this.moveAction = options.moveAction || 'rb_move_timeline_booking';

            this.intervalMinutes = 30;
            this.refreshHandle = null;
            this.isLoading = false;
            this.dragState = null;
            this.timelineStartSlot = null;
            this.timelineEndSlot = null;
            this.timelinePixelsPerMinute = this.slotHeight / Math.max(this.intervalMinutes, 1);

            this.init();
        }

        init() {
            if (!this.ajaxUrl || !this.nonce) {
                return;
            }

            if (!this.currentDate) {
                this.currentDate = this.$dateInput.val() || new Date().toISOString().split('T')[0];
            }

            if (!this.currentLocationId) {
                this.currentLocationId = parseInt(this.$locationSelect.val(), 10) || 0;
            }

            this.$dateInput.val(this.currentDate);
            if (this.currentLocationId) {
                this.$locationSelect.val(String(this.currentLocationId));
            }

            this.$autoRefreshToggle.prop('checked', !!this.autoRefresh);
            this.updateLocationSummary();

            this.bindEvents();
            this.loadTimeline(true);

            if (this.autoRefresh) {
                this.startAutoRefresh();
            }
        }

        bindEvents() {
            this.$locationSelect.on('change', () => {
                this.currentLocationId = parseInt(this.$locationSelect.val(), 10) || 0;
                this.updateLocationSummary();
                this.loadTimeline();
            });

            this.$dateInput.on('change', () => {
                this.currentDate = this.$dateInput.val();
                this.loadTimeline();
            });

            this.$prevDay.on('click', () => {
                this.changeDate(-1);
            });

            this.$nextDay.on('click', () => {
                this.changeDate(1);
            });

            this.$refreshButton.on('click', () => {
                this.loadTimeline();
            });

            this.$autoRefreshToggle.on('change', () => {
                this.autoRefresh = this.$autoRefreshToggle.is(':checked');
                if (this.autoRefresh) {
                    this.startAutoRefresh();
                } else {
                    this.stopAutoRefresh();
                }
            });

            this.$container.on('change', '.rb-table-status-select', (event) => {
                this.handleStatusChange(event);
            });

            $(window).on('beforeunload', () => this.stopAutoRefresh());
        }

        startAutoRefresh() {
            this.stopAutoRefresh();
            if (!this.refreshInterval) {
                return;
            }

            this.refreshHandle = window.setInterval(() => {
                this.loadTimeline(false);
            }, this.refreshInterval);
        }

        stopAutoRefresh() {
            if (this.refreshHandle) {
                window.clearInterval(this.refreshHandle);
                this.refreshHandle = null;
            }
        }

        changeDate(days) {
            const current = new Date(this.currentDate);
            if (Number.isNaN(current.getTime())) {
                return;
            }
            current.setDate(current.getDate() + days);
            const iso = current.toISOString().split('T')[0];
            this.currentDate = iso;
            this.$dateInput.val(iso);
            this.loadTimeline();
        }

        loadTimeline(showLoading = true) {
            if (this.isLoading || !this.currentLocationId || !this.currentDate) {
                if (!this.currentLocationId || !this.currentDate) {
                    this.showNotice(this.messages.loadError || 'Invalid timeline parameters.', 'error');
                }
                return;
            }

            this.isLoading = true;
            this.clearNotice();

            if (showLoading) {
                this.showLoading();
            }

            $.ajax({
                url: this.ajaxUrl,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: this.ajaxAction || 'rb_get_timeline_data',
                    nonce: this.nonce,
                    date: this.currentDate,
                    location_id: this.currentLocationId
                }
            })
                .done((response) => {
                    if (response && response.success && response.data) {
                        this.renderTimeline(response.data);
                        this.updateLastRefreshed();
                    } else {
                        const message = response && response.data && response.data.message ? response.data.message : (this.messages.loadError || 'Unable to load timeline data.');
                        this.showNotice(message, 'error');
                        this.$container.empty().append(this.buildEmptyState(message));
                    }
                })
                .fail(() => {
                    this.showNotice(this.messages.loadError || 'Unable to load timeline data. Please try again.', 'error');
                })
                .always(() => {
                    this.hideLoading();
                    this.isLoading = false;
                });
        }

        renderTimeline(timeline) {
            if (!timeline || !timeline.tables) {
                this.$container.empty().append(this.buildEmptyState(this.messages.noTables || 'No tables found for this location.'));
                return;
            }

            const tables = Object.values(timeline.tables);
            if (!tables.length) {
                this.$container.empty().append(this.buildEmptyState(this.messages.noTables || 'No tables found for this location.'));
                return;
            }

            const timeSlots = Array.isArray(timeline.time_slots) ? timeline.time_slots : [];
            if (!timeSlots.length) {
                this.$container.empty().append(this.buildEmptyState(this.messages.noBookings || 'No bookings for the selected time range.'));
                return;
            }

            this.intervalMinutes = this.computeInterval(timeSlots);
            const slotHeight = this.slotHeight;
            const columnHeight = Math.max(timeSlots.length * slotHeight, slotHeight);
            const startSlot = timeSlots[0];
            const endSlot = timeSlots[timeSlots.length - 1];

            this.timelineStartSlot = startSlot;
            this.timelineEndSlot = endSlot;
            this.timelinePixelsPerMinute = this.getPixelsPerMinute();

            const $grid = $('<div/>', { class: 'rb-timeline-grid' });

            const $headerRow = $('<div/>', { class: 'rb-timeline-header-row' });
            $headerRow.append($('<div/>', {
                class: 'rb-timeline-time-header',
                text: this.labels.timeLabel || 'Time'
            }));

            const $headersWrapper = $('<div/>', { class: 'rb-timeline-table-headers' });
            tables.forEach((table) => {
                const status = this.normalizeStatus(table.current_status);
                const $header = $('<div/>', {
                    class: 'rb-timeline-table-header',
                    'data-table-id': table.id
                });

                $header.append($('<strong/>', { text: this.formatTableName(table) }));
                $header.append($('<span/>', {
                    class: 'rb-table-capacity',
                    text: this.formatCapacity(table.capacity)
                }));

                const $select = $('<select/>', {
                    class: 'rb-table-status-select',
                    'data-table-id': table.id
                });

                allowedStatuses.forEach((value) => {
                    const label = this.statusLabels[value] || value;
                    const $option = $('<option/>', {
                        value,
                        text: label,
                        selected: value === status
                    });
                    $select.append($option);
                });

                $select.data('status', status);
                $header.append($select);
                this.decorateHeaderStatus($header, status);
                $headersWrapper.append($header);
            });
            $headerRow.append($headersWrapper);

            const $body = $('<div/>', { class: 'rb-timeline-body' });
            const $timeColumn = $('<div/>', { class: 'rb-timeline-time-column' });
            timeSlots.forEach((slot) => {
                $timeColumn.append($('<div/>', {
                    class: 'rb-timeline-time-cell',
                    text: slot
                }));
            });
            $body.append($timeColumn);

            const $columnsWrapper = $('<div/>', { class: 'rb-timeline-table-columns' });
            let hasBookings = false;

            tables.forEach((table) => {
                const $column = $('<div/>', {
                    class: 'rb-timeline-column',
                    'data-table-id': table.id,
                    'data-table-number': table.table_number
                }).css('height', columnHeight + 'px');

                const bookings = Array.isArray(table.bookings) ? table.bookings : [];
                bookings.forEach((booking) => {
                    const $booking = this.createBookingElement(booking, startSlot, endSlot, columnHeight, table);
                    if ($booking) {
                        hasBookings = true;
                        $column.append($booking);
                    }
                });

                $columnsWrapper.append($column);
            });

            $body.append($columnsWrapper);

            $grid.append($headerRow, $body);
            this.$container.empty().append($grid);
            this.enableDragAndDrop();

            if (!hasBookings) {
                this.showNotice(this.messages.noBookings || 'No bookings for the selected time range.', 'info');
            }
        }

        createBookingElement(booking, startSlot, endSlot, columnHeight, table) {
            const status = this.normalizeStatus(booking.status);
            const checkin = booking.actual_checkin || booking.checkin_time;
            const checkout = booking.actual_checkout || booking.checkout_time;

            const startMinutes = this.parseTimeToMinutes(startSlot);
            const endMinutes = this.parseTimeToMinutes(endSlot);
            const checkinMinutes = this.parseTimeToMinutes(checkin);
            let checkoutMinutes = this.parseTimeToMinutes(checkout);

            if (checkinMinutes === null || startMinutes === null) {
                return null;
            }

            if (checkoutMinutes === null || checkoutMinutes <= checkinMinutes) {
                checkoutMinutes = checkinMinutes + this.intervalMinutes;
            }

            const totalMinutes = this.computeTotalMinutes(startMinutes, endMinutes);
            const minutesFromStart = Math.max(0, checkinMinutes - startMinutes);
            const bookingDuration = Math.max(this.intervalMinutes / 2, checkoutMinutes - checkinMinutes);
            const pixelsPerMinute = this.intervalMinutes > 0 ? (this.slotHeight / this.intervalMinutes) : 1;

            const top = minutesFromStart * pixelsPerMinute;
            const height = Math.max(this.slotHeight * 0.6, bookingDuration * pixelsPerMinute);

            const maxTop = Math.max(0, columnHeight - height - 4);
            const topPosition = Math.min(top, maxTop);

            const $booking = $('<div/>', {
                class: 'rb-timeline-booking ' + 'is-status-' + status,
                'data-booking-id': booking.booking_id || ''
            }).css({
                top: topPosition + 'px',
                height: height + 'px'
            });

            const timeLabel = this.buildBookingTimeLabel(booking);
            const metaParts = [];
            if (booking.guest_count) {
                metaParts.push(this.formatGuests(booking.guest_count));
            }
            if (booking.booking_source) {
                metaParts.push(booking.booking_source);
            }

            $booking.attr('title', [booking.customer_name, timeLabel, metaParts.join(' • ')].filter(Boolean).join('\n'));

            const plannedCheckin = this.normalizeTimeString(booking.checkin_time || booking.actual_checkin || '');
            const plannedCheckout = this.normalizeTimeString(booking.checkout_time || booking.actual_checkout || '');
            const durationMinutes = booking.duration_minutes || bookingDuration;

            $booking
                .attr('draggable', true)
                .data({
                    bookingId: booking.booking_id || '',
                    checkin: plannedCheckin,
                    checkout: plannedCheckout,
                    guestCount: booking.guest_count || 0,
                    tableId: table && table.id ? table.id : (booking.table_id || ''),
                    tableNumber: table && table.table_number ? table.table_number : (booking.table_number || ''),
                    durationMinutes: durationMinutes
                });

            $booking.append($('<div/>', { class: 'rb-booking-time', text: timeLabel }));
            $booking.append($('<div/>', { class: 'rb-booking-name', text: booking.customer_name || this.statusLabels[status] || '' }));
            if (metaParts.length) {
                $booking.append($('<div/>', { class: 'rb-booking-meta', text: metaParts.join(' • ') }));
            }

            return $booking;
        }

        handleStatusChange(event) {
            const $select = $(event.currentTarget);
            const tableId = parseInt($select.data('table-id'), 10);
            const newStatus = this.normalizeStatus($select.val());
            const previousStatus = this.normalizeStatus($select.data('status'));

            if (!tableId || !newStatus || allowedStatuses.indexOf(newStatus) === -1) {
                return;
            }

            if (newStatus === previousStatus || !this.statusAction) {
                return;
            }

            $select.prop('disabled', true);

            $.ajax({
                url: this.ajaxUrl,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: this.statusAction || 'rb_update_table_status',
                    nonce: this.nonce,
                    table_id: tableId,
                    status: newStatus
                }
            })
                .done((response) => {
                    if (response && response.success) {
                        $select.data('status', newStatus);
                        const $header = $select.closest('.rb-timeline-table-header');
                        this.decorateHeaderStatus($header, newStatus);
                        this.showNotice(this.messages.statusUpdated || 'Table status updated.', 'success');
                    } else {
                        const message = response && response.data && response.data.message ? response.data.message : (this.messages.statusUpdateError || 'Could not update table status.');
                        this.showNotice(message, 'error');
                        $select.val(previousStatus);
                    }
                })
                .fail(() => {
                    this.showNotice(this.messages.statusUpdateError || 'Could not update table status. Please try again.', 'error');
                    $select.val(previousStatus);
                })
                .always(() => {
                    $select.prop('disabled', false);
                });
        }

        enableDragAndDrop() {
            if (!this.moveAction) {
                return;
            }

            const $bookings = this.$container.find('.rb-timeline-booking');
            if (!$bookings.length) {
                return;
            }

            $bookings.attr('draggable', true);

            this.$container.off('.rbTimelineDrag');
            this.$container.on('dragstart.rbTimelineDrag', '.rb-timeline-booking', (event) => this.onBookingDragStart(event));
            this.$container.on('dragend.rbTimelineDrag', '.rb-timeline-booking', (event) => this.onBookingDragEnd(event));
            this.$container.on('dragover.rbTimelineDrag', '.rb-timeline-column', (event) => this.onColumnDragOver(event));
            this.$container.on('dragleave.rbTimelineDrag', '.rb-timeline-column', (event) => this.onColumnDragLeave(event));
            this.$container.on('drop.rbTimelineDrag', '.rb-timeline-column', (event) => this.onColumnDrop(event));
        }

        onBookingDragStart(event) {
            const $booking = $(event.currentTarget);
            const $column = $booking.closest('.rb-timeline-column');
            const bookingId = parseInt($booking.data('bookingId'), 10) || parseInt($booking.attr('data-booking-id'), 10) || 0;
            const tableId = parseInt($booking.data('tableId'), 10) || parseInt($column.data('table-id'), 10) || 0;
            const tableNumber = parseInt($booking.data('tableNumber'), 10) || parseInt($column.data('table-number'), 10) || 0;
            const checkin = this.normalizeTimeString($booking.data('checkin'));
            const checkout = this.normalizeTimeString($booking.data('checkout'));
            let duration = parseFloat($booking.data('durationMinutes'));
            if (!duration || Number.isNaN(duration)) {
                const checkinMinutes = this.parseTimeToMinutes(checkin);
                const checkoutMinutes = this.parseTimeToMinutes(checkout);
                if (checkinMinutes !== null && checkoutMinutes !== null) {
                    duration = checkoutMinutes - checkinMinutes;
                }
            }
            if (!duration || duration <= 0) {
                duration = this.intervalMinutes;
            }

            this.dragState = {
                bookingId,
                originalTableId: tableId,
                originalTableNumber: tableNumber,
                checkin,
                checkout,
                durationMinutes: duration,
                guestCount: parseInt($booking.data('guestCount'), 10) || 0
            };

            $booking.addClass('is-dragging');

            if (event.originalEvent && event.originalEvent.dataTransfer) {
                event.originalEvent.dataTransfer.effectAllowed = 'move';
                event.originalEvent.dataTransfer.setData('text/plain', bookingId ? String(bookingId) : '');
            }
        }

        onBookingDragEnd() {
            this.clearDragHighlights();
            this.dragState = null;
        }

        onColumnDragOver(event) {
            if (!this.dragState) {
                return;
            }

            event.preventDefault();
            if (event.originalEvent && event.originalEvent.dataTransfer) {
                event.originalEvent.dataTransfer.dropEffect = 'move';
            }
            $(event.currentTarget).addClass('is-drop-target');
        }

        onColumnDragLeave(event) {
            $(event.currentTarget).removeClass('is-drop-target');
        }

        onColumnDrop(event) {
            if (!this.dragState) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            const $column = $(event.currentTarget);
            const columnRect = event.currentTarget.getBoundingClientRect();
            const clientY = event.originalEvent ? event.originalEvent.clientY : event.clientY;
            const relativeY = clientY - columnRect.top;
            const columnHeight = $column.innerHeight();
            const clampedY = Math.min(Math.max(relativeY, 0), columnHeight);
            const startMinutes = this.parseTimeToMinutes(this.timelineStartSlot);
            const endMinutes = this.parseTimeToMinutes(this.timelineEndSlot);

            if (startMinutes === null || endMinutes === null) {
                this.clearDragHighlights();
                this.dragState = null;
                return;
            }

            const pixelsPerMinute = this.getPixelsPerMinute();
            const interval = this.intervalMinutes > 0 ? this.intervalMinutes : 15;
            const duration = this.dragState.durationMinutes || interval;

            let offsetMinutes = clampedY / pixelsPerMinute;
            offsetMinutes = Math.round(offsetMinutes / interval) * interval;

            let newStartMinutes = startMinutes + offsetMinutes;
            const latestStart = endMinutes - duration;
            if (!Number.isNaN(latestStart)) {
                if (newStartMinutes > latestStart) {
                    newStartMinutes = latestStart;
                }
            }

            if (newStartMinutes < startMinutes) {
                newStartMinutes = startMinutes;
            }

            let newCheckoutMinutes = newStartMinutes + duration;
            if (newCheckoutMinutes > endMinutes) {
                newStartMinutes = endMinutes - duration;
                if (newStartMinutes < startMinutes) {
                    newStartMinutes = startMinutes;
                }
                newCheckoutMinutes = newStartMinutes + duration;
            }

            const newCheckin = this.minutesToTime(newStartMinutes);
            const newCheckout = this.minutesToTime(newCheckoutMinutes);
            const tableId = parseInt($column.data('table-id'), 10) || 0;
            const tableNumber = parseInt($column.data('table-number'), 10) || 0;

            const originalCheckin = this.normalizeTimeString(this.dragState.checkin);
            const originalCheckout = this.normalizeTimeString(this.dragState.checkout);

            if (
                tableId === this.dragState.originalTableId &&
                newCheckin === originalCheckin &&
                newCheckout === originalCheckout
            ) {
                this.clearDragHighlights();
                this.dragState = null;
                return;
            }

            const payload = {
                bookingId: this.dragState.bookingId,
                tableId,
                tableNumber,
                checkin: newCheckin,
                checkout: newCheckout,
                guestCount: this.dragState.guestCount
            };

            this.clearDragHighlights();
            this.dragState = null;
            this.moveBooking(payload);
        }

        moveBooking(update) {
            if (!update || !update.bookingId || !this.moveAction) {
                return;
            }

            if (!this.nonce) {
                this.showNotice(this.messages.moveError || 'Unable to move booking.', 'error');
                return;
            }

            const data = {
                action: this.moveAction,
                nonce: this.nonce,
                booking_id: update.bookingId,
                location_id: this.currentLocationId,
                booking_date: this.currentDate,
                checkin_time: update.checkin,
                checkout_time: update.checkout
            };

            if (update.tableId) {
                data.table_id = update.tableId;
            }

            if (update.tableNumber) {
                data.table_number = update.tableNumber;
            }

            if (update.guestCount) {
                data.guest_count = update.guestCount;
            }

            $.ajax({
                url: this.ajaxUrl,
                method: 'POST',
                dataType: 'json',
                data
            })
                .done((response) => {
                    if (response && response.success) {
                        this.showNotice(this.messages.moveSuccess || this.messages.statusUpdated || 'Booking updated.', 'success');
                        this.showLoading();
                        this.loadTimeline(false);
                    } else {
                        const message = response && response.data && response.data.message ? response.data.message : (this.messages.moveError || 'Unable to move booking.');
                        this.showNotice(message, 'error');
                    }
                })
                .fail(() => {
                    this.showNotice(this.messages.moveError || 'Unable to move booking.', 'error');
                });
        }

        decorateHeaderStatus($header, status) {
            allowedStatuses.forEach((value) => {
                $header.removeClass('is-status-' + value);
            });
            $header.addClass('is-status-' + status);
        }

        clearDragHighlights() {
            this.$container.find('.rb-timeline-column').removeClass('is-drop-target');
            this.$container.find('.rb-timeline-booking.is-dragging').removeClass('is-dragging');
        }

        showLoading() {
            this.$loading.addClass('is-visible');
        }

        hideLoading() {
            this.$loading.removeClass('is-visible');
        }

        showNotice(message, type) {
            if (!message) {
                return;
            }
            const classes = ['notice-message'];
            if (type === 'error') {
                classes.push('notice-error');
            } else if (type === 'info') {
                classes.push('notice-info');
            } else {
                classes.push('notice-success');
            }

            const $message = $('<div/>', {
                class: classes.join(' '),
                text: message
            });

            this.$notices.empty().append($message);

            if (type !== 'error') {
                window.setTimeout(() => {
                    if ($message.is(':visible')) {
                        $message.fadeOut(200, () => $message.remove());
                    }
                }, 4000);
            }
        }

        clearNotice() {
            this.$notices.empty();
        }

        updateLastRefreshed() {
            if (!this.$lastRefreshed.length) {
                return;
            }

            const label = this.labels.lastUpdatedLabel || 'Last updated:';
            const now = new Date();
            this.$lastRefreshed.text(label + ' ' + now.toLocaleTimeString());
        }

        buildEmptyState(message) {
            return $('<div/>', {
                class: 'rb-timeline-empty',
                text: message || this.messages.noBookings || 'No data available.'
            });
        }

        normalizeStatus(status) {
            const normalized = (status || '').toString().toLowerCase();
            if (allowedStatuses.indexOf(normalized) === -1) {
                return 'available';
            }
            return normalized;
        }

        computeInterval(slots) {
            if (!Array.isArray(slots) || slots.length < 2) {
                return this.intervalMinutes;
            }
            const first = this.parseTimeToMinutes(slots[0]);
            for (let i = 1; i < slots.length; i += 1) {
                const next = this.parseTimeToMinutes(slots[i]);
                if (first !== null && next !== null) {
                    return Math.max(5, next - first);
                }
            }
            return this.intervalMinutes;
        }

        computeTotalMinutes(startMinutes, endMinutes) {
            if (startMinutes === null || endMinutes === null || endMinutes <= startMinutes) {
                return this.intervalMinutes;
            }
            return endMinutes - startMinutes;
        }

        getPixelsPerMinute() {
            if (this.intervalMinutes <= 0) {
                return this.slotHeight;
            }
            return this.slotHeight / this.intervalMinutes;
        }

        parseTimeToMinutes(time) {
            if (!time || typeof time !== 'string') {
                return null;
            }
            const parts = time.split(':');
            if (parts.length < 2) {
                return null;
            }
            const hours = parseInt(parts[0], 10);
            const minutes = parseInt(parts[1], 10);
            if (Number.isNaN(hours) || Number.isNaN(minutes)) {
                return null;
            }
            return (hours * 60) + minutes;
        }

        minutesToTime(totalMinutes) {
            if (typeof totalMinutes !== 'number' || Number.isNaN(totalMinutes)) {
                return '';
            }

            const minutes = Math.max(0, Math.round(totalMinutes));
            const hours = Math.floor(minutes / 60) % 24;
            const mins = minutes % 60;

            return String(hours).padStart(2, '0') + ':' + String(mins).padStart(2, '0');
        }

        normalizeTimeString(time) {
            if (!time && time !== 0) {
                return '';
            }

            if (typeof time === 'number') {
                return this.minutesToTime(time);
            }

            const str = String(time).trim();
            if (!str) {
                return '';
            }

            const parts = str.split(':');
            if (parts.length >= 2) {
                const hours = parts[0].padStart(2, '0');
                const minutes = parts[1].padStart(2, '0');
                return hours + ':' + minutes;
            }

            if (parts.length === 1 && parts[0].length) {
                const value = parts[0].padStart(4, '0');
                return value.slice(0, 2) + ':' + value.slice(2);
            }

            return str;
        }

        buildBookingTimeLabel(booking) {
            const plannedCheckin = booking.checkin_time || '';
            const plannedCheckout = booking.checkout_time || '';
            const actualCheckin = booking.actual_checkin || '';
            const actualCheckout = booking.actual_checkout || '';

            if (actualCheckin || actualCheckout) {
                const actualLabel = [actualCheckin, actualCheckout].filter(Boolean).join(' - ');
                const plannedLabel = [plannedCheckin, plannedCheckout].filter(Boolean).join(' - ');
                if (plannedLabel && plannedLabel !== actualLabel) {
                    return actualLabel + ' (' + plannedLabel + ')';
                }
                return actualLabel || plannedLabel;
            }

            return [plannedCheckin, plannedCheckout].filter(Boolean).join(' - ');
        }

        formatTableName(table) {
            const number = table.table_number ? ' #' + table.table_number : '';
            return (this.labels.tableLabel || 'Table') + number;
        }

        formatCapacity(capacity) {
            if (!capacity) {
                return '';
            }
            return capacity + ' ' + (this.labels.guestsLabel || 'guests');
        }

        formatGuests(guestCount) {
            return guestCount + ' ' + (this.labels.guestsLabel || 'guests');
        }

        updateLocationSummary() {
            if (!this.$summary || !this.$summary.length || !this.$locationSelect || !this.$locationSelect.length) {
                return;
            }

            const $option = this.$locationSelect.find('option:selected');
            if (!$option.length) {
                return;
            }

            const parts = [];
            const name = $option.data('name') || $option.text();
            const address = $option.data('address');
            const hotline = $option.data('hotline');

            if (name) {
                parts.push(name);
            }

            if (address) {
                parts.push(address);
            }

            if (hotline) {
                parts.push('📞 ' + hotline);
            }

            this.$summary.text(parts.join(' · '));
        }
    }

    $(document).ready(() => {
        const $root = $('#rb-timeline-root');
        if (!$root.length) {
            return;
        }

        const runtime = window.rbTimelineViewData || {};
        const l10n = window.rbTimelineViewL10n || {};

        const view = new RBTimelineView({
            root: $root,
            ajaxUrl: runtime.ajaxUrl || l10n.ajaxUrl || adminAjaxUrl(),
            nonce: runtime.nonce || '',
            date: runtime.initialDate || $root.data('initial-date'),
            locationId: parseInt(runtime.initialLocationId || $root.data('location-id'), 10) || 0,
            autoRefresh: runtime.autoRefresh !== undefined ? runtime.autoRefresh : ($root.data('auto-refresh') !== undefined),
            refreshInterval: runtime.refreshInterval || 30000,
            slotHeight: parseInt($root.find('#rb-timeline-container').data('slot-height'), 10) || 42,
            labels: {
                timeLabel: l10n.timeLabel || 'Time',
                tableLabel: l10n.tableLabel || 'Table',
                guestsLabel: l10n.guestsLabel || 'guests',
                lastUpdatedLabel: runtime.lastUpdatedLabel || (l10n.labels ? l10n.labels.lastUpdatedLabel : 'Last updated:')
            },
            messages: l10n.messages || {},
            statuses: l10n.statuses || {},
            ajaxAction: runtime.ajaxAction || $root.data('ajax-action') || 'rb_get_timeline_data',
            statusAction: runtime.statusAction || $root.data('status-action') || 'rb_update_table_status',
            moveAction: runtime.moveAction || $root.data('move-action') || ''
        });

        function adminAjaxUrl() {
            if (window.ajaxurl) {
                return window.ajaxurl;
            }
            return (runtime.ajaxUrl || '').toString();
        }

        return view;
    });
})(jQuery);
