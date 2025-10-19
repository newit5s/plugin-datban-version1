(function($) {
    'use strict';

    class RBTimelineBooking {
        constructor(config) {
            this.ajaxUrl = config.ajaxUrl;
            this.nonce = config.nonce;
            this.locations = config.locations || [];
            this.strings = config.strings || {};

            this.$location = $('#rb-new-location');
            this.$checkin = $('#rb-checkin-time');
            this.$duration = $('#rb-duration');
            this.$date = $('#rb-new-date');
            this.$guests = $('#rb-new-guests');
            this.$message = $('#rb-availability-message');

            this.init();
        }

        init() {
            if (!this.$location.length || !this.$checkin.length) {
                return;
            }

            this.populateTimeSlots();
            this.bindEvents();
            this.triggerReady();
        }

        triggerReady() {
            window.rbTimelineBookingInstance = this;
            $(document).trigger('rbTimeline:ready', [this]);
        }

        bindEvents() {
            this.$location.on('change', () => {
                this.populateTimeSlots();
                this.clearTimeDisplay();
                this.clearMessage();
            });

            this.$checkin.on('change', () => {
                this.updateCheckoutAndCleanup();
                this.clearMessage();
            });

            this.$duration.on('change', () => {
                this.updateCheckoutAndCleanup();
                this.clearMessage();
            });

            this.$date.on('change', () => {
                this.clearMessage();
            });

            this.$guests.on('change', () => {
                this.clearMessage();
            });

            $('#rb-check-availability').on('click', (event) => {
                event.preventDefault();
                this.checkAvailability();
            });

            this.$message.on('click', '.rb-alternative-time', (event) => {
                event.preventDefault();
                const $button = $(event.currentTarget);
                const checkin = $button.data('checkin');
                const checkout = $button.data('checkout');

                if (checkin) {
                    this.$checkin.val(checkin).trigger('change');
                }

                if (checkout) {
                    const duration = (this.timeToMinutes(checkout) - this.timeToMinutes(checkin)) / 60;
                    this.$duration.val(duration.toString());
                }

                this.checkAvailability();
            });
        }

        populateTimeSlots() {
            const locationId = parseInt(this.$location.val(), 10);
            const location = this.locations.find((item) => parseInt(item.id, 10) === locationId);

            if (!location) {
                return;
            }

            const slots = this.generateTimeSlots(
                location.opening_time,
                location.closing_time,
                location.time_slot_interval
            );

            this.$checkin.empty();
            this.$checkin.append(`<option value="">${this.getString('select_time_hint', '-- Select time --')}</option>`);

            slots.forEach((slot) => {
                this.$checkin.append(`<option value="${slot}">${slot}</option>`);
            });
        }

        generateTimeSlots(start, end, interval) {
            const slots = [];
            let current = this.timeToMinutes(start || '09:00');
            const endMinutes = this.timeToMinutes(end || '22:00');
            const step = interval > 0 ? interval : 30;

            while (current <= endMinutes) {
                slots.push(this.minutesToTime(current));
                current += step;
            }

            return slots;
        }

        timeToMinutes(time) {
            if (!time || time.indexOf(':') === -1) {
                return 0;
            }

            const parts = time.split(':').map(Number);
            return (parts[0] * 60) + parts[1];
        }

        minutesToTime(minutes) {
            const hours = Math.floor(minutes / 60) % 24;
            const mins = minutes % 60;
            return `${String(hours).padStart(2, '0')}:${String(mins).padStart(2, '0')}`;
        }

        updateCheckoutAndCleanup() {
            const checkinTime = this.$checkin.val();
            const duration = parseFloat(this.$duration.val());

            if (!checkinTime || !duration) {
                this.clearTimeDisplay();
                return;
            }

            $.ajax({
                url: this.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'rb_get_checkout_time',
                    checkin_time: checkinTime,
                    duration: duration,
                    nonce: this.nonce
                }
            }).done((response) => {
                if (response && response.success) {
                    $('#rb-display-checkin').text(checkinTime);
                    $('#rb-display-checkout').text(response.data.checkout_time);
                    $('#rb-display-cleanup').text(response.data.cleanup_end);
                }
            });
        }

        clearTimeDisplay() {
            $('#rb-display-checkin').text('--:--');
            $('#rb-display-checkout').text('--:--');
            $('#rb-display-cleanup').text('--:--');
        }

        clearMessage() {
            if (!this.$message.length) {
                return;
            }

            this.$message
                .removeClass('is-visible')
                .removeClass('rb-success-message rb-error-message')
                .attr('hidden', true)
                .empty();
        }

        checkAvailability() {
            const date = this.$date.val();
            const checkinTime = this.$checkin.val();
            const duration = parseFloat(this.$duration.val());
            const guests = parseInt(this.$guests.val(), 10);
            const locationId = parseInt(this.$location.val(), 10);

            if (!date || !checkinTime || !duration || !guests || !locationId) {
                this.showInlineError(this.getString('fillRequired', 'Please complete all required fields.'));
                return;
            }

            const checkoutMinutes = this.timeToMinutes(checkinTime) + (duration * 60);
            const checkoutTime = this.minutesToTime(checkoutMinutes);

            $.ajax({
                url: this.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'rb_check_availability_extended',
                    nonce: this.nonce,
                    date: date,
                    checkin_time: checkinTime,
                    checkout_time: checkoutTime,
                    duration: duration,
                    guest_count: guests,
                    location_id: locationId
                }
            }).done((response) => {
                if (!response) {
                    this.showInlineError(this.getString('connectionError', 'Connection error. Please try again.'));
                    return;
                }

                if (response.success && response.data) {
                    if (response.data.available) {
                        this.showAvailabilitySuccess(response.data, {
                            date,
                            checkinTime,
                            checkoutTime,
                            duration,
                            guests,
                            locationId
                        });
                    } else {
                        this.showAvailabilityFailed(response.data, {
                            date,
                            checkinTime,
                            duration,
                            guests,
                            locationId
                        });
                    }
                } else if (response.data && response.data.message) {
                    this.showInlineError(response.data.message);
                } else {
                    this.showInlineError(this.getString('connectionError', 'Connection error. Please try again.'));
                }
            }).fail(() => {
                this.showInlineError(this.getString('connectionError', 'Connection error. Please try again.'));
            });
        }

        showAvailabilitySuccess(data, context) {
            if (!this.$message.length) {
                return;
            }

            const availableCount = typeof data.available_tables !== 'undefined' ? data.available_tables : data.available_count;
            const locationName = this.$location.find('option:selected').text();
            const successMessage = `
                <div class="rb-success-message">
                    <strong>${this.getString('availability_available', '✅ Tables available!')}</strong>
                    <p>${this.interpolate(this.getString('availability_tables_count', '{count} tables available'), availableCount)}</p>
                    <button type="button" class="button button-primary rb-continue-booking">${this.getString('continue_booking', 'Continue booking')}</button>
                </div>
            `;

            this.$message
                .html(successMessage)
                .removeAttr('hidden')
                .addClass('is-visible');

            const payload = {
                date: context.date,
                locationId: context.locationId,
                locationName,
                guests: context.guests,
                checkin_time: data.checkin_time || context.checkinTime,
                checkout_time: data.checkout_time || context.checkoutTime,
                cleanup_time: data.cleanup_time || '',
                available_tables: availableCount,
                duration: context.duration
            };

            this.updateHiddenFields(payload);
            $(document).trigger('rbTimeline:availabilitySuccess', [payload]);

            this.$message.find('.rb-continue-booking').on('click', (event) => {
                event.preventDefault();
                $(document).trigger('rbTimeline:continueBooking', [payload]);
            });
        }

        showAvailabilityFailed(data, context) {
            if (!this.$message.length) {
                return;
            }

            let html = `
                <div class="rb-error-message">
                    <strong>${data.message || this.getString('availability_not_available', '❌ No tables available at this time')}</strong>
            `;

            if (data.alternatives && data.alternatives.length) {
                html += `<p>${this.getString('alternative_suggestions', '💡 Suggestions:')}</p>`;
                data.alternatives.forEach((alt) => {
                    html += `
                        <button type="button" class="rb-alternative-time" data-checkin="${alt.checkin}" data-checkout="${alt.checkout}">
                            ${alt.checkin} - ${alt.checkout}
                        </button>
                    `;
                });
            }

            html += '</div>';

            this.$message
                .html(html)
                .removeAttr('hidden')
                .addClass('is-visible');

            const payload = {
                date: context.date,
                locationId: context.locationId,
                locationName: this.$location.find('option:selected').text(),
                guests: context.guests,
                checkin_time: context.checkinTime,
                duration: context.duration,
                alternatives: data.alternatives || []
            };

            $(document).trigger('rbTimeline:availabilityFailed', [payload]);
        }

        showInlineError(message) {
            if (!this.$message.length) {
                alert(message);
                return;
            }

            this.$message
                .html(`<div class="rb-error-message"><strong>${message}</strong></div>`)
                .removeAttr('hidden')
                .addClass('is-visible');

            $(document).trigger('rbTimeline:availabilityFailed', [{ message }]);
        }

        updateHiddenFields(payload) {
            $('#rb-new-hidden-location').val(payload.locationId || '');
            $('#rb-new-hidden-date').val(payload.date || '');
            $('#rb-new-hidden-time').val(payload.checkin_time || '');
            $('#rb-new-hidden-checkin').val(payload.checkin_time || '');
            $('#rb-new-hidden-checkout').val(payload.checkout_time || '');
            $('#rb-new-hidden-duration').val(payload.duration || this.$duration.val() || '');
            $('#rb-new-hidden-guests').val(payload.guests || this.$guests.val() || '');
        }

        getString(key, fallback) {
            if (this.strings && this.strings[key]) {
                return this.strings[key];
            }

            if (window.rbBookingStrings && window.rbBookingStrings[key]) {
                return window.rbBookingStrings[key];
            }

            return fallback;
        }

        interpolate(template, count) {
            return template.replace('{count}', count);
        }
    }

    $(document).ready(function() {
        if (typeof rbBookingAjax === 'undefined') {
            return;
        }

        const config = {
            ajaxUrl: rbBookingAjax.ajaxUrl,
            nonce: rbBookingAjax.nonce,
            locations: rbBookingAjax.locations || [],
            strings: window.rbTimelineStrings || {}
        };

        new RBTimelineBooking(config);
    });

    window.RBTimelineBooking = RBTimelineBooking;
})(jQuery);
