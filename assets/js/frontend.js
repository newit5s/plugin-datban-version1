/**
 * Restaurant Booking - Frontend JavaScript
 */

(function($) {
    'use strict';
    
    $(document).ready(function() {

        if (typeof window.rb_ajax === 'undefined') {
            window.rb_ajax = {};
        }

        rb_ajax.error_text = rb_ajax.error_text || 'Something went wrong. Please try again.';
        rb_ajax.success_text = rb_ajax.success_text || 'Saved successfully.';
        rb_ajax.delete_confirm_text = rb_ajax.delete_confirm_text || 'Are you sure you want to delete this booking?';
        rb_ajax.confirm_text = rb_ajax.confirm_text || 'Confirm';
        rb_ajax.cancel_text = rb_ajax.cancel_text || 'Cancel';
        rb_ajax.complete_text = rb_ajax.complete_text || 'Complete';
        rb_ajax.edit_text = rb_ajax.edit_text || 'Edit';
        rb_ajax.delete_text = rb_ajax.delete_text || 'Delete';
        rb_ajax.bulk_cancel_confirm = rb_ajax.bulk_cancel_confirm || 'Cancel selected bookings?';
        rb_ajax.confirm_delete_table = rb_ajax.confirm_delete_table || 'Are you sure you want to delete this table?';
        rb_ajax.confirm_set_vip = rb_ajax.confirm_set_vip || 'Upgrade this customer to VIP?';
        rb_ajax.confirm_blacklist = rb_ajax.confirm_blacklist || 'Blacklist this customer?';
        rb_ajax.confirm_unblacklist = rb_ajax.confirm_unblacklist || 'Remove this customer from blacklist?';
        
        // Modal handling
        var modal = $('#rb-booking-modal');
        var openBtn = $('.rb-open-modal-btn');
        var closeBtn = $('.rb-close, .rb-close-modal');
        
        // Open modal
        openBtn.on('click', function(e) {
            e.preventDefault();
            modal.addClass('show');
            $('body').css('overflow', 'hidden');
        });
        
        // Close modal
        closeBtn.on('click', function(e) {
            e.preventDefault();
            modal.removeClass('show');
            $('body').css('overflow', 'auto');
            resetForm();
        });
        
        // Close modal when clicking outside
        $(window).on('click', function(e) {
            if ($(e.target).is(modal)) {
                modal.removeClass('show');
                $('body').css('overflow', 'auto');
                resetForm();
            }
        });
        
        // Handle booking form submission (modal)
        $('#rb-booking-form').on('submit', function(e) {
            e.preventDefault();
            submitBookingForm($(this), '#rb-form-message');
        });
        
        // Handle inline form submission
        $('#rb-booking-form-inline').on('submit', function(e) {
            e.preventDefault();
            submitBookingForm($(this), '#rb-form-message-inline');
        });
        
        // Date change - update available times
        $('#rb_booking_date, #rb_date_inline').on('change', function() {
            var date = $(this).val();
            var guestCount = $(this).closest('form').find('[name="guest_count"]').val();
            var timeSelect = $(this).closest('form').find('[name="booking_time"]');

            if (date && guestCount) {
                updateAvailableTimeSlots(date, guestCount, timeSelect);
            }
        });

        // Guest count change - update available times
        $('#rb_guest_count, #rb_guests_inline').on('change', function() {
            var guestCount = $(this).val();
            var date = $(this).closest('form').find('[name="booking_date"]').val();
            var timeSelect = $(this).closest('form').find('[name="booking_time"]');

            if (date && guestCount) {
                updateAvailableTimeSlots(date, guestCount, timeSelect);
            }
        });
        
        // Check availability button
        $('#rb-check-availability').on('click', function(e) {
            e.preventDefault();
            checkAvailability();
        });
        
        // Submit booking form
        function submitBookingForm(form, messageContainer) {
            var formData = form.serialize();
            var submitBtn = form.find('[type="submit"]');
            var originalText = submitBtn.text();
            var nonceField = form.find('[name="rb_nonce"], [name="rb_nonce_inline"], [name="rb_nonce_portal"]');
            var nonceValue = nonceField.length ? nonceField.val() : '';

            // Show loading state
            submitBtn.text(rb_ajax.loading_text).prop('disabled', true);
            form.addClass('rb-loading');

            // Clear previous messages
            $(messageContainer).removeClass('success error').hide();

            // AJAX request
            $.ajax({
                url: rb_ajax.ajax_url,
                type: 'POST',
                data: formData + '&action=rb_submit_booking&rb_nonce=' + nonceValue,
                success: function(response) {
                    if (response.success) {
                        // Show success message
                        $(messageContainer)
                            .removeClass('error')
                            .addClass('success')
                            .removeAttr('hidden')
                            .html(response.data.message)
                            .show();
                        
                        // Reset form
                        form[0].reset();
                        
                        // Close modal after 3 seconds if it's open
                        if (modal.hasClass('show')) {
                            setTimeout(function() {
                                modal.removeClass('show');
                                $('body').css('overflow', 'auto');
                                resetForm();
                            }, 3000);
                        }
                        
                        // Trigger custom event
                        $(document).trigger('rb_booking_success', [response.data]);
                        
                    } else {
                        // Show error message
                        $(messageContainer)
                            .removeClass('success')
                            .addClass('error')
                            .removeAttr('hidden')
                            .html(response.data.message)
                            .show();
                    }
                },
                error: function(xhr, status, error) {
                    $(messageContainer)
                        .removeClass('success')
                        .addClass('error')
                        .removeAttr('hidden')
                        .html(rb_ajax.error_text)
                        .show();
                },
                complete: function() {
                    // Remove loading state
                    submitBtn.text(originalText).prop('disabled', false);
                    form.removeClass('rb-loading');
                }
            });
        }
        
        // Update available time slots
        function updateAvailableTimeSlots(date, guestCount, timeSelect) {
            if (!timeSelect || !timeSelect.length) {
                return;
            }

            var form = timeSelect.closest('form');
            var locationField = form.find('[name="location_id"]');
            var locationId = locationField.length ? locationField.val() : '';

            if (!locationId && rb_ajax.default_location_id) {
                locationId = rb_ajax.default_location_id;
            }

            if (!locationId) {
                return;
            }

            $.ajax({
                url: rb_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'rb_get_time_slots',
                    date: date,
                    guest_count: guestCount,
                    location_id: locationId,
                    nonce: rb_ajax.nonce
                },
                success: function(response) {
                    if (response.success && response.data) {
                        var slots = response.data.slots;
                        var currentValue = timeSelect.val();

                        // Clear and rebuild options
                        timeSelect.empty();
                        timeSelect.append('<option value="">' + (rb_ajax.select_time_text || 'Select time') + '</option>');
                        
                        if (slots.length > 0) {
                            $.each(slots, function(i, slot) {
                                var selected = (slot === currentValue) ? ' selected' : '';
                                timeSelect.append('<option value="' + slot + '"' + selected + '>' + slot + '</option>');
                            });
                        } else {
                            timeSelect.append('<option value="">' + (rb_ajax.no_slots_text || 'No available times') + '</option>');
                        }
                    }
                }
            });
        }

        // Check availability
        function checkAvailability() {
            var date = $('#rb_booking_date').val();
            var time = $('#rb_booking_time').val();
            var guests = $('#rb_guest_count').val();
            var locationId = $('#rb-booking-form').find('[name="location_id"]').val() || rb_ajax.default_location_id || '';
            var resultDiv = $('#rb-availability-result');

            if (!date || !time || !guests || !locationId) {
                resultDiv
                    .removeClass('success')
                    .addClass('error')
                    .html(rb_ajax.missing_fields_text || 'Please select date, time, guests and location before checking availability.')
                    .show();
                return;
            }

            // Show loading
            resultDiv
                .removeClass('success error')
                .html(rb_ajax.loading_text || 'Checking...')
                .show();
            
            $.ajax({
                url: rb_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'rb_check_availability',
                    date: date,
                    time: time,
                    guests: guests,
                    location_id: locationId,
                    nonce: rb_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        if (response.data.available) {
                            resultDiv
                                .removeClass('error')
                                .addClass('success')
                                .html(response.data.message);
                        } else {
                            resultDiv
                                .removeClass('success')
                                .addClass('error')
                                .html(response.data.message);
                        }
                    } else {
                        resultDiv
                            .removeClass('success')
                            .addClass('error')
                            .html(response.data.message);
                    }
                },
                error: function() {
                    resultDiv
                        .removeClass('success')
                        .addClass('error')
                        .html(rb_ajax.error_text || 'Something went wrong. Please try again.');
                }
            });
        }

        // Reset form
        function resetForm() {
            var modalForm = $('#rb-booking-form');
            if (modalForm.length) {
                modalForm[0].reset();
            }
            $('#rb-form-message').removeClass('success error').hide();
            $('#rb-availability-result').removeClass('success error').hide();
        }
        
        // Form validation
        $('#rb-booking-form, #rb-booking-form-inline').find('input[type="tel"]').on('input', function() {
            // Only allow numbers
            this.value = this.value.replace(/[^0-9]/g, '');
        });
        
        // Set minimum date to today
        var today = new Date();
        var dd = String(today.getDate()).padStart(2, '0');
        var mm = String(today.getMonth() + 1).padStart(2, '0');
        var yyyy = today.getFullYear();
        today = yyyy + '-' + mm + '-' + dd;
        
        $('#rb_booking_date, #rb_date_inline').attr('min', today);
        
        // Set maximum date to 30 days from now
        var maxDate = new Date();
        maxDate.setDate(maxDate.getDate() + 30);
        var maxDd = String(maxDate.getDate()).padStart(2, '0');
        var maxMm = String(maxDate.getMonth() + 1).padStart(2, '0');
        var maxYyyy = maxDate.getFullYear();
        maxDate = maxYyyy + '-' + maxMm + '-' + maxDd;
        
        $('#rb_booking_date, #rb_date_inline').attr('max', maxDate);
        
        // Enhanced phone validation
        $('#rb-booking-form, #rb-booking-form-inline, #rb-portal-details-form').on('submit', function(e) {
            var phone = $(this).find('input[type="tel"]').val().replace(/\D+/g, '');

            if (phone.length < 8 || phone.length > 15) {
                e.preventDefault();

                var messageContainer = '#rb-form-message';
                if ($(this).attr('id') === 'rb-booking-form-inline') {
                    messageContainer = '#rb-form-message-inline';
                } else if ($(this).attr('id') === 'rb-portal-details-form') {
                    messageContainer = '#rb-portal-details-message';
                }

                $(messageContainer)
                    .removeClass('success')
                    .addClass('error')
                    .html(rb_ajax.invalid_phone_text || 'Invalid phone number. Please enter a valid phone number.')
                    .show();

                return false;
            }
        });

        // Multi-step portal handling
        var portalWrapper = $('.rb-portal');
        if (portalWrapper.length) {
            var locationsData = rb_ajax.locations || [];
            var selectedLanguage = rb_ajax.current_language || '';

            function supportsSessionStorage() {
                try {
                    return typeof window.sessionStorage !== 'undefined';
                } catch (error) {
                    return false;
                }
            }

            function findLocation(id) {
                id = parseInt(id, 10);
                for (var i = 0; i < locationsData.length; i++) {
                    if (parseInt(locationsData[i].id, 10) === id) {
                        return locationsData[i];
                    }
                }
                return null;
            }

            function parseTimeToMinutes(timeStr) {
                if (!timeStr) {
                    return null;
                }

                var parts = String(timeStr).split(':');
                if (parts.length < 2) {
                    return null;
                }

                var hours = parseInt(parts[0], 10);
                var minutes = parseInt(parts[1], 10);

                if (isNaN(hours) || isNaN(minutes)) {
                    return null;
                }

                return (hours * 60) + minutes;
            }

            function generateTimeSlotsForLocation(location) {
                if (!location) {
                    return [];
                }

                var startMinutes = parseTimeToMinutes(location.opening_time);
                var endMinutes = parseTimeToMinutes(location.closing_time);
                var interval = parseInt(location.time_slot_interval, 10);

                if (isNaN(interval) || interval <= 0) {
                    interval = 30;
                }

                if (startMinutes === null || endMinutes === null) {
                    return [];
                }

                var slots = [];
                for (var current = startMinutes; current <= endMinutes; current += interval) {
                    var hours = Math.floor(current / 60);
                    var mins = current % 60;
                    var hoursStr = hours < 10 ? '0' + hours : String(hours);
                    var minsStr = mins < 10 ? '0' + mins : String(mins);
                    slots.push(hoursStr + ':' + minsStr);
                }

                return slots;
            }

            function populateTimeOptions(locationId, selectedTime) {
                var timeSelect = $('#rb-portal-time');
                if (!timeSelect.length) {
                    return;
                }

                var placeholder = rb_ajax.select_time_text || 'Select time';
                var slots = generateTimeSlotsForLocation(findLocation(locationId));

                timeSelect.empty();
                timeSelect.append('<option value="">' + placeholder + '</option>');

                if (!slots.length) {
                    timeSelect.append('<option value="" disabled>' + (rb_ajax.no_slots_text || 'No available times') + '</option>');
                    return;
                }

                slots.forEach(function(slot) {
                    var selectedAttr = slot === selectedTime ? ' selected' : '';
                    timeSelect.append('<option value="' + slot + '"' + selectedAttr + '>' + slot + '</option>');
                });
            }

            function formatDate(dateObj) {
                var year = dateObj.getFullYear();
                var month = String(dateObj.getMonth() + 1).padStart(2, '0');
                var day = String(dateObj.getDate()).padStart(2, '0');
                return year + '-' + month + '-' + day;
            }

            function showPortalStep(step) {
                portalWrapper.find('.rb-portal-step').attr('hidden', true);
                portalWrapper.find('.rb-portal-step[data-step="' + step + '"]').removeAttr('hidden');
            }

            function updatePortalLocationSummary(location) {
                if (!location) {
                    return;
                }
                $('#rb-portal-location-hotline').text(location.hotline || '');
                $('#rb-portal-hotline-note').text(location.hotline ? ' ' + location.hotline : '');
                $('#rb-portal-location-address').text(location.address || '');
            }

            function setLanguage(language, options) {
                options = options || {};

                var availabilityLanguageField = $('#rb-portal-language-selected');
                var detailsLanguageField = $('#rb-portal-language-hidden');
                var currentLanguage = rb_ajax.current_language || '';
                var targetLanguage = language || selectedLanguage ||
                    (availabilityLanguageField.length ? availabilityLanguageField.val() : '') ||
                    (detailsLanguageField.length ? detailsLanguageField.val() : '') ||
                    currentLanguage;

                if (!targetLanguage) {
                    return false;
                }

                selectedLanguage = targetLanguage;

                if (availabilityLanguageField.length) {
                    availabilityLanguageField.val(targetLanguage);
                }

                if (detailsLanguageField.length) {
                    detailsLanguageField.val(targetLanguage);
                }

                var languageSelector = $('#rb-portal-language-selector');
                if (languageSelector.length) {
                    languageSelector.val(targetLanguage);
                }

                var needsPersist = !!options.persist;
                var resumeStep = options.resumeStep || null;

                if (needsPersist && targetLanguage !== currentLanguage) {
                    var storeState = function() {
                        if (!supportsSessionStorage()) {
                            return;
                        }

                        try {
                            sessionStorage.setItem('rbPortalSelectedLanguage', targetLanguage);

                            if (resumeStep) {
                                sessionStorage.setItem('rbPortalResumeStep', resumeStep);
                            } else {
                                sessionStorage.removeItem('rbPortalResumeStep');
                            }
                        } catch (storageError) {
                            // Ignore storage errors (e.g. private mode)
                        }
                    };

                    var fallbackNavigation = function() {
                        storeState();

                        try {
                            var fallbackUrl = new URL(window.location.href);
                            fallbackUrl.searchParams.set('rb_lang', targetLanguage);
                            window.location.href = fallbackUrl.toString();
                        } catch (urlError) {
                            var baseUrl = window.location.href.split('?')[0];
                            window.location.href = baseUrl + '?rb_lang=' + encodeURIComponent(targetLanguage);
                        }
                    };

                    storeState();

                    if (rb_ajax.language_nonce) {
                        $.ajax({
                            url: rb_ajax.ajax_url,
                            type: 'POST',
                            data: {
                                action: 'rb_switch_language',
                                language: targetLanguage,
                                nonce: rb_ajax.language_nonce
                            },
                            success: function(response) {
                                if (response && response.success) {
                                    window.location.reload();
                                } else {
                                    fallbackNavigation();
                                }
                            },
                            error: fallbackNavigation
                        });
                    } else {
                        fallbackNavigation();
                    }

                    return true;
                }

                if (needsPersist && supportsSessionStorage()) {
                    try {
                        sessionStorage.removeItem('rbPortalResumeStep');
                        sessionStorage.removeItem('rbPortalSelectedLanguage');
                    } catch (clearError) {
                        // Ignore storage errors
                    }
                }

                rb_ajax.current_language = targetLanguage;

                return false;
            }

            function applyLocationConstraints(locationId) {
                var location = findLocation(locationId);
                if (!location) {
                    return;
                }

                var minAdvance = parseInt(location.min_advance_booking, 10) || 2;
                var maxAdvance = parseInt(location.max_advance_booking, 10) || 30;

                var minDate = new Date();
                minDate.setHours(minDate.getHours() + minAdvance);
                var maxDate = new Date();
                maxDate.setDate(maxDate.getDate() + maxAdvance);

                $('#rb-portal-date').attr('min', formatDate(minDate));
                $('#rb-portal-date').attr('max', formatDate(maxDate));

                updatePortalLocationSummary(location);
                populateTimeOptions(locationId);
            }

            function resetAvailabilityState() {
                var resultDiv = $('#rb-portal-availability-result');
                var suggestionsWrap = $('#rb-portal-suggestions');
                var suggestionList = suggestionsWrap.find('.rb-portal-suggestion-list');
                var continueWrap = $('#rb-portal-availability-continue');

                resultDiv.removeClass('success error').text('').attr('hidden', true);
                suggestionsWrap.attr('hidden', true);
                suggestionList.empty();
                continueWrap.attr('hidden', true);
            }

            $('#rb-portal-start').on('click', function() {
                resetAvailabilityState();

                var currentLocation = $('#rb-portal-location').val();
                if (!currentLocation && locationsData.length) {
                    currentLocation = locationsData[0].id;
                    $('#rb-portal-location').val(currentLocation);
                }

                applyLocationConstraints(currentLocation);
                populateTimeOptions(currentLocation);
                showPortalStep('2');
            });

            $('#rb-portal-back-to-start').on('click', function() {
                resetAvailabilityState();
                showPortalStep('start');
            });

            $('#rb-portal-language-selector').on('change', function() {
                var language = $(this).val();
                if (!language) {
                    return;
                }

                var willReload = setLanguage(language, { persist: true, resumeStep: '2' });
                if (!willReload) {
                    resetAvailabilityState();
                }
            });

            $('#rb-portal-location').on('change', function() {
                var selectedLocation = $(this).val();
                applyLocationConstraints(selectedLocation);
                resetAvailabilityState();
                populateTimeOptions(selectedLocation);
            });

            $('#rb-portal-availability-form').on('submit', function(e) {
                e.preventDefault();

                var form = $(this);
                var resultDiv = $('#rb-portal-availability-result');
                var suggestionsWrap = $('#rb-portal-suggestions');
                var suggestionList = suggestionsWrap.find('.rb-portal-suggestion-list');
                var continueWrap = $('#rb-portal-availability-continue');

                resultDiv.removeClass('success error').text(rb_ajax.loading_text).removeAttr('hidden').show();
                suggestionsWrap.attr('hidden', true);
                suggestionList.empty();
                continueWrap.attr('hidden', true);

                $.ajax({
                    url: rb_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'rb_check_availability',
                        nonce: rb_ajax.nonce,
                        date: form.find('[name="booking_date"]').val(),
                        time: form.find('[name="booking_time"]').val(),
                        guests: form.find('[name="guest_count"]').val(),
                        location_id: form.find('[name="location_id"]').val()
                    },
                    success: function(response) {
                        if (response.success) {
                            if (response.data.available) {
                                resultDiv.removeClass('error').addClass('success').text(response.data.message);
                                resultDiv.removeAttr('hidden');
                                continueWrap.removeAttr('hidden');
                            } else {
                                resultDiv.removeClass('success').addClass('error').text(response.data.message);
                                resultDiv.removeAttr('hidden');
                                if (response.data.suggestions && response.data.suggestions.length) {
                                    suggestionsWrap.removeAttr('hidden');
                                    suggestionList.empty();
                                    response.data.suggestions.forEach(function(time) {
                                        suggestionList.append('<button type="button" class="rb-btn-secondary rb-portal-suggestion" data-time="' + time + '">' + time + '</button>');
                                    });
                                }
                            }
                        } else {
                            resultDiv.removeClass('success').addClass('error').text(response.data.message);
                            resultDiv.removeAttr('hidden');
                        }
                    },
                    error: function() {
                        resultDiv.removeClass('success').addClass('error').text(rb_ajax.error_text);
                        resultDiv.removeAttr('hidden');
                    }
                });
            });

            $(document).on('click', '.rb-portal-suggestion', function() {
                $('#rb-portal-time').val($(this).data('time'));
                $('#rb-portal-availability-form').trigger('submit');
            });

            $('#rb-portal-go-to-details').on('click', function() {
                var dateValue = $('#rb-portal-date').val();
                var timeValue = $('#rb-portal-time').val();
                var guestsValue = $('#rb-portal-guests').val();
                var locationId = $('#rb-portal-location').val();
                var location = findLocation(locationId);

                $('#rb-portal-location-hidden').val(locationId);
                $('#rb-portal-date-hidden').val(dateValue);
                $('#rb-portal-time-hidden').val(timeValue);
                $('#rb-portal-guests-hidden').val(guestsValue);

                updatePortalLocationSummary(location);

                showPortalStep('3');
            });

            $('#rb-portal-back-to-availability').on('click', function() {
                showPortalStep('2');
            });

            $('#rb-portal-details-form').on('submit', function(e) {
                e.preventDefault();
                submitBookingForm($(this), '#rb-portal-details-message');
            });

            // Initialize default state
            if (locationsData.length) {
                var initialLocation = $('#rb-portal-location').val() || locationsData[0].id;
                $('#rb-portal-location').val(initialLocation);
                applyLocationConstraints(initialLocation);
                populateTimeOptions(initialLocation, $('#rb-portal-time').val());
            }
            setLanguage(selectedLanguage || $('#rb-portal-language-selected').val());

            if (supportsSessionStorage()) {
                try {
                    var resumeStep = sessionStorage.getItem('rbPortalResumeStep');
                    var resumeLanguage = sessionStorage.getItem('rbPortalSelectedLanguage');

                        if (resumeStep && resumeLanguage) {
                            if (resumeLanguage === rb_ajax.current_language) {
                                if (resumeStep === '2') {
                                    resetAvailabilityState();
                                    populateTimeOptions($('#rb-portal-location').val());
                                }
                                showPortalStep(resumeStep);
                            }

                            sessionStorage.removeItem('rbPortalResumeStep');
                            sessionStorage.removeItem('rbPortalSelectedLanguage');
                        }
                } catch (resumeError) {
                    // Ignore storage errors
                }
            }
        }

        // Manager dashboard actions
        var managerWrapper = $('.rb-manager');
        if (managerWrapper.length) {
            var bookingDetail = $('#rb-manager-detail');
            var detailEmptyHtml = bookingDetail.length ? bookingDetail.html() : '';
            var bookingList = $('.rb-booking-item');
            var currentBookingId = null;

            function refreshBookingListCache() {
                bookingList = $('.rb-booking-item');
            }

            function escapeAttribute(value) {
                return String(value == null ? '' : value)
                    .replace(/&/g, '&amp;')
                    .replace(/"/g, '&quot;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;');
            }

            function escapeHtml(value) {
                return String(value == null ? '' : value)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            }

            function getBookingDataFromElement(item) {
                if (!item || !item.length) {
                    return null;
                }

                return {
                    id: String(item.data('bookingId') || item.attr('data-booking-id') || ''),
                    padded_id: String(item.data('paddedId') || item.attr('data-padded-id') || ''),
                    customer_name: String(item.data('customerName') || item.attr('data-customer-name') || ''),
                    customer_phone: String(item.data('customerPhone') || item.attr('data-customer-phone') || ''),
                    customer_email: String(item.data('customerEmail') || item.attr('data-customer-email') || ''),
                    booking_date: String(item.data('bookingDate') || item.attr('data-booking-date') || ''),
                    booking_time: String(item.data('bookingTime') || item.attr('data-booking-time') || ''),
                    date_display: String(item.data('dateDisplay') || item.attr('data-date-display') || ''),
                    guest_count: String(item.data('guestCount') || item.attr('data-guest-count') || ''),
                    booking_source: String(item.data('bookingSource') || item.attr('data-booking-source') || ''),
                    source_label: String(item.data('sourceLabel') || item.attr('data-source-label') || ''),
                    special_requests: String(item.data('specialRequests') || item.attr('data-special-requests') || ''),
                    admin_notes: String(item.data('adminNotes') || item.attr('data-admin-notes') || ''),
                    status: String(item.data('status') || item.attr('data-status') || ''),
                    status_label: String(item.data('statusLabel') || item.attr('data-status-label') || ''),
                    table_number: String(item.data('tableNumber') || item.attr('data-table-number') || ''),
                    created_display: String(item.data('createdDisplay') || item.attr('data-created-display') || '')
                };
            }

            function showEmptyDetail() {
                if (!bookingDetail.length) {
                    return;
                }

                var message = bookingDetail.data('emptyMessage') || '';
                if (message) {
                    bookingDetail.html('<div class="rb-detail-empty">👈 ' + escapeHtml(message) + '</div>');
                } else if (detailEmptyHtml) {
                    bookingDetail.html(detailEmptyHtml);
                } else {
                    bookingDetail.empty();
                }

                currentBookingId = null;
            }

            function renderBookingDetail(booking) {
                if (!bookingDetail.length) {
                    return;
                }

                if (!booking || !booking.id) {
                    showEmptyDetail();
                    return;
                }

                currentBookingId = String(booking.id);

                var contactLabel = bookingDetail.data('contactLabel') || 'Contact';
                var bookingLabel = bookingDetail.data('bookingLabel') || 'Booking details';
                var notesLabel = bookingDetail.data('notesLabel') || 'Notes';
                var actionsLabel = bookingDetail.data('actionsLabel') || 'Actions';
                var phoneLabel = bookingDetail.data('phoneLabel') || 'Phone';
                var emailLabel = bookingDetail.data('emailLabel') || 'Email';
                var dateLabel = bookingDetail.data('dateLabel') || 'Date';
                var timeLabel = bookingDetail.data('timeLabel') || 'Time';
                var guestsLabel = bookingDetail.data('guestsLabel') || 'Guests';
                var sourceLabel = bookingDetail.data('sourceLabel') || 'Source';
                var tableLabel = bookingDetail.data('tableLabel') || 'Table';
                var createdLabel = bookingDetail.data('createdLabel') || 'Created';
                var specialLabel = bookingDetail.data('specialLabel') || 'Special requests';
                var internalLabel = bookingDetail.data('internalLabel') || 'Internal notes';

                var subtitleParts = [];
                if (booking.padded_id || booking.id) {
                    subtitleParts.push('#' + escapeHtml(booking.padded_id || booking.id));
                }
                if (booking.date_display || booking.booking_date) {
                    subtitleParts.push(escapeHtml(booking.date_display || booking.booking_date));
                }
                if (booking.booking_time) {
                    subtitleParts.push(escapeHtml(booking.booking_time));
                }
                var subtitle = subtitleParts.join(' • ');

                var statusLabel = booking.status_label || booking.status || '';
                var phoneValue = booking.customer_phone ? '<a href="tel:' + escapeAttribute(booking.customer_phone) + '">' + escapeHtml(booking.customer_phone) + '</a>' : '—';
                var emailValue = booking.customer_email ? '<a href="mailto:' + escapeAttribute(booking.customer_email) + '">' + escapeHtml(booking.customer_email) + '</a>' : '—';
                var guestsValue = booking.guest_count ? escapeHtml(booking.guest_count) : '0';
                var tableValue = booking.table_number ? escapeHtml(booking.table_number) : '—';
                var createdValue = booking.created_display ? escapeHtml(booking.created_display) : '';
                var sourceValue = booking.source_label ? escapeHtml(booking.source_label) : escapeHtml(booking.booking_source || '');

                var contactHtml = '<div class="rb-detail-section"><h4 class="rb-detail-section-title">' + escapeHtml(contactLabel) + '</h4>' +
                    '<div class="rb-detail-grid">' +
                        '<div class="rb-detail-row"><span class="rb-detail-label">' + escapeHtml(phoneLabel) + '</span><span class="rb-detail-value">' + phoneValue + '</span></div>' +
                        '<div class="rb-detail-row"><span class="rb-detail-label">' + escapeHtml(emailLabel) + '</span><span class="rb-detail-value">' + emailValue + '</span></div>' +
                    '</div></div>';

                var bookingInfoHtml = '<div class="rb-detail-section"><h4 class="rb-detail-section-title">' + escapeHtml(bookingLabel) + '</h4>' +
                    '<div class="rb-detail-grid">' +
                        '<div class="rb-detail-row"><span class="rb-detail-label">' + escapeHtml(dateLabel) + '</span><span class="rb-detail-value large">' + escapeHtml(booking.date_display || booking.booking_date || '') + '</span></div>' +
                        '<div class="rb-detail-row"><span class="rb-detail-label">' + escapeHtml(timeLabel) + '</span><span class="rb-detail-value large">' + escapeHtml(booking.booking_time || '') + '</span></div>' +
                        '<div class="rb-detail-row"><span class="rb-detail-label">' + escapeHtml(guestsLabel) + '</span><span class="rb-detail-value large">' + guestsValue + ' 👥</span></div>' +
                        '<div class="rb-detail-row"><span class="rb-detail-label">' + escapeHtml(sourceLabel) + '</span><span class="rb-detail-value">' + sourceValue + '</span></div>' +
                        '<div class="rb-detail-row"><span class="rb-detail-label">' + escapeHtml(tableLabel) + '</span><span class="rb-detail-value">' + tableValue + '</span></div>' +
                        '<div class="rb-detail-row"><span class="rb-detail-label">' + escapeHtml(createdLabel) + '</span><span class="rb-detail-value">' + createdValue + '</span></div>' +
                    '</div></div>';

                var notesHtml = '';
                if (booking.special_requests) {
                    notesHtml += '<div class="rb-detail-row"><span class="rb-detail-label">' + escapeHtml(specialLabel) + '</span><span class="rb-detail-value">' + escapeHtml(booking.special_requests) + '</span></div>';
                }
                if (booking.admin_notes) {
                    notesHtml += '<div class="rb-detail-row"><span class="rb-detail-label">' + escapeHtml(internalLabel) + '</span><span class="rb-detail-value">' + escapeHtml(booking.admin_notes) + '</span></div>';
                }

                var notesSection = notesHtml ? '<div class="rb-detail-section"><h4 class="rb-detail-section-title">' + escapeHtml(notesLabel) + '</h4>' + notesHtml + '</div>' : '';

                var actionsHtml = buildActionButtons(booking.status, booking.id);
                var actionsSection = '<div class="rb-detail-section"><h4 class="rb-detail-section-title">' + escapeHtml(actionsLabel) + '</h4><div class="rb-detail-actions">' + actionsHtml + '</div></div>';

                bookingDetail.html(
                    '<div class="rb-detail-header">' +
                        '<div class="rb-detail-header-row">' +
                            '<div>' +
                                '<h3 class="rb-detail-title">' + escapeHtml(booking.customer_name || '') + '</h3>' +
                                '<p class="rb-detail-subtitle">' + subtitle + '</p>' +
                            '</div>' +
                            '<span class="rb-detail-status-badge rb-booking-badge ' + escapeAttribute(booking.status || '') + '">' + escapeHtml(statusLabel) + '</span>' +
                        '</div>' +
                    '</div>' +
                    '<div class="rb-detail-body">' +
                        contactHtml +
                        bookingInfoHtml +
                        notesSection +
                        actionsSection +
                    '</div>'
                );
            }

            function selectBookingItem(item, scrollIntoView) {
                if (!item || !item.length) {
                    showEmptyDetail();
                    return;
                }

                refreshBookingListCache();
                bookingList.removeClass('active');
                item.addClass('active');

                var data = getBookingDataFromElement(item);
                renderBookingDetail(data);
                $(document).trigger('rb:manager:bookingSelected', [item]);

                if (scrollIntoView && item.length && item[0].scrollIntoView) {
                    try {
                        item[0].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                    } catch (scrollError) {
                        item[0].scrollIntoView();
                    }
                }
            }

            function filterBookingList(term) {
                var query = String(term || '').toLowerCase();
                refreshBookingListCache();

                bookingList.each(function() {
                    var el = $(this);
                    var name = String(el.data('customerName') || el.attr('data-customer-name') || '').toLowerCase();
                    var phone = String(el.data('customerPhone') || el.attr('data-customer-phone') || '').toLowerCase();
                    var email = String(el.data('customerEmail') || el.attr('data-customer-email') || '').toLowerCase();
                    var match = !query || name.indexOf(query) !== -1 || phone.indexOf(query) !== -1 || email.indexOf(query) !== -1;
                    el.toggle(match);
                });

                var active = bookingList.filter('.active');
                if (active.length && !active.is(':visible')) {
                    var nextVisible = bookingList.filter(':visible').first();
                    if (nextVisible.length) {
                        selectBookingItem(nextVisible, false);
                    } else {
                        showEmptyDetail();
                    }
                }
            }

            var searchInput = $('.rb-manager-filter-search .rb-list-search input[name="search"]');
            if (searchInput.length) {
                var searchTimeout = null;
                searchInput.on('input', function() {
                    var value = $(this).val();
                    window.clearTimeout(searchTimeout);
                    searchTimeout = window.setTimeout(function() {
                        filterBookingList(value);
                    }, 150);
                });

                filterBookingList(searchInput.val());
            }

            managerWrapper.on('click', '.rb-booking-item', function(e) {
                e.preventDefault();
                selectBookingItem($(this));
            });

            if (bookingList.length) {
                selectBookingItem(bookingList.first(), false);
            } else {
                showEmptyDetail();
            }

            function showManagerFeedback(container, type, message) {
                container
                    .removeClass('success error warning')
                    .addClass(type)
                    .text(message)
                    .removeAttr('hidden')
                    .show();
            }

            function getManagerNonce() {
                var feedback = $('#rb-manager-feedback');
                var nonce = feedback.data('nonce');
                return nonce ? nonce : rb_ajax.nonce;
            }

            function buildActionButtons(status, bookingId) {
                var label = escapeAttribute(rb_ajax.booking_actions_label || 'Booking actions');
                var buttons = ['<div class="rb-manager-action-stack" role="group" aria-label="' + label + '">'];
                if (status === 'pending') {
                    buttons.push('<button class="rb-btn-success rb-manager-action" data-action="confirm" data-id="' + bookingId + '">' + (rb_ajax.confirm_text || 'Confirm') + '</button>');
                    buttons.push('<button class="rb-btn-danger rb-manager-action" data-action="cancel" data-id="' + bookingId + '">' + (rb_ajax.cancel_text || 'Cancel') + '</button>');
                }
                if (status === 'confirmed') {
                    buttons.push('<button class="rb-btn-danger rb-manager-action" data-action="cancel" data-id="' + bookingId + '">' + (rb_ajax.cancel_text || 'Cancel') + '</button>');
                    buttons.push('<button class="rb-btn-secondary rb-manager-action" data-action="complete" data-id="' + bookingId + '">' + (rb_ajax.complete_text || 'Complete') + '</button>');
                }
                buttons.push('<button class="rb-btn-secondary rb-manager-edit-booking" data-id="' + bookingId + '">' + (rb_ajax.edit_text || 'Edit') + '</button>');
                buttons.push('<button class="rb-btn-danger rb-manager-action" data-action="delete" data-id="' + bookingId + '">' + (rb_ajax.delete_text || 'Delete') + '</button>');
                buttons.push('</div>');
                return buttons.join('');
            }

            function updateBookingRow(row, booking) {
                if (!booking) {
                    return;
                }

                var item = row;
                if (!item || !item.length) {
                    return;
                }

                if (!item.hasClass('rb-booking-item')) {
                    item = $('.rb-booking-item[data-booking-id="' + booking.id + '"]');
                    if (!item.length) {
                        return;
                    }
                }

                var paddedId = booking.padded_id || booking.id || '';
                var dateDisplay = booking.date_display || booking.booking_date || '';
                var statusLabel = booking.status_label || booking.status || '';
                var status = booking.status || '';
                var sourceLabel = booking.source_label || booking.booking_source || '';
                var createdDisplay = booking.created_display || booking.created_at || '';
                var guestCount = booking.guest_count || '';

                item.attr('data-booking-id', booking.id || '');
                item.attr('data-padded-id', paddedId);
                item.attr('data-customer-name', booking.customer_name || '');
                item.attr('data-customer-phone', booking.customer_phone || '');
                item.attr('data-customer-email', booking.customer_email || '');
                item.attr('data-booking-date', booking.booking_date || '');
                item.attr('data-booking-time', booking.booking_time || '');
                item.attr('data-date-display', dateDisplay);
                item.attr('data-guest-count', guestCount);
                item.attr('data-booking-source', booking.booking_source || '');
                item.attr('data-source-label', sourceLabel);
                item.attr('data-special-requests', booking.special_requests || '');
                item.attr('data-admin-notes', booking.admin_notes || '');
                item.attr('data-status', status);
                item.attr('data-status-label', statusLabel);
                item.attr('data-table-number', booking.table_number || '');
                item.attr('data-created-display', createdDisplay);

                item.data('bookingId', booking.id || '');
                item.data('paddedId', paddedId);
                item.data('customerName', booking.customer_name || '');
                item.data('customerPhone', booking.customer_phone || '');
                item.data('customerEmail', booking.customer_email || '');
                item.data('bookingDate', booking.booking_date || '');
                item.data('bookingTime', booking.booking_time || '');
                item.data('dateDisplay', dateDisplay);
                item.data('guestCount', guestCount);
                item.data('bookingSource', booking.booking_source || '');
                item.data('sourceLabel', sourceLabel);
                item.data('specialRequests', booking.special_requests || '');
                item.data('adminNotes', booking.admin_notes || '');
                item.data('status', status);
                item.data('statusLabel', statusLabel);
                item.data('tableNumber', booking.table_number || '');
                item.data('createdDisplay', createdDisplay);

                var initials = '';
                if (booking.customer_name) {
                    initials = booking.customer_name.trim().charAt(0).toUpperCase();
                }

                var avatar = item.find('.rb-booking-item-avatar');
                if (avatar.length) {
                    avatar.text(initials || '•');
                }

                item.find('.rb-booking-item-name').text(booking.customer_name || '');
                var combinedTime = dateDisplay || '';
                if (booking.booking_time) {
                    combinedTime = combinedTime ? combinedTime + ' ' + booking.booking_time : booking.booking_time;
                }
                item.find('.rb-booking-item-time').text(combinedTime);
                var slotSpan = item.find('.rb-booking-item-slot');
                if (slotSpan.length) {
                    slotSpan.text(booking.booking_time || '');
                }
                var idBadge = item.find('.rb-booking-card-id');
                if (idBadge.length) {
                    idBadge.text(paddedId ? '#' + paddedId : (booking.id ? '#' + booking.id : ''));
                }

                var phoneSpan = item.find('[data-meta="phone"]');
                if (phoneSpan.length) {
                    var phoneText = booking.customer_phone ? '📞 ' + booking.customer_phone : '📞';
                    phoneSpan.text(phoneText.trim());
                }

                var statusBadge = item.find('[data-meta="status"]');
                if (statusBadge.length) {
                    statusBadge.removeClass('pending confirmed completed cancelled').addClass(status || '');
                    statusBadge.text(statusLabel);
                }

                var guestsSpan = item.find('[data-meta="guests"]');
                if (guestsSpan.length) {
                    var guestsLabel = guestCount ? '👥 ' + guestCount : '👥 0';
                    guestsSpan.text(guestsLabel);
                }

                var sourceSpan = item.find('[data-meta="source"]');
                if (sourceSpan.length) {
                    sourceSpan.text(sourceLabel || '');
                }

                var createdSpan = item.find('[data-meta="created"]');
                if (createdSpan.length) {
                    createdSpan.text(createdDisplay ? '⏱ ' + createdDisplay : '');
                }

                var noteParagraph = item.find('[data-meta="special"]');
                if (noteParagraph.length) {
                    if (booking.special_requests) {
                        noteParagraph.text('📝 ' + booking.special_requests);
                    } else {
                        noteParagraph.text('');
                    }
                }

                item.removeClass(function(index, className) {
                    return (className.match(/status-[^\s]+/g) || []).join(' ');
                }).addClass('status-' + (status || ''));

                refreshBookingListCache();

                if (currentBookingId && String(currentBookingId) === String(booking.id)) {
                    renderBookingDetail(getBookingDataFromElement(item));
                }

                $(document).trigger('rb:manager:bookingUpdated', [booking]);
            }

            var customerDetail = $('#rb-customer-detail');
            var customerLists = $('.rb-customer-list');
            var customerNumberFormatter = null;

            function formatCustomerNumber(value) {
                var numeric = parseFloat(value);
                if (isNaN(numeric)) {
                    numeric = 0;
                }

                if (!customerNumberFormatter) {
                    try {
                        var intlFormatter = new Intl.NumberFormat(rb_ajax.locale || undefined);
                        customerNumberFormatter = function(num) {
                            return intlFormatter.format(num);
                        };
                    } catch (error) {
                        customerNumberFormatter = function(num) {
                            return String(num);
                        };
                    }
                }

                return customerNumberFormatter(numeric);
            }

            function decodeCustomerField(value) {
                if (!value) {
                    return '';
                }

                try {
                    return decodeURIComponent(value);
                } catch (error) {
                    return value;
                }
            }

            function truncateCustomerNote(note) {
                if (!note) {
                    return '';
                }

                var trimmed = String(note).trim();
                if (trimmed.length > 120) {
                    return trimmed.substring(0, 117).trim() + '…';
                }

                return trimmed;
            }

            function updateCustomerNotePreview(customerId, note) {
                if (!customerLists.length) {
                    return;
                }

                var $item = customerLists.find('.rb-inbox-item[data-customer-id="' + customerId + '"]').first();
                if (!$item.length) {
                    return;
                }

                var encoded = note ? encodeURIComponent(note) : '';
                $item.attr('data-notes', encoded);

                var $noteRow = $item.find('[data-note-preview]');
                var $noteText = $noteRow.find('[data-note-text]');

                if (note && note.trim().length) {
                    $noteRow.removeAttr('hidden');
                    $noteText.text(truncateCustomerNote(note));
                } else {
                    $noteRow.attr('hidden', 'hidden');
                    $noteText.text('');
                }
            }

            function populateManagerCustomerDetail($item) {
                if (!customerDetail.length || !$item || !$item.length) {
                    return;
                }

                var customerId = $item.attr('data-customer-id') || '';
                customerDetail.attr('data-active-id', customerId);
                customerDetail.attr('aria-hidden', 'false');

                var $empty = customerDetail.find('.rb-inbox-detail-empty');
                var $body = customerDetail.find('.rb-inbox-detail-body');
                $empty.hide();
                $body.removeAttr('hidden');

                var name = $item.attr('data-name') || '';
                var phone = $item.attr('data-phone') || '';
                var email = $item.attr('data-email') || '';
                var total = $item.attr('data-total') || '0';
                var completed = $item.attr('data-completed') || '0';
                var cancelled = $item.attr('data-cancelled') || '0';
                var noShows = $item.attr('data-no-shows') || '0';
                var successRate = $item.attr('data-success-rate') || '0';
                var problemRate = $item.attr('data-problem-rate') || '0';
                var firstVisit = $item.attr('data-first-visit') || '—';
                var lastVisit = $item.attr('data-last-visit') || '—';
                var notes = decodeCustomerField($item.attr('data-notes'));
                var isVip = $item.attr('data-is-vip') === '1';
                var isBlacklisted = $item.attr('data-is-blacklisted') === '1';
                var isLoyal = $item.attr('data-is-loyal') === '1';
                var isProblem = $item.attr('data-is-problem') === '1';
                var canPromoteVip = $item.attr('data-can-promote-vip') === '1';
                var problemCount = $item.attr('data-problem-count') || '0';
                var historyPhone = $item.attr('data-history-phone') || phone;

                customerDetail.find('[data-field="name"]').text(name);

                var $phoneLink = customerDetail.find('[data-field="phone"]').text(phone);
                if (phone) {
                    $phoneLink.attr('href', 'tel:' + phone).show();
                } else {
                    $phoneLink.removeAttr('href').hide();
                }

                var $emailLink = customerDetail.find('[data-field="email"]').text(email);
                if (email) {
                    $emailLink.attr('href', 'mailto:' + email).show();
                } else {
                    $emailLink.removeAttr('href').hide();
                }

                customerDetail.find('[data-field="total"]').text(formatCustomerNumber(total));
                customerDetail.find('[data-field="completed"]').text(formatCustomerNumber(completed));
                customerDetail.find('[data-field="problem-count"]').text(formatCustomerNumber(problemCount));
                customerDetail.find('[data-field="success-rate"]').text((successRate || '0') + '%');
                customerDetail.find('[data-field="problem-rate"]').text((problemRate || '0') + '%');
                customerDetail.find('[data-field="last-visit"]').text(lastVisit || '—');
                customerDetail.find('[data-field="first-visit"]').text(firstVisit || '—');
                customerDetail.find('[data-field="cancelled"]').text(formatCustomerNumber(cancelled));
                customerDetail.find('[data-field="no-shows"]').text(formatCustomerNumber(noShows));

                var $tags = customerDetail.find('[data-badge-row]');
                var badgeVisible = false;

                function toggleBadge(selector, visible) {
                    var $badge = $tags.find(selector);
                    if (!$badge.length) {
                        return;
                    }

                    if (visible) {
                        $badge.removeAttr('hidden');
                        badgeVisible = true;
                    } else {
                        $badge.attr('hidden', 'hidden');
                    }
                }

                toggleBadge('[data-badge="vip"]', isVip);
                toggleBadge('[data-badge="blacklist"]', isBlacklisted);
                toggleBadge('[data-badge="loyal"]', isLoyal);
                toggleBadge('[data-badge="problem"]', isProblem);

                if (badgeVisible) {
                    $tags.removeAttr('hidden');
                } else {
                    $tags.attr('hidden', 'hidden');
                }

                var $noteField = customerDetail.find('.rb-manager-note-field');
                $noteField.val(notes).attr('data-customer-id', customerId);

                customerDetail.find('.rb-manager-note-status').removeClass('is-error').text('');
                customerDetail.find('.rb-manager-save-note').attr('data-customer-id', customerId);

                customerDetail
                    .find('.rb-manager-view-history')
                    .attr('data-customer-id', customerId)
                    .attr('data-phone', historyPhone || '');

                customerDetail
                    .find('.rb-manager-set-vip')
                    .attr('data-customer-id', customerId)
                    .toggle(canPromoteVip);

                customerDetail
                    .find('.rb-manager-blacklist')
                    .attr('data-customer-id', customerId)
                    .toggle(!isBlacklisted);

                customerDetail
                    .find('.rb-manager-unblacklist')
                    .attr('data-customer-id', customerId)
                    .toggle(isBlacklisted);
            }

            function showManagerCustomerDetail($item) {
                if (!$item || !$item.length) {
                    return;
                }

                var $list = $item.closest('.rb-customer-list');
                $list.find('.rb-inbox-item').removeClass('is-active');
                $item.addClass('is-active');
                populateManagerCustomerDetail($item);
                $(document).trigger('rb:manager:customerSelected', [$item.get(0)]);
            }

            function refreshManagerCustomerDetail(customerId) {
                if (!customerDetail.length || !customerId) {
                    return;
                }

                var activeId = customerDetail.attr('data-active-id');
                if (!activeId || String(activeId) !== String(customerId)) {
                    return;
                }

                var $item = customerLists.find('.rb-inbox-item[data-customer-id="' + customerId + '"]').first();
                if ($item.length) {
                    populateManagerCustomerDetail($item);
                }
            }

            function initManagerCustomerInbox() {
                if (!customerLists.length) {
                    return;
                }

                customerLists.on('click', '.rb-inbox-item', function(e) {
                    if ($(e.target).closest('button, a, textarea, input, label').length) {
                        return;
                    }

                    showManagerCustomerDetail($(this));
                });

                var $initial = customerLists.find('.rb-inbox-item.is-active').first();
                if (!$initial.length) {
                    $initial = customerLists.find('.rb-inbox-item').first();
                }

                if ($initial && $initial.length) {
                    showManagerCustomerDetail($initial);
                } else if (customerDetail.length) {
                    customerDetail.removeAttr('data-active-id');
                    customerDetail.find('.rb-inbox-detail-body').attr('hidden', true);
                    customerDetail.find('.rb-inbox-detail-empty').show();
                    customerDetail.attr('aria-hidden', 'true');
                    $(document).trigger('rb:manager:customerCleared');
                }
            }

            window.RBManagerInbox = window.RBManagerInbox || {};
            $.extend(window.RBManagerInbox, {
                updateBookingRow: updateBookingRow,
                refreshBookingListCache: refreshBookingListCache,
                showManagerFeedback: showManagerFeedback,
                getManagerNonce: getManagerNonce,
                selectBookingItem: selectBookingItem,
                showEmptyDetail: showEmptyDetail
            });

            initManagerCustomerInbox();

            managerWrapper.on('click', '.rb-manager-action', function(e) {
                e.preventDefault();
                var button = $(this);
                if (button.prop('disabled')) {
                    return;
                }

                var bookingId = String(button.data('id'));
                var action = button.data('action');
                var item = button.closest('.rb-booking-item');
                if (!item.length) {
                    item = $('.rb-booking-item[data-booking-id="' + bookingId + '"]');
                }
                var feedback = $('#rb-manager-feedback');

                if (action === 'delete' && !window.confirm(rb_ajax.delete_confirm_text || 'Are you sure you want to delete this booking?')) {
                    return;
                }

                button.prop('disabled', true);

                $.ajax({
                    url: rb_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'rb_manager_update_booking',
                        booking_id: bookingId,
                        manager_action: action,
                        nonce: getManagerNonce()
                    },
                    success: function(response) {
                        if (response.success) {
                            var successMessage = response.data && response.data.message ? response.data.message : rb_ajax.success_text;
                            showManagerFeedback(feedback, 'success', successMessage);

                            if (action === 'delete') {
                                item.remove();
                                refreshBookingListCache();
                                $(document).trigger('rb:manager:bookingRemoved', [bookingId]);
                                if (currentBookingId && String(currentBookingId) === bookingId) {
                                    var nextItem = bookingList.filter(':visible').first();
                                    if (!nextItem.length) {
                                        nextItem = bookingList.first();
                                    }
                                    if (nextItem.length) {
                                        selectBookingItem(nextItem, false);
                                    } else {
                                        showEmptyDetail();
                                    }
                                }
                            } else if (response.data && response.data.booking) {
                                updateBookingRow(item, response.data.booking);
                            }
                        } else {
                            var message = response.data && response.data.message ? response.data.message : rb_ajax.error_text;
                            showManagerFeedback(feedback, 'error', message);
                        }
                    },
                    error: function() {
                        showManagerFeedback(feedback, 'error', rb_ajax.error_text);
                    },
                    complete: function() {
                        button.prop('disabled', false);
                    }
                });
            });

            var editModal = $('#rb-manager-edit-modal');
            var editForm = $('#rb-manager-edit-booking-form');
            var editFeedback = $('#rb-manager-edit-feedback');

            function closeEditModal() {
                editModal.attr('hidden', true);
                editForm.removeData('row');
                editFeedback.hide().removeClass('success error');
            }

            managerWrapper.on('click', '.rb-manager-edit-booking', function(e) {
                e.preventDefault();
                var button = $(this);
                var item = button.closest('.rb-booking-item');
                if (!item.length) {
                    item = $('.rb-booking-item[data-booking-id="' + button.data('id') + '"]');
                }

                editForm.find('[name="booking_id"]').val(item.data('bookingId') || item.data('booking-id'));
                editForm.find('[name="customer_name"]').val(item.data('customerName'));
                editForm.find('[name="customer_phone"]').val(item.data('customerPhone'));
                editForm.find('[name="customer_email"]').val(item.data('customerEmail'));
                editForm.find('[name="guest_count"]').val(item.data('guestCount'));
                editForm.find('[name="booking_date"]').val(item.data('bookingDate'));
                editForm.find('[name="booking_time"]').val(item.data('bookingTime'));
                editForm.find('[name="booking_source"]').val(item.data('bookingSource'));
                editForm.find('[name="special_requests"]').val(item.data('specialRequests'));
                editForm.find('[name="admin_notes"]').val(item.data('adminNotes'));
                editForm.data('row', item);
                editModal.removeAttr('hidden');
            });

            managerWrapper.on('click', '.rb-manager-modal-close, .rb-manager-modal-cancel', function() {
                closeEditModal();
            });

            editModal.on('click', function(e) {
                if ($(e.target).is('#rb-manager-edit-modal')) {
                    closeEditModal();
                }
            });

            if (editForm.length) {
                editForm.on('submit', function(e) {
                    e.preventDefault();
                    var form = $(this);
                    var submitBtn = form.find('button[type="submit"]');
                    var row = form.data('row');

                    if (!row || !row.length) {
                        closeEditModal();
                        return;
                    }

                    submitBtn.prop('disabled', true);
                    editFeedback.hide();

                    $.ajax({
                        url: rb_ajax.ajax_url,
                        type: 'POST',
                        data: form.serialize() + '&action=rb_manager_save_booking',
                        success: function(response) {
                            if (response.success && response.data && response.data.booking) {
                                showManagerFeedback(editFeedback, 'success', response.data.message || (rb_ajax.success_text || 'Saved successfully.'));
                                updateBookingRow(row, response.data.booking);
                                setTimeout(function() {
                                    closeEditModal();
                                }, 600);
                            } else {
                                var message = response.data && response.data.message ? response.data.message : rb_ajax.error_text;
                                showManagerFeedback(editFeedback, 'error', message);
                            }
                        },
                        error: function() {
                            showManagerFeedback(editFeedback, 'error', rb_ajax.error_text);
                        },
                        complete: function() {
                            submitBtn.prop('disabled', false);
                        }
                    });
                });
            }

            managerWrapper.on('click', '.rb-manager-save-note', function(e) {
                e.preventDefault();

                var button = $(this);
                var customerId = button.attr('data-customer-id');
                if (!customerId || button.prop('disabled')) {
                    return;
                }

                if (!customerDetail.length) {
                    return;
                }

                var noteField = customerDetail.find('.rb-manager-note-field');
                var noteValue = noteField.length ? noteField.val() || '' : '';
                var status = customerDetail.find('.rb-manager-note-status');

                button.prop('disabled', true);
                status.removeClass('is-error').text(rb_ajax.loading_text || 'Saving...');

                $.ajax({
                    url: rb_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'rb_manager_update_customer_note',
                        customer_id: customerId,
                        note: noteValue,
                        nonce: getManagerNonce()
                    },
                    success: function(response) {
                        if (response.success) {
                            var message = response.data && response.data.message ? response.data.message : (rb_ajax.success_text || 'Saved successfully.');
                            status.removeClass('is-error').text(message);
                            updateCustomerNotePreview(customerId, noteValue);
                            refreshManagerCustomerDetail(customerId);
                        } else {
                            var errorMessage = response.data && response.data.message ? response.data.message : rb_ajax.error_text;
                            status.addClass('is-error').text(errorMessage);
                        }
                    },
                    error: function() {
                        status.addClass('is-error').text(rb_ajax.error_text);
                    },
                    complete: function() {
                        button.prop('disabled', false);
                    }
                });
            });

            var createForm = $('#rb-manager-create-booking');
            if (createForm.length) {
                createForm.on('submit', function(e) {
                    e.preventDefault();
                    var form = $(this);
                    var submitBtn = form.find('button[type="submit"]');
                    var feedback = $('#rb-manager-create-feedback');

                    submitBtn.prop('disabled', true);
                    feedback.removeClass('success error').hide();

                    $.ajax({
                        url: rb_ajax.ajax_url,
                        type: 'POST',
                        data: form.serialize() + '&action=rb_manager_create_booking',
                        success: function(response) {
                            if (response.success) {
                                form[0].reset();
                                var successMessage = response.data && response.data.message ? response.data.message : rb_ajax.success_text;
                                showManagerFeedback(feedback, 'success', successMessage);
                            } else if (response.data && response.data.message) {
                                showManagerFeedback(feedback, 'error', response.data.message);
                            } else {
                                showManagerFeedback(feedback, 'error', rb_ajax.error_text);
                            }
                        },
                        error: function() {
                            showManagerFeedback(feedback, 'error', rb_ajax.error_text);
                        },
                        complete: function() {
                            submitBtn.prop('disabled', false);
                        }
                    });
                });
            }

            var addTableForm = $('#rb-manager-add-table');
            if (addTableForm.length) {
                addTableForm.on('submit', function(e) {
                    e.preventDefault();
                    var form = $(this);
                    var feedback = $('#rb-manager-table-feedback');
                    var submitBtn = form.find('button[type="submit"]');

                    submitBtn.prop('disabled', true);
                    feedback.hide();

                    $.ajax({
                        url: rb_ajax.ajax_url,
                        type: 'POST',
                        data: form.serialize() + '&action=rb_manager_add_table',
                        success: function(response) {
                            if (response.success) {
                                location.reload();
                            } else if (response.data && response.data.message) {
                                showManagerFeedback(feedback, 'error', response.data.message);
                            } else {
                                showManagerFeedback(feedback, 'error', rb_ajax.error_text);
                            }
                        },
                        error: function() {
                            showManagerFeedback(feedback, 'error', rb_ajax.error_text);
                        },
                        complete: function() {
                            submitBtn.prop('disabled', false);
                        }
                    });
                });
            }

            managerWrapper.on('click', '.rb-manager-toggle-table', function(e) {
                e.preventDefault();
                var button = $(this);
                if (button.prop('disabled')) {
                    return;
                }

                button.prop('disabled', true);

                $.ajax({
                    url: rb_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'rb_manager_toggle_table',
                        table_id: button.data('table-id'),
                        is_available: button.data('next-status'),
                        nonce: rb_ajax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else if (response.data && response.data.message) {
                            alert(response.data.message);
                        } else {
                            alert(rb_ajax.error_text);
                        }
                    },
                    error: function() {
                        alert(rb_ajax.error_text);
                    },
                    complete: function() {
                        button.prop('disabled', false);
                    }
                });
            });

            managerWrapper.on('click', '.rb-manager-delete-table', function(e) {
                e.preventDefault();
                if (!confirm(rb_ajax.confirm_delete_table || 'Are you sure you want to delete this table?')) {
                    return;
                }

                var button = $(this);

                $.ajax({
                    url: rb_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'rb_manager_delete_table',
                        table_id: button.data('table-id'),
                        nonce: rb_ajax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else if (response.data && response.data.message) {
                            alert(response.data.message);
                        } else {
                            alert(rb_ajax.error_text);
                        }
                    },
                    error: function() {
                        alert(rb_ajax.error_text);
                    }
                });
            });

            managerWrapper.on('click', '.rb-manager-set-vip', function(e) {
                e.preventDefault();
                if (!confirm(rb_ajax.confirm_set_vip || 'Upgrade this customer to VIP?')) {
                    return;
                }

                var button = $(this);
                $.ajax({
                    url: rb_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'rb_manager_set_customer_vip',
                        customer_id: button.data('customer-id'),
                        status: 1,
                        nonce: rb_ajax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else if (response.data && response.data.message) {
                            alert(response.data.message);
                        } else {
                            alert(rb_ajax.error_text);
                        }
                    },
                    error: function() {
                        alert(rb_ajax.error_text);
                    }
                });
            });

            managerWrapper.on('click', '.rb-manager-blacklist, .rb-manager-unblacklist', function(e) {
                e.preventDefault();
                var button = $(this);
                var status = button.hasClass('rb-manager-unblacklist') ? 0 : 1;
                var confirmMessage = status ? (rb_ajax.confirm_blacklist || 'Blacklist this customer?') : (rb_ajax.confirm_unblacklist || 'Remove this customer from blacklist?');
                if (!confirm(confirmMessage)) {
                    return;
                }

                $.ajax({
                    url: rb_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'rb_manager_set_customer_blacklist',
                        customer_id: button.data('customer-id'),
                        status: status,
                        nonce: rb_ajax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else if (response.data && response.data.message) {
                            alert(response.data.message);
                        } else {
                            alert(rb_ajax.error_text);
                        }
                    },
                    error: function() {
                        alert(rb_ajax.error_text);
                    }
                });
            });

            managerWrapper.on('click', '.rb-manager-view-history', function(e) {
                e.preventDefault();
                var phone = $(this).data('phone');
                var historyModal = $('#rb-manager-history');
                var historyContent = $('#rb-manager-history-content');
                var nonceField = $('#rb-manager-customers-nonce');
                var nonceValue = nonceField.length ? nonceField.val() : rb_ajax.nonce;

                historyContent.html('<p>' + (rb_ajax.loading_text || 'Loading...') + '</p>');
                historyModal.removeAttr('hidden');

                $.ajax({
                    url: rb_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'rb_manager_customer_history',
                        phone: phone,
                        nonce: nonceValue
                    },
                    success: function(response) {
                        if (response.success && response.data.history) {
                            if (!response.data.history.length) {
                                historyContent.html('<p>' + (rb_ajax.no_history_text || 'No history found.') + '</p>');
                                return;
                            }

                            var table = $('<table class="rb-manager-history-table"></table>');
                            var thead = $('<thead><tr><th>Date</th><th>Time</th><th>Guests</th><th>Table</th><th>Status</th></tr></thead>');
                            table.append(thead);
                            var tbody = $('<tbody></tbody>');
                            response.data.history.forEach(function(item) {
                                var row = $('<tr></tr>');
                                row.append('<td>' + item.booking_date + '</td>');
                                row.append('<td>' + item.booking_time + '</td>');
                                row.append('<td>' + item.guest_count + '</td>');
                                row.append('<td>' + (item.table_number || '-') + '</td>');
                                row.append('<td>' + item.status + '</td>');
                                tbody.append(row);
                            });
                            table.append(tbody);
                            historyContent.html(table);
                        } else if (response.data && response.data.message) {
                            historyContent.html('<p>' + response.data.message + '</p>');
                        } else {
                            historyContent.html('<p>' + rb_ajax.error_text + '</p>');
                        }
                    },
                    error: function() {
                        historyContent.html('<p>' + rb_ajax.error_text + '</p>');
                    }
                });
            });

            managerWrapper.on('click', '.rb-manager-history-close', function() {
                $('#rb-manager-history').attr('hidden', true);
            });

            $('#rb-manager-history').on('click', function(e) {
                if ($(e.target).is('#rb-manager-history')) {
                    $('#rb-manager-history').attr('hidden', true);
                }
            });

            var settingsForm = $('#rb-manager-settings-form');
            if (settingsForm.length) {
                settingsForm.on('submit', function(e) {
                    e.preventDefault();
                    var form = $(this);
                    var feedback = $('#rb-manager-settings-feedback');
                    var submitBtn = form.find('button[type="submit"]');

                    submitBtn.prop('disabled', true);
                    feedback.hide();

                    $.ajax({
                        url: rb_ajax.ajax_url,
                        type: 'POST',
                        data: form.serialize() + '&action=rb_manager_update_settings',
                        success: function(response) {
                            if (response.success) {
                                var successMessage = response.data && response.data.message ? response.data.message : rb_ajax.success_text;
                                showManagerFeedback(feedback, 'success', successMessage);
                            } else if (response.data && response.data.message) {
                                showManagerFeedback(feedback, 'error', response.data.message);
                            } else {
                                showManagerFeedback(feedback, 'error', rb_ajax.error_text);
                            }
                        },
                        error: function() {
                            showManagerFeedback(feedback, 'error', rb_ajax.error_text);
                        },
                        complete: function() {
                            submitBtn.prop('disabled', false);
                        }
                    });
                });
            }

            var settingsTabs = $('.rb-manager-settings-tab');
            if (settingsTabs.length) {
                var panels = $('.rb-manager-settings-panel');
                settingsTabs.on('click', function() {
                    var tab = $(this).data('tab');
                    settingsTabs.removeClass('active');
                    $(this).addClass('active');
                    panels.attr('hidden', true);
                    panels.filter('[data-tab="' + tab + '"]').removeAttr('hidden');

                    if (window.history && window.history.replaceState) {
                        var url = new URL(window.location.href);
                        url.searchParams.set('rb_settings_tab', tab);
                        window.history.replaceState({}, '', url.toString());
                    }
                });
            }
        }

        // Auto-hide messages after 10 seconds
        $(document).on('rb_booking_success', function() {
            setTimeout(function() {
                $('.rb-form-message').fadeOut();
            }, 10000);
        });
        
    });
    
})(jQuery);