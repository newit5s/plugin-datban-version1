/**
 * Restaurant Booking - Modern Frontend JavaScript
 * Handles the new unified booking experience
 */

(function($) {
    'use strict';

    const RBBookingNew = {
        modal: null,
        isInline: false,
        currentStep: 1,
        selectedData: {},
        currentLanguage: '',
        
        // Localized strings (will be populated by WordPress)
        strings: window.rbBookingStrings || {
            checking: 'Checking...',
            processing: 'Processing...',
            selectTime: 'Select time',
            people: 'people',
            connectionError: 'Connection error. Please try again.',
            securityError: 'Security check failed',
            fillRequired: 'Please fill in all required fields',
            suggestedTimes: 'Suggested Times',
            back: 'Back',
            continue: 'Continue',
            checkingAvailability: 'Check Availability',
            confirmBooking: 'Confirm Booking',
            invalidEmail: 'Please enter a valid email address',
            invalidPhone: 'Please enter a valid phone number',
            languageSwitching: 'Switching language…',
            languageSwitched: 'Language switched',
            languageSwitchFailed: 'Unable to switch language. Please try again.'
        },

        init: function() {
            this.modal = $('#rb-new-booking-modal');

            if (!this.modal.length) {
                return;
            }

            this.isInline = this.modal.data('inline-mode') === 1 || this.modal.hasClass('rb-new-modal-inline');
            this.currentLanguage = $('#rb-new-hidden-language').val() || (window.rbBookingAjax && window.rbBookingAjax.currentLanguage) || '';
            if (!this.currentLanguage) {
                this.currentLanguage = $('.rb-new-lang-select').first().val() || '';
            }
            this.bindEvents();
            this.setInitialValues();
            this.preventBodyScroll();
        },

        bindEvents: function() {
            // Open modal
            $(document).on('click', '.rb-new-open-modal-btn', this.openModal.bind(this));
            
            // Close modal
            $(document).on('click', '.rb-new-close', this.closeModal.bind(this));
            $(document).on('click', '.rb-new-modal', this.handleModalClick.bind(this));

            // Language switcher
            $(document).on('change', '.rb-new-lang-select', this.changeLanguage.bind(this));

            // Availability form
            $(document).on('submit', '#rb-new-availability-form', this.checkAvailability.bind(this));

            // Booking form
            $(document).on('submit', '#rb-new-booking-form', this.submitBooking.bind(this));

            // Back button
            $(document).on('click', '#rb-new-back-btn', this.goBackToStep1.bind(this));

            // Date/location change - update time slots
            $(document).on('change', '#rb-new-date, #rb-new-location, #rb-new-guests', this.updateTimeSlots.bind(this));

            // Suggestion buttons
            $(document).on('click', '.rb-new-suggestion-btn', this.selectSuggestedTime.bind(this));

            // Keyboard navigation
            $(document).on('keydown', this.handleKeydown.bind(this));
        },

        setInitialValues: function() {
            // Set default date to tomorrow
            const tomorrow = new Date();
            tomorrow.setDate(tomorrow.getDate() + 1);
            const dateString = tomorrow.toISOString().split('T')[0];
            $('#rb-new-date').val(dateString);
            
            // Load initial time slots
            this.updateTimeSlots();
        },

        preventBodyScroll: function() {
            if (this.isInline) {
                return;
            }

            // Prevent body scroll when modal is open
            this.modal.on('show', function() {
                $('body').addClass('rb-modal-open');
            });

            this.modal.on('hide', function() {
                $('body').removeClass('rb-modal-open');
            });
        },

        openModal: function(e) {
            if (this.isInline) {
                return;
            }

            if (e) {
                e.preventDefault();
            }
            this.modal.addClass('show').attr('aria-hidden', 'false');
            this.modal.trigger('show');

            // Focus first input for accessibility
            setTimeout(() => {
                this.modal.find('input, select').first().focus();
            }, 300);
        },

        closeModal: function(e) {
            if (this.isInline) {
                return;
            }

            if (e) {
                e.preventDefault();
            }
            this.modal.removeClass('show').attr('aria-hidden', 'true');
            this.modal.trigger('hide');
            this.resetToStep1();
        },

        handleModalClick: function(e) {
            if (this.isInline) {
                return;
            }

            if (e.target === e.currentTarget) {
                this.closeModal();
            }
        },

        handleKeydown: function(e) {
            if (this.isInline) {
                return;
            }

            if (e.key === 'Escape' && this.modal.hasClass('show')) {
                this.closeModal();
            }
        },

        changeLanguage: function(e) {
            const $select = $(e.target);
            const newLang = $select.val();
            const previousLanguage = this.currentLanguage || (window.rbBookingAjax && window.rbBookingAjax.currentLanguage) || '';

            if (!newLang || newLang === previousLanguage) {
                return;
            }

            const $status = this.getLanguageStatusElement($select);
            this.updateLanguageStatus($status, this.strings.languageSwitching || 'Switching language…', 'loading');

            if (!window.rbBookingAjax || !rbBookingAjax.languageAction || !rbBookingAjax.languageNonce) {
                this.currentLanguage = newLang;
                $('#rb-new-hidden-language').val(newLang);
                $('.rb-new-lang-select').val(newLang);
                this.updateLanguageStatus($status, this.strings.languageSwitched || 'Language switched', 'success');
                setTimeout(() => this.updateLanguageStatus($status, '', ''), 2500);
                return;
            }

            this.toggleLanguageLoading(true);

            $.ajax({
                url: rbBookingAjax.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: rbBookingAjax.languageAction,
                    language: newLang,
                    nonce: rbBookingAjax.languageNonce
                }
            }).done((response) => {
                if (response && response.success) {
                    this.currentLanguage = newLang;
                    $('#rb-new-hidden-language').val(newLang);
                    $('.rb-new-lang-select').val(newLang);

                    if (rbBookingAjax.shouldReloadOnLanguageChange) {
                        window.location.reload();
                        return;
                    }

                    const successMessage = this.strings.languageSwitched || 'Language switched';
                    this.updateLanguageStatus($status, successMessage, 'success');
                    setTimeout(() => this.updateLanguageStatus($status, '', ''), 2500);
                } else {
                    this.currentLanguage = previousLanguage;
                    $('#rb-new-hidden-language').val(previousLanguage);
                    $('.rb-new-lang-select').val(previousLanguage);

                    const errorMessage = response && response.data && response.data.message;
                    this.updateLanguageStatus($status, errorMessage || this.strings.languageSwitchFailed || this.strings.connectionError, 'error');
                }
            }).fail(() => {
                this.currentLanguage = previousLanguage;
                $('#rb-new-hidden-language').val(previousLanguage);
                $('.rb-new-lang-select').val(previousLanguage);
                this.updateLanguageStatus($status, this.strings.languageSwitchFailed || this.strings.connectionError, 'error');
            }).always(() => {
                this.toggleLanguageLoading(false);
            });
        },

        getLanguageStatusElement: function($select) {
            const $container = $select.closest('.rb-new-language-switcher');
            if ($container.length) {
                const $status = $container.find('.rb-new-language-status');
                if ($status.length) {
                    return $status;
                }
            }
            return $('.rb-new-language-status').first();
        },

        toggleLanguageLoading: function(isLoading) {
            const $selects = $('.rb-new-lang-select');
            if (isLoading) {
                $selects.prop('disabled', true).addClass('rb-new-lang-loading');
            } else {
                $selects.prop('disabled', false).removeClass('rb-new-lang-loading');
            }
        },

        updateLanguageStatus: function($status, message, state) {
            if (!$status || !$status.length) {
                return;
            }

            $status.removeClass('is-success is-error is-loading');

            if (!message) {
                $status.text('').attr('hidden', true);
                return;
            }

            if (state === 'success') {
                $status.addClass('is-success');
            } else if (state === 'error') {
                $status.addClass('is-error');
            } else if (state === 'loading') {
                $status.addClass('is-loading');
            }

            $status.text(message).attr('hidden', false);
        },

        checkAvailability: function(e) {
            e.preventDefault();
            
            if (!this.validateAvailabilityForm()) {
                return;
            }

            const formData = this.getAvailabilityFormData();
            const $button = $('#rb-new-availability-form button[type="submit"]');
            
            this.setButtonLoading($button, this.strings.checking);

            $.ajax({
                url: window.rbBookingAjax.ajaxUrl,
                type: 'POST',
                data: formData,
                success: this.handleAvailabilityResponse.bind(this),
                error: this.handleAjaxError.bind(this),
                complete: () => this.resetButton($button, this.strings.checkingAvailability || 'Check Availability')
            });
        },

        validateAvailabilityForm: function() {
            const requiredFields = ['#rb-new-location', '#rb-new-date', '#rb-new-time', '#rb-new-guests'];
            let isValid = true;

            requiredFields.forEach(selector => {
                const $field = $(selector);
                if (!$field.val()) {
                    this.highlightField($field);
                    isValid = false;
                } else {
                    this.unhighlightField($field);
                }
            });

            if (!isValid) {
                this.showError(this.strings.fillRequired);
            }

            return isValid;
        },

        getAvailabilityFormData: function() {
            return {
                action: 'rb_check_availability',
                nonce: window.rbBookingAjax.nonce,
                location_id: $('#rb-new-location').val(),
                date: $('#rb-new-date').val(),
                time: $('#rb-new-time').val(),
                guests: $('#rb-new-guests').val()
            };
        },

        handleAvailabilityResponse: function(response) {
            if (response.success) {
                if (response.data.available) {
                    this.showAvailabilitySuccess(response.data);
                    this.storeSelectedData();
                    this.goToStep2();
                } else {
                    this.showSuggestions(response.data);
                }
            } else {
                this.showError(response.data.message);
            }
        },

        showAvailabilitySuccess: function(data) {
            $('#rb-new-availability-result')
                .removeClass('error')
                .addClass('success')
                .html('<p>✓ ' + data.message + '</p>')
                .removeAttr('hidden')
                .show();
            $('#rb-new-suggestions').attr('hidden', true).hide();
        },

        showSuggestions: function(data) {
            $('#rb-new-availability-result')
                .removeClass('success')
                .addClass('error')
                .html('<p>' + data.message + '</p>')
                .removeAttr('hidden')
                .show();

            if (data.suggestions && data.suggestions.length > 0) {
                this.renderSuggestions(data.suggestions);
                $('#rb-new-suggestions').removeAttr('hidden').show();
            } else {
                $('#rb-new-suggestions').attr('hidden', true).hide();
            }
        },

        renderSuggestions: function(suggestions) {
            let suggestionsHtml = '';
            suggestions.forEach(suggestion => {
                suggestionsHtml += `<button type="button" class="rb-new-suggestion-btn" data-time="${suggestion}">${suggestion}</button>`;
            });
            
            $('#rb-new-suggestions .rb-new-suggestion-list').html(suggestionsHtml);
        },

        selectSuggestedTime: function(e) {
            const suggestedTime = $(e.target).data('time');
            $('#rb-new-time').val(suggestedTime);
            
            // Highlight the selected suggestion
            $('.rb-new-suggestion-btn').removeClass('selected');
            $(e.target).addClass('selected');
            
            // Auto-submit after selection
            setTimeout(() => {
                $('#rb-new-availability-form').submit();
            }, 500);
        },

        storeSelectedData: function() {
            this.selectedData = {
                locationId: $('#rb-new-location').val(),
                locationName: $('#rb-new-location option:selected').text(),
                date: $('#rb-new-date').val(),
                time: $('#rb-new-time').val(),
                guests: $('#rb-new-guests').val()
            };
        },

        goToStep2: function() {
            this.updateBookingSummary();
            this.setHiddenFields();

            const $step1 = $('.rb-new-step[data-step="1"]');
            const $step2 = $('.rb-new-step[data-step="2"]');

            // Ensure the second step can be displayed by removing the hidden attribute
            $step2.stop(true, true).hide().removeAttr('hidden');

            $step1.stop(true, true).fadeOut(300, () => {
                $step1.attr('hidden', true);
                $step2.fadeIn(300);
            });

            this.currentStep = 2;

            // Focus first input in step 2
            setTimeout(() => {
                $('#rb-new-customer-name').trigger('focus');
            }, 350);
        },

        updateBookingSummary: function() {
            const { locationName, date, time, guests } = this.selectedData;
            
            $('#rb-new-summary-location').text(locationName);
            $('#rb-new-summary-datetime').text(`${date} ${time}`);
            $('#rb-new-summary-guests').text(`${guests} ${this.strings.people}`);
        },

        setHiddenFields: function() {
            const { locationId, date, time, guests } = this.selectedData;

            $('#rb-new-hidden-location').val(locationId);
            $('#rb-new-hidden-date').val(date);
            $('#rb-new-hidden-time').val(time);
            $('#rb-new-hidden-guests').val(guests);
            if (this.currentLanguage) {
                $('#rb-new-hidden-language').val(this.currentLanguage);
            }
        },

        goBackToStep1: function(e) {
            e.preventDefault();

            const $step1 = $('.rb-new-step[data-step="1"]');
            const $step2 = $('.rb-new-step[data-step="2"]');

            $step1.stop(true, true).hide().removeAttr('hidden');

            $step2.stop(true, true).fadeOut(300, () => {
                $step2.attr('hidden', true);
                $step1.fadeIn(300);
            });

            this.currentStep = 1;

            // Clear any result messages
            $('#rb-new-booking-result').attr('hidden', true).hide();
        },

        resetToStep1: function() {
            const $step1 = $('.rb-new-step[data-step="1"]');
            const $step2 = $('.rb-new-step[data-step="2"]');

            $step2.stop(true, true).hide().attr('hidden', true);
            $step1.stop(true, true).show().removeAttr('hidden');
            this.currentStep = 1;
            this.selectedData = {};
            this.clearAllMessages();
            this.clearForm();
        },

        submitBooking: function(e) {
            e.preventDefault();
            
            if (!this.validateBookingForm()) {
                return;
            }

            const formData = $('#rb-new-booking-form').serialize() + '&action=rb_submit_booking';
            const $button = $('#rb-new-booking-form button[type="submit"]');
            
            this.setButtonLoading($button, this.strings.processing);

            $.ajax({
                url: window.rbBookingAjax.ajaxUrl,
                type: 'POST',
                data: formData,
                success: this.handleBookingResponse.bind(this),
                error: this.handleAjaxError.bind(this),
                complete: () => this.resetButton($button, this.strings.confirmBooking || 'Confirm Booking')
            });
        },

        validateBookingForm: function() {
            const requiredFields = [
                '#rb-new-customer-name',
                '#rb-new-customer-phone', 
                '#rb-new-customer-email'
            ];
            
            let isValid = true;
            let errorMessage = '';

            requiredFields.forEach(selector => {
                const $field = $(selector);
                if (!$field.val().trim()) {
                    this.highlightField($field);
                    isValid = false;
                    errorMessage = this.strings.fillRequired;
                } else {
                    this.unhighlightField($field);
                }
            });

            // Email validation
            const email = $('#rb-new-customer-email').val();
            if (email && !this.isValidEmail(email)) {
                this.highlightField($('#rb-new-customer-email'));
                isValid = false;
                errorMessage = this.strings.invalidEmail;
            }

            // Phone validation
            const phone = $('#rb-new-customer-phone').val();
            if (phone && !this.isValidPhone(phone)) {
                this.highlightField($('#rb-new-customer-phone'));
                isValid = false;
                errorMessage = this.strings.invalidPhone;
            }

            if (!isValid) {
                this.showBookingError(errorMessage || this.strings.fillRequired);
            }

            return isValid;
        },

        isValidEmail: function(email) {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return emailRegex.test(email);
        },

        isValidPhone: function(phone) {
            const phoneRegex = /^[0-9+\-\s]{8,20}$/;
            return phoneRegex.test(phone);
        },

        handleBookingResponse: function(response) {
            if (response.success) {
                this.showBookingSuccess(response.data.message);

                // Auto-close modal after success
                setTimeout(() => {
                    if (!this.isInline) {
                        this.closeModal();
                    }
                    this.resetToStep1();
                    const bookingForm = $('#rb-new-booking-form')[0];
                    if (bookingForm) {
                        bookingForm.reset();
                    }
                }, 4000);
            } else {
                this.showBookingError(response.data.message);
            }
        },

        showBookingSuccess: function(message) {
            $('#rb-new-booking-result')
                .removeClass('error')
                .addClass('success')
                .html('<p>✓ ' + message + '</p>')
                .removeAttr('hidden')
                .show();
            
            // Scroll to message
            this.scrollToElement('#rb-new-booking-result');
        },

        showBookingError: function(message) {
            $('#rb-new-booking-result')
                .removeClass('success')
                .addClass('error')
                .html('<p>' + message + '</p>')
                .removeAttr('hidden')
                .show();
            
            // Scroll to message
            this.scrollToElement('#rb-new-booking-result');
        },

        updateTimeSlots: function() {
            const date = $('#rb-new-date').val();
            const locationId = $('#rb-new-location').val();
            const guestCount = $('#rb-new-guests').val();

            if (!date || !locationId || !guestCount) return;

            $.ajax({
                url: window.rbBookingAjax.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'rb_get_time_slots',
                    nonce: window.rbBookingAjax.nonce,
                    date: date,
                    location_id: locationId,
                    guest_count: guestCount
                },
                success: this.handleTimeSlotsResponse.bind(this)
            });
        },

        handleTimeSlotsResponse: function(response) {
            if (response.success && response.data.slots) {
                const $timeSelect = $('#rb-new-time');
                const currentTime = $timeSelect.val();
                
                $timeSelect.empty().append(`<option value="">${this.strings.selectTime}</option>`);
                
                response.data.slots.forEach(slot => {
                    $timeSelect.append(`<option value="${slot}">${slot}</option>`);
                });

                // Restore selection if still available
                if (currentTime && response.data.slots.includes(currentTime)) {
                    $timeSelect.val(currentTime);
                }
            }
        },

        handleAjaxError: function() {
            if (this.currentStep === 2) {
                this.showBookingError(this.strings.connectionError);
            } else {
                this.showError(this.strings.connectionError);
            }
        },

        showError: function(message) {
            $('#rb-new-availability-result')
                .removeClass('success')
                .addClass('error')
                .html('<p>' + message + '</p>')
                .removeAttr('hidden')
                .show();
            $('#rb-new-suggestions').attr('hidden', true).hide();
        },

        setButtonLoading: function($button, text) {
            $button.text(text).prop('disabled', true).addClass('rb-new-loading');
        },

        resetButton: function($button, text) {
            $button.text(text).prop('disabled', false).removeClass('rb-new-loading');
        },

        highlightField: function($field) {
            $field.addClass('error').css('border-color', '#fc8181');
        },

        unhighlightField: function($field) {
            $field.removeClass('error').css('border-color', '');
        },

        scrollToElement: function(selector) {
            const $element = $(selector);
            if ($element.length) {
                $element[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        },

        clearAllMessages: function() {
            $('#rb-new-availability-result, #rb-new-suggestions, #rb-new-booking-result')
                .attr('hidden', true)
                .hide();
        },

        clearForm: function() {
            const bookingForm = $('#rb-new-booking-form')[0];
            if (bookingForm) {
                bookingForm.reset();
            }
            $('.rb-new-form-group input, .rb-new-form-group select, .rb-new-form-group textarea')
                .removeClass('error')
                .css('border-color', '');
        }
    };

    // Initialize when DOM is ready
    $(document).ready(function() {
        RBBookingNew.init();
    });

    // Expose to global scope for external access
    window.RBBookingNew = RBBookingNew;

})(jQuery);
