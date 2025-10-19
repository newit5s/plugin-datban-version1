(function ($) {
    'use strict';

    var GmailManager = {
        init: function () {
            this.manager = $('.rb-manager--gmail');
            if (!this.manager.length) {
                return;
            }

            this.layouts = this.manager.find('.rb-manager-gmail-layout, .rb-customer-inbox');
            if (!this.layouts.length) {
                this.layouts = this.manager;
            }

            this.layouts.attr('data-rb-gmail-enhanced', '1');
            this.layout = this.layouts.first();

            this.sidebar = this.layout.find('.rb-gmail-sidebar, .rb-inbox-sidebar');
            this.detail = this.layout.find('.rb-gmail-detail, .rb-inbox-detail');
            if (!this.detail.length) {
                this.detail = this.manager.find('.rb-gmail-detail, .rb-inbox-detail');
            }
            this.header = this.manager.find('.rb-manager-header');
            this.detailScroll = this.detail.find('.rb-gmail-detail-scroll, .rb-customer-detail-scroll');
            this.lastFocused = null;
            this.lastScrollY = window.pageYOffset || document.documentElement.scrollTop || 0;
            this.cardSelector = '.rb-booking-item, .rb-inbox-item';

            if (this.detail.length) {
                this.detail.attr('aria-hidden', 'true');
            }

            this.bindEvents();
            this.refreshCards();
            this.handleResize();
            this.handleScroll();
        },

        bindEvents: function () {
            var self = this;

            this.layouts.on('click', '[data-rb-toggle-sidebar]', function () {
                self.toggleSidebar();
            });

            this.layouts.on('click', '[data-rb-close-panels]', function () {
                self.closePanels();
            });

            this.layouts.on('click', this.cardSelector, function (event) {
                var card = $(this);
                self.focusCard(card);
                self.openDetail();
            });

            this.layouts.on('keydown', this.cardSelector, function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    $(this).trigger('click');
                }
            });

            this.layouts.on('click', '[data-rb-close-detail]', function () {
                self.closeDetail();
            });

            $(document).on('rb:manager:bookingSelected', function (event, element) {
                if (!element) {
                    return;
                }
                var card = $(element);
                self.markFocused(card);
                if (card.length) {
                    self.openDetail();
                }
            });

            $(document).on('rb:manager:customerSelected', function (event, element) {
                if (!element) {
                    return;
                }
                var card = $(element);
                self.markFocused(card);
                if (card.length) {
                    self.openDetail();
                }
            });

            $(document).on('rb:manager:customerCleared', function () {
                self.closeDetail();
            });

            $(document).on('rb:manager:bookingUpdated', function (event, booking) {
                if (!booking || !booking.id) {
                    return;
                }
                self.refreshCardFromResponse(booking);
            });

            $(document).on('rb:manager:bookingRemoved', function (event, bookingId) {
                if (!bookingId) {
                    return;
                }
                self.removeCard(bookingId);
            });

            $(document).on('keydown', function (event) {
                self.handleGlobalKeydown(event);
            });

            $(window).on('resize', function () {
                self.handleResize();
            });

            $(window).on('scroll', function () {
                self.handleScroll();
            });
        },

        refreshCards: function () {
            this.cards = this.manager.find(this.cardSelector);
            this.cards.each(function (index, element) {
                element.setAttribute('data-rb-index', index);
            });
        },

        getCardById: function (bookingId) {
            if (!bookingId && bookingId !== 0) {
                return $();
            }
            return this.layout.find('.rb-booking-item[data-booking-id="' + bookingId + '"]');
        },

        toggleSidebar: function () {
            if (window.matchMedia('(max-width: 768px)').matches) {
                var shouldOpen = !this.sidebar.hasClass('is-open');
                this.layouts.removeClass('has-detail-open');
                this.layouts.toggleClass('is-sidebar-open', shouldOpen);
                this.sidebar.toggleClass('is-open', shouldOpen);
                this.sidebar.toggleClass('is-collapsed', !shouldOpen);
            } else {
                this.layouts.toggleClass('is-sidebar-hidden');
            }

            this.revealHeader();
        },

        openDetail: function () {
            this.closeSidebar();
            this.layouts.addClass('has-detail-open');
            if (this.detail.length) {
                this.detail.attr('aria-hidden', 'false');
                if (!this.detail.attr('tabindex')) {
                    this.detail.attr('tabindex', '-1');
                }
                this.detail.trigger('focus');
            }
            this.scrollDetailToTop();
            this.revealHeader();

            if (window.innerWidth < 769 && typeof window.scrollTo === 'function') {
                try {
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                } catch (error) {
                    window.scrollTo(0, 0);
                }
            }
        },

        closeDetail: function () {
            var previousFocus = this.lastFocused;
            this.layouts.removeClass('has-detail-open');
            if (this.detail.length) {
                this.detail.attr('aria-hidden', 'true');
                var customerBody = this.detail.find('.rb-inbox-detail-body');
                var customerEmpty = this.detail.find('.rb-inbox-detail-empty');
                if (customerBody.length && customerEmpty.length) {
                    customerBody.attr('hidden', true);
                    customerEmpty.show();
                }
            }
            this.scrollDetailToTop();
            this.manager.find('.rb-booking-item.active').removeClass('active');
            this.manager.find('.rb-booking-item.is-focused').removeClass('is-focused');
            this.manager.find('.rb-inbox-item.is-active').removeClass('is-active');
            this.manager.find('.rb-inbox-item.is-focused').removeClass('is-focused');
            this.lastFocused = null;
            if (previousFocus && previousFocus.length) {
                previousFocus.trigger('focus');
            }
            this.revealHeader();
        },

        closeSidebar: function () {
            if (window.innerWidth < 769) {
                this.layouts.removeClass('is-sidebar-open');
                this.sidebar.removeClass('is-open');
                this.sidebar.addClass('is-collapsed');
            }

            this.revealHeader();
        },

        closePanels: function () {
            this.closeDetail();
            this.closeSidebar();
        },

        handleResize: function () {
            var isMobile = window.innerWidth < 769;

            this.layouts.toggleClass('is-mobile-ready', isMobile);

            if (isMobile) {
                this.layouts.removeClass('is-sidebar-hidden');
                if (this.sidebar.hasClass('is-open')) {
                    this.layouts.addClass('is-sidebar-open');
                    this.sidebar.removeClass('is-collapsed');
                } else {
                    this.layouts.removeClass('is-sidebar-open');
                    this.sidebar.addClass('is-collapsed');
                }
            } else {
                this.layouts.removeClass('is-sidebar-open has-detail-open');
                this.sidebar.removeClass('is-open is-collapsed');
                if (this.detail.length) {
                    this.detail.attr('aria-hidden', 'true');
                }
                this.revealHeader();
            }

            this.lastScrollY = window.pageYOffset || document.documentElement.scrollTop || 0;
        },

        scrollDetailToTop: function () {
            if (this.detailScroll && this.detailScroll.length) {
                this.detailScroll.scrollTop(0);
            }
        },

        revealHeader: function () {
            if (this.header && this.header.length) {
                this.header.removeClass('is-hidden');
            }
            this.lastScrollY = window.pageYOffset || document.documentElement.scrollTop || 0;
        },

        handleScroll: function () {
            if (!this.header || !this.header.length) {
                return;
            }

            if (window.innerWidth >= 769) {
                this.header.removeClass('is-hidden');
                this.lastScrollY = window.pageYOffset || document.documentElement.scrollTop || 0;
                return;
            }

            var currentY = window.pageYOffset || document.documentElement.scrollTop || 0;
            var delta = currentY - this.lastScrollY;

            if (this.layouts.filter('.is-sidebar-open').length || this.layouts.filter('.has-detail-open').length) {
                this.header.removeClass('is-hidden');
                this.lastScrollY = currentY;
                return;
            }

            if (currentY <= 0) {
                this.header.removeClass('is-hidden');
            } else if (delta > 6) {
                this.header.addClass('is-hidden');
            } else if (delta < -6) {
                this.header.removeClass('is-hidden');
            }

            this.lastScrollY = currentY;
        },

        focusCard: function (card) {
            if (!card || !card.length) {
                return;
            }

            this.manager.find('.rb-booking-item.active').removeClass('active');
            this.manager.find('.rb-inbox-item.is-active').removeClass('is-active');
            if (card.hasClass('rb-inbox-item')) {
                card.addClass('is-active');
            } else {
                card.addClass('active');
            }
            this.markFocused(card);
            this.scrollDetailToTop();
        },

        markFocused: function (card) {
            this.manager.find('.rb-booking-item.is-focused, .rb-inbox-item.is-focused').removeClass('is-focused');
            if (card && card.length) {
                card.addClass('is-focused');
                this.lastFocused = card;
            }
        },

        handleGlobalKeydown: function (event) {
            if (!this.layout.length) {
                return;
            }

            if ($(event.target).is('input, textarea, select, button, [contenteditable]')) {
                return;
            }

            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                this.navigate(event.key === 'ArrowDown' ? 1 : -1);
            }

            if (event.key === 'Escape') {
                if (this.layout.hasClass('has-detail-open') || this.layout.hasClass('is-sidebar-open')) {
                    this.closePanels();
                }
            }
        },

        navigate: function (direction) {
            var cards = this.manager.find(this.cardSelector + ':visible');
            if (!cards.length) {
                return;
            }

            var current = cards.index(cards.filter('.active, .is-active').first());
            if (current === -1) {
                current = 0;
            } else {
                current = Math.max(0, Math.min(cards.length - 1, current + direction));
            }

            var next = cards.eq(current);
            if (next.length) {
                next.trigger('click');
                this.scrollIntoView(next);
            }
        },

        scrollIntoView: function (card) {
            if (!card || !card.length) {
                return;
            }

            var container = card.closest('.rb-gmail-list, .rb-inbox-list');
            if (!container.length) {
                return;
            }

            var containerEl = container.get(0);
            var cardEl = card.get(0);

            if (containerEl && cardEl && containerEl.scrollHeight > containerEl.clientHeight) {
                var cardTop = cardEl.offsetTop;
                var cardBottom = cardTop + cardEl.offsetHeight;
                var visibleTop = containerEl.scrollTop;
                var visibleBottom = visibleTop + containerEl.clientHeight;

                if (cardTop < visibleTop) {
                    containerEl.scrollTo({ top: cardTop - 16, behavior: 'smooth' });
                } else if (cardBottom > visibleBottom) {
                    containerEl.scrollTo({ top: cardBottom - containerEl.clientHeight + 16, behavior: 'smooth' });
                }
            }
        },

        refreshCardFromResponse: function (booking) {
            var card = this.getCardById(booking.id);
            if (!card.length) {
                return;
            }

            card.attr('data-status', booking.status || '');
            card.attr('data-status-label', booking.status_label || booking.status || '');
            card.attr('data-guest-count', booking.guest_count || '');
            card.attr('data-booking-time', booking.booking_time || '');
            card.attr('data-booking-date', booking.booking_date || '');
            card.attr('data-date-display', booking.date_display || '');
            card.attr('data-source-label', booking.source_label || '');
            card.attr('data-created-display', booking.created_display || '');
            card.attr('data-special-requests', booking.special_requests || '');
            card.attr('data-admin-notes', booking.admin_notes || '');

            card.find('.rb-booking-item-name').text(booking.customer_name || '');
            card.find('.rb-booking-item-time').text((booking.date_display || booking.booking_date || '') + (booking.booking_time ? ' ' + booking.booking_time : ''));
            card.find('.rb-booking-item-slot').text(booking.booking_time || '');
            card.find('[data-meta="phone"]').text(booking.customer_phone ? '📞 ' + booking.customer_phone : '');
            card.find('[data-meta="guests"]').text('👥 ' + (booking.guest_count || '0'));
            card.find('[data-meta="status"]').text(booking.status_label || booking.status || '');
            card.find('[data-meta="source"]').text(booking.source_label || booking.booking_source || '');
            card.find('[data-meta="created"]').text(booking.created_display ? '⏱ ' + booking.created_display : '');
            card.find('[data-meta="special"]').text(booking.special_requests ? '📝 ' + booking.special_requests : '');

            card.removeClass(function (index, className) {
                return (className.match(/status-[^\s]+/g) || []).join(' ');
            });
            card.addClass('status-' + (booking.status || ''));
        },

        removeCard: function (bookingId) {
            var card = this.getCardById(bookingId);
            if (!card.length) {
                return;
            }
            card.remove();
            this.refreshCards();
            if (this.lastFocused && !this.lastFocused.closest('body').length) {
                this.lastFocused = null;
            }
        }
    };

    $(function () {
        GmailManager.init();
    });

    window.RBGmailManager = GmailManager;
})(jQuery);

document.addEventListener('DOMContentLoaded', function () {
    var layout = document.querySelector('.rb-manager-gmail-layout');
    if (!layout || layout.getAttribute('data-rb-gmail-enhanced') === '1') {
        return;
    }

    var toggle = layout.querySelector('.rb-gmail-toggle');
    var sidebar = layout.querySelector('.rb-gmail-sidebar');

    if (!toggle || !sidebar) {
        return;
    }

    toggle.addEventListener('click', function () {
        if (layout.getAttribute('data-rb-gmail-enhanced') === '1') {
            return;
        }

        if (!window.matchMedia('(max-width: 768px)').matches) {
            return;
        }

        var isOpen = sidebar.classList.toggle('is-open');
        layout.classList.toggle('is-sidebar-open', isOpen);

        if (isOpen) {
            sidebar.classList.remove('is-collapsed');
        } else {
            sidebar.classList.add('is-collapsed');
        }
    });
});
