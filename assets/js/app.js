/* ============================================
   Bilet-Geç - Main Application JavaScript
   ============================================ */

const APP_BASE = (document.body?.dataset?.baseUrl || '/ilterhoca/').replace(/\/?$/, '/');

/* ============================================
   Toast Notification System
   ============================================ */
class ToastManager {
    constructor() {
        this.container = null;
        this.init();
    }

    init() {
        this.container = document.querySelector('.toast-container');
        if (!this.container) {
            this.container = document.createElement('div');
            this.container.className = 'toast-container';
            document.body.appendChild(this.container);
        }
    }

    getIcon(type) {
        const icons = {
            success: '✅',
            error: '❌',
            warning: '⚠️',
            info: 'ℹ️'
        };
        return icons[type] || icons.info;
    }

    getTitle(type) {
        const titles = {
            success: 'Başarılı',
            error: 'Hata',
            warning: 'Uyarı',
            info: 'Bilgi'
        };
        return titles[type] || titles.info;
    }

    show(message, type = 'success', duration = 4000) {
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;

        toast.innerHTML = `
            <span class="toast-icon">${this.getIcon(type)}</span>
            <div class="toast-content">
                <div class="toast-title">${this.getTitle(type)}</div>
                <div class="toast-message">${message}</div>
            </div>
            <button class="toast-close" aria-label="Kapat">&times;</button>
            <div class="toast-progress" style="animation-duration: ${duration}ms;"></div>
        `;

        // Close button handler
        const closeBtn = toast.querySelector('.toast-close');
        closeBtn.addEventListener('click', () => this.dismiss(toast));

        this.container.appendChild(toast);

        // Auto dismiss after duration
        const dismissTimeout = setTimeout(() => {
            this.dismiss(toast);
        }, duration);

        // Store timeout reference so we can clear it on manual close
        toast._dismissTimeout = dismissTimeout;

        return toast;
    }

    dismiss(toast) {
        if (toast._dismissed) return;
        toast._dismissed = true;

        if (toast._dismissTimeout) {
            clearTimeout(toast._dismissTimeout);
        }

        toast.classList.add('toast-dismiss');
        toast.addEventListener('animationend', () => {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        });
    }

    success(message, duration) {
        return this.show(message, 'success', duration);
    }

    error(message, duration) {
        return this.show(message, 'error', duration);
    }

    warning(message, duration) {
        return this.show(message, 'warning', duration);
    }

    info(message, duration) {
        return this.show(message, 'info', duration);
    }
}

// Global toast instance
const toast = new ToastManager();

// Global helper function for showing toasts
function showToast(message, type = 'info', duration) {
    return toast.show(message, type, duration);
}

/* ============================================
   Reservation Timer Manager
   ============================================ */
class ReservationTimer {
    constructor(reservationId, expiresAt, onExpire) {
        this.reservationId = reservationId;
        this.expiresAt = new Date(expiresAt).getTime();
        this.onExpire = onExpire || null;
        this.intervalId = null;
        this.element = document.getElementById(`timer-${reservationId}`);
    }

    start() {
        if (!this.element) {
            console.warn(`Timer element not found: timer-${this.reservationId}`);
            return;
        }
        this.updateDisplay();
        this.intervalId = setInterval(() => this.updateDisplay(), 1000);
    }

    updateDisplay() {
        const now = Date.now();
        const remaining = Math.max(0, this.expiresAt - now);
        const totalSeconds = Math.floor(remaining / 1000);
        const minutes = Math.floor(totalSeconds / 60);
        const seconds = totalSeconds % 60;

        const formatted = `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;

        // Find the timer value element inside the countdown-timer
        const valueEl = this.element.querySelector('.timer-value');
        const timerContainer = this.element.closest('.countdown-timer') || this.element;

        if (valueEl) {
            valueEl.textContent = formatted;
        } else {
            this.element.textContent = formatted;
        }

        // Update color coding based on remaining time
        timerContainer.classList.remove('warning', 'critical');

        if (totalSeconds <= 30) {
            timerContainer.classList.add('critical');
        } else if (totalSeconds <= 60) {
            timerContainer.classList.add('warning');
        }

        // Timer expired
        if (totalSeconds <= 0) {
            this.stop();
            if (valueEl) {
                valueEl.textContent = '00:00';
            } else {
                this.element.textContent = '00:00';
            }
            timerContainer.classList.add('critical');

            if (typeof this.onExpire === 'function') {
                this.onExpire(this.reservationId);
            }
        }
    }

    stop() {
        if (this.intervalId) {
            clearInterval(this.intervalId);
            this.intervalId = null;
        }
    }

    getRemainingSeconds() {
        return Math.max(0, Math.floor((this.expiresAt - Date.now()) / 1000));
    }
}

/* ============================================
   API Helper
   ============================================ */
class API {
    static BASE = APP_BASE + 'api/';

    static async request(endpoint, options = {}) {
        const url = API.BASE + endpoint;
        const defaultOptions = {
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        };

        // Attach CSRF token from meta tag when present
        try {
            const meta = document.querySelector('meta[name="csrf-token"]');
            if (meta && meta.content) {
                defaultOptions.headers['X-CSRF-Token'] = meta.content;
            }
        } catch (e) {
            // ignore if DOM not ready
        }

        const config = { ...defaultOptions, ...options }; 
        if (options.headers) {
            config.headers = { ...defaultOptions.headers, ...options.headers };
        }
        if (config.body && typeof config.body === 'object') {
            config.body = JSON.stringify(config.body);
        }

        try {
            const response = await fetch(url, config);
            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.message || `HTTP hatası: ${response.status}`);
            }

            return data;
        } catch (error) {
            if (error.name === 'TypeError' && error.message.includes('fetch')) {
                toast.error('Sunucuya bağlanılamadı. Lütfen internet bağlantınızı kontrol edin.');
            } else {
                toast.error(error.message || 'Bir hata oluştu.');
            }
            throw error;
        }
    }

    static async checkAvailability(eventId) {
        return API.request(`check_availability.php?event_id=${eventId}`, {
            method: 'GET'
        });
    }

    static async reserveTicket(eventId, quantity) {
        return API.request('reserve_ticket.php', {
            method: 'POST',
            body: { event_id: eventId, quantity: quantity }
        });
    }

    static async confirmPurchase(reservationId) {
        if (!reservationId) {
            throw new Error('Geçerli bir rezervasyon IDsi bulunamadı.');
        }
        return API.request('confirm_purchase.php', {
            method: 'POST',
            body: { reservation_id: reservationId }
        });
    }

    static async cancelReservation(reservationId) {
        if (!reservationId) {
            throw new Error('Geçerli bir rezervasyon IDsi bulunamadı.');
        }
        return API.request('cancel_reservation.php', {
            method: 'POST',
            body: { reservation_id: reservationId }
        });
    }
}

/* ============================================
   Ticket Availability Poller
   ============================================ */
class AvailabilityPoller {
    constructor(eventId, interval = 30000) {
        this.eventId = eventId;
        this.interval = interval;
        this.timerId = null;
    }

    start() {
        this.poll(); // Initial poll
        this.timerId = setInterval(() => this.poll(), this.interval);
    }

    stop() {
        if (this.timerId) {
            clearInterval(this.timerId);
            this.timerId = null;
        }
    }

    async poll() {
        try {
            const data = await API.checkAvailability(this.eventId);
            this.updateDOM(data);
        } catch (e) {
            // Silently fail on poll errors
            console.warn('Availability poll failed:', e);
        }
    }

    updateDOM(data) {
        const remainingEl = document.querySelector('[data-remaining-tickets]');
        const capacityEl = document.querySelector('[data-total-capacity]');

        if (!remainingEl || !data.remaining === undefined) return;

        const remaining = data.remaining;
        const total = data.total || parseInt(capacityEl?.textContent) || 100;
        const percentage = (remaining / total) * 100;

        remainingEl.textContent = remaining;

        // Update color coding
        remainingEl.classList.remove('high', 'medium', 'low');
        if (percentage > 50) {
            remainingEl.classList.add('high');
        } else if (percentage > 20) {
            remainingEl.classList.add('medium');
        } else {
            remainingEl.classList.add('low');
        }

        // Update quantity selector max
        const qtyMax = document.querySelector('[data-max-quantity]');
        if (qtyMax) {
            qtyMax.setAttribute('data-max-quantity', remaining);
            // Adjust current quantity if it exceeds remaining
            const qtyValue = document.querySelector('.quantity-value');
            if (qtyValue) {
                const currentQty = parseInt(qtyValue.textContent);
                if (currentQty > remaining) {
                    qtyValue.textContent = Math.max(1, remaining);
                }
            }
        }
    }
}

/* ============================================
   Mobile Navigation
   ============================================ */
class MobileNav {
    constructor() {
        this.toggle = document.getElementById('nav-toggle');
        this.navbar = document.querySelector('.navbar-nav');
        if (this.toggle && this.navbar) {
            this.init();
        }
    }

    init() {
        // Close on outside click
        document.addEventListener('click', (e) => {
            if (this.toggle.checked &&
                !this.navbar.contains(e.target) &&
                !e.target.closest('.nav-toggle-label')) {
                this.toggle.checked = false;
            }
        });

        // Close on nav link click
        const navLinks = this.navbar.querySelectorAll('.nav-link');
        navLinks.forEach(link => {
            link.addEventListener('click', () => {
                this.toggle.checked = false;
            });
        });

        // Close on ESC key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.toggle.checked) {
                this.toggle.checked = false;
            }
        });
    }
}

/* ============================================
   Scroll Animations (Intersection Observer)
   ============================================ */
class ScrollAnimations {
    constructor() {
        this.observer = null;
        this.init();
    }

    init() {
        const elements = document.querySelectorAll('.animate-on-scroll');
        if (elements.length === 0) return;

        this.observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('animate-visible');
                    this.observer.unobserve(entry.target);
                }
            });
        }, {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        });

        elements.forEach(el => this.observer.observe(el));
    }
}

/* ============================================
   Form Validation
   ============================================ */
class FormValidator {
    constructor(formElement) {
        this.form = formElement;
        this.errors = {};
        if (this.form) {
            this.init();
        }
    }

    init() {
        this.form.addEventListener('submit', (e) => {
            if (!this.validateAll()) {
                e.preventDefault();
            }
        });

        // Real-time validation on blur
        const inputs = this.form.querySelectorAll('.form-control, .form-input');
        inputs.forEach(input => {
            input.addEventListener('blur', () => {
                this.validateField(input);
            });

            // Clear error on input
            input.addEventListener('input', () => {
                this.clearFieldError(input);
            });
        });
    }

    validateAll() {
        this.errors = {};
        let isValid = true;
        const inputs = this.form.querySelectorAll('.form-control[required], .form-control[data-validate], .form-input[required], .form-input[data-validate]');

        inputs.forEach(input => {
            if (!this.validateField(input)) {
                isValid = false;
            }
        });

        return isValid;
    }

    validateField(input) {
        const name = input.name || input.id;
        const value = input.value.trim();
        const type = input.type;
        const validate = input.getAttribute('data-validate');
        let errorMsg = '';

        // Required check
        if (input.hasAttribute('required') && !value) {
            errorMsg = 'Bu alan zorunludur.';
        }
        // Email validation
        else if ((type === 'email' || validate === 'email') && value) {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(value)) {
                errorMsg = 'Geçerli bir e-posta adresi giriniz.';
            }
        }
        // Password validation
        else if ((type === 'password' || validate === 'password') && value) {
            if (value.length < 6) {
                errorMsg = 'Şifre en az 6 karakter olmalıdır.';
            }
        }
        // Password match
        else if (validate === 'password-match' && value) {
            const targetId = input.getAttribute('data-match');
            const targetInput = this.form.querySelector(`#${targetId}, [name="${targetId}"]`);
            if (targetInput && value !== targetInput.value) {
                errorMsg = 'Şifreler eşleşmiyor.';
            }
        }
        // Phone validation
        else if (validate === 'phone' && value) {
            const phoneRegex = /^(\+90|0)?[0-9]{10}$/;
            const cleaned = value.replace(/[\s\-\(\)]/g, '');
            if (!phoneRegex.test(cleaned)) {
                errorMsg = 'Geçerli bir telefon numarası giriniz.';
            }
        }
        // Min length
        else if (input.hasAttribute('minlength') && value) {
            const minLen = parseInt(input.getAttribute('minlength'));
            if (value.length < minLen) {
                errorMsg = `En az ${minLen} karakter giriniz.`;
            }
        }

        if (errorMsg) {
            this.showFieldError(input, errorMsg);
            this.errors[name] = errorMsg;
            return false;
        } else {
            this.clearFieldError(input);
            delete this.errors[name];
            return true;
        }
    }

    showFieldError(input, message) {
        input.classList.add('is-invalid');
        input.classList.remove('is-valid');

        // Remove existing error message
        const existingError = input.parentElement.querySelector('.invalid-feedback');
        if (existingError) {
            existingError.remove();
        }

        // Add new error message
        const errorEl = document.createElement('div');
        errorEl.className = 'invalid-feedback';
        errorEl.textContent = message;
        input.parentElement.appendChild(errorEl);
    }

    clearFieldError(input) {
        input.classList.remove('is-invalid');

        const existingError = input.parentElement.querySelector('.invalid-feedback');
        if (existingError) {
            existingError.remove();
        }

        // Mark as valid if has value
        if (input.value.trim()) {
            input.classList.add('is-valid');
        } else {
            input.classList.remove('is-valid');
        }
    }

    hasErrors() {
        return Object.keys(this.errors).length > 0;
    }
}

/* ============================================
   Quantity Selector
   ============================================ */
class QuantitySelector {
    constructor(containerEl) {
        this.container = containerEl;
        if (!this.container) return;

        this.minusBtn = this.container.querySelector('[data-qty-minus]');
        this.plusBtn = this.container.querySelector('[data-qty-plus]');
        this.valueEl = this.container.querySelector('.quantity-value');
        this.hiddenInput = this.container.querySelector('input[type="hidden"]');
        this.priceDisplay = document.querySelector('[data-total-price]');
        this.unitPrice = parseFloat(this.container.getAttribute('data-unit-price')) || 0;
        this.min = parseInt(this.container.getAttribute('data-min')) || 1;
        this.max = parseInt(this.container.getAttribute('data-max-quantity')) || 10;

        if (this.minusBtn && this.plusBtn && this.valueEl) {
            this.init();
        }
    }

    init() {
        this.minusBtn.addEventListener('click', () => this.decrease());
        this.plusBtn.addEventListener('click', () => this.increase());
        this.updateButtons();
        this.updateTotalPrice();
    }

    getValue() {
        return parseInt(this.valueEl.textContent) || 1;
    }

    setValue(val) {
        const clamped = Math.max(this.min, Math.min(this.max, val));
        this.valueEl.textContent = clamped;
        if (this.hiddenInput) {
            this.hiddenInput.value = clamped;
        }
        this.updateButtons();
        this.updateTotalPrice();
    }

    increase() {
        this.setValue(this.getValue() + 1);
    }

    decrease() {
        this.setValue(this.getValue() - 1);
    }

    updateButtons() {
        const val = this.getValue();
        this.minusBtn.disabled = val <= this.min;
        this.plusBtn.disabled = val >= this.max;
    }

    updateTotalPrice() {
        if (this.priceDisplay && this.unitPrice) {
            const total = (this.getValue() * this.unitPrice).toFixed(2);
            this.priceDisplay.textContent = total;
        }
    }
}

/* ============================================
   Search & Filter (for index.php)
   ============================================ */
class SearchFilter {
    constructor() {
        this.searchInput = document.getElementById('search-input') || document.getElementById('hero-search-input');
        this.categoryBtns = document.querySelectorAll('[data-category-filter]');
        this.citySelect = document.getElementById('city-filter');
        this.venueSelect = document.getElementById('venue-filter');
        this.dateInput = document.getElementById('date-filter');
        this.sortSelect = document.getElementById('sort-filter');
        this.eventCards = document.querySelectorAll('[data-event-card]');
        this.originalVenueOptions = this.venueSelect ? Array.from(this.venueSelect.querySelectorAll('option[data-city]')) : [];
        this.debounceTimer = null;

        if (this.eventCards.length > 0) {
            this.init();
        }
    }

    init() {
        // Search input with debounce
        if (this.searchInput) {
            this.searchInput.addEventListener('input', () => {
                clearTimeout(this.debounceTimer);
                this.debounceTimer = setTimeout(() => this.applyFilters(), 300);
            });
        }

        // Category filter buttons
        this.categoryBtns.forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                // Toggle active class
                this.categoryBtns.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                this.applyFilters();
            });
        });

        // City filter
        if (this.citySelect) {
            this.citySelect.addEventListener('change', () => {
                if (this.venueSelect) {
                    this.updateVenueOptions();
                }
                this.applyFilters();
            });
        }

        if (this.venueSelect) {
            this.venueSelect.addEventListener('change', () => this.applyFilters());
        }

        // Date filter
        if (this.dateInput) {
            this.dateInput.addEventListener('change', () => this.applyFilters());
        }

        if (this.sortSelect) {
            this.sortSelect.addEventListener('change', () => this.applyFilters());
        }

        if (this.venueSelect) {
            this.updateVenueOptions();
        }

        // Periodically refresh ordering to keep the nearest events first
        window.setInterval(() => {
            this.applyFilters();
        }, 30000);
    }

    updateVenueOptions() {
        if (!this.venueSelect || !this.citySelect) {
            return;
        }

        const selectedCity = this.citySelect.value;
        const options = this.originalVenueOptions;

        if (!selectedCity) {
            this.venueSelect.innerHTML = '<option value="">Önce şehir seçin</option>';
            this.venueSelect.disabled = true;
            return;
        }

        const matching = options.filter(opt => opt.dataset.city === selectedCity);
        if (matching.length === 0) {
            this.venueSelect.innerHTML = '<option value="">Bu şehirde mekan bulunamadı</option>';
            this.venueSelect.disabled = true;
            return;
        }

        const currentValue = this.venueSelect.value;
        this.venueSelect.disabled = false;
        this.venueSelect.innerHTML = '<option value="">Tüm Mekanlar</option>' + matching.map(opt => `<option value="${opt.value}" data-city="${opt.dataset.city}">${opt.textContent}</option>`).join('');

        if (currentValue && matching.some(opt => opt.value === currentValue)) {
            this.venueSelect.value = currentValue;
        }
    }

    applyFilters() {
        const searchTerm = this.searchInput ? this.searchInput.value.toLowerCase().trim() : '';
        const activeCategory = document.querySelector('[data-category-filter].active');
        const categorySlug = activeCategory ? activeCategory.getAttribute('data-category-filter') : 'all';
        const selectedCity = this.citySelect ? this.citySelect.value : '';
        const selectedVenue = this.venueSelect ? this.venueSelect.value : '';
        const selectedDate = this.dateInput ? this.dateInput.value : '';
        const sortValueRaw = this.sortSelect ? this.sortSelect.value : 'date-asc';
        const sortValue = sortValueRaw.replace('-', '_');

        let visibleCards = [];

        this.eventCards.forEach(card => {
            const title = (card.getAttribute('data-title') || '').toLowerCase();
            const category = card.getAttribute('data-category') || '';
            const city = card.getAttribute('data-city') || '';
            const date = card.getAttribute('data-date') || '';
            const venue = (card.getAttribute('data-venue') || '').toLowerCase();

            let visible = true;

            // Search filter
            if (searchTerm && !title.includes(searchTerm) && !venue.includes(searchTerm)) {
                visible = false;
            }

            // Category filter
            if (categorySlug && categorySlug !== 'all' && category !== categorySlug) {
                visible = false;
            }

            // City filter
            if (selectedCity && city !== selectedCity) {
                visible = false;
            }

            // Venue filter
            if (selectedVenue && venue !== selectedVenue.toLowerCase()) {
                visible = false;
            }

            // Date filter
            if (selectedDate) {
                const cardDate = new Date(date);
                const today = new Date();
                today.setHours(0, 0, 0, 0);

                if (selectedDate === 'today') {
                    const tomorrow = new Date(today);
                    tomorrow.setDate(tomorrow.getDate() + 1);
                    if (cardDate < today || cardDate >= tomorrow) visible = false;
                } else if (selectedDate === 'week') {
                    const weekEnd = new Date(today);
                    weekEnd.setDate(weekEnd.getDate() + 7);
                    if (cardDate < today || cardDate > weekEnd) visible = false;
                } else if (selectedDate === 'month') {
                    const monthEnd = new Date(today);
                    monthEnd.setDate(monthEnd.getDate() + 30);
                    if (cardDate < today || cardDate > monthEnd) visible = false;
                }
            }

            card.style.display = visible ? '' : 'none';
            if (visible) visibleCards.push(card);
        });

        if (visibleCards.length > 0) {
            const grid = document.getElementById('events-grid');
            visibleCards.sort((a, b) => {
                const dateA = new Date(a.getAttribute('data-date'));
                const dateB = new Date(b.getAttribute('data-date'));

                switch (sortValue) {
                    case 'price_asc':
                        return parseFloat(a.getAttribute('data-price')) - parseFloat(b.getAttribute('data-price'));
                    case 'price_desc':
                        return parseFloat(b.getAttribute('data-price')) - parseFloat(a.getAttribute('data-price'));
                    case 'date_desc':
                        return dateB - dateA;
                    case 'popularity':
                        return 0;
                    case 'date_asc':
                    default:
                        return dateA - dateB;
                }
            });
            visibleCards.forEach(card => grid.appendChild(card));
        }

        // Show/hide empty state
        const emptyState = document.getElementById('no-results');
        if (emptyState) {
            emptyState.style.display = visibleCards.length === 0 ? 'block' : 'none';
        }
    }
}

/* ============================================
   Ripple Effect
   ============================================ */
class RippleEffect {
    constructor() {
        this.init();
    }

    init() {
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('.btn');
            if (!btn) return;

            const rect = btn.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;
            const size = Math.max(rect.width, rect.height) * 2;

            const ripple = document.createElement('span');
            ripple.className = 'ripple';
            ripple.style.width = ripple.style.height = `${size}px`;
            ripple.style.left = `${x - size / 2}px`;
            ripple.style.top = `${y - size / 2}px`;

            btn.appendChild(ripple);

            ripple.addEventListener('animationend', () => {
                ripple.remove();
            });
        });
    }
}

/* ============================================
   Modal Manager
   ============================================ */
class ModalManager {
    static open(modalId) {
        const overlay = document.getElementById(modalId);
        if (!overlay) return;

        overlay.classList.add('active');
        document.body.style.overflow = 'hidden';

        // Focus trap - focus first focusable element
        const focusable = overlay.querySelector('input, button, select, textarea, [tabindex]:not([tabindex="-1"])');
        if (focusable) {
            setTimeout(() => focusable.focus(), 100);
        }
    }

    static close(modalId) {
        const overlay = document.getElementById(modalId);
        if (!overlay) return;

        overlay.classList.remove('active');
        document.body.style.overflow = '';
    }

    static init() {
        // Close on backdrop click
        document.addEventListener('click', (e) => {
            if (e.target.classList.contains('modal-overlay')) {
                e.target.classList.remove('active');
                document.body.style.overflow = '';
            }
        });

        // Close on close button click
        document.addEventListener('click', (e) => {
            const closeBtn = e.target.closest('.modal-close, [data-modal-close]');
            if (closeBtn) {
                const overlay = closeBtn.closest('.modal-overlay');
                if (overlay) {
                    overlay.classList.remove('active');
                    document.body.style.overflow = '';
                }
            }
        });

        // Close on ESC key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                const activeModal = document.querySelector('.modal-overlay.active');
                if (activeModal) {
                    activeModal.classList.remove('active');
                    document.body.style.overflow = '';
                }
            }
        });

        // Open on trigger click
        document.addEventListener('click', (e) => {
            const trigger = e.target.closest('[data-modal-open]');
            if (trigger) {
                e.preventDefault();
                const modalId = trigger.getAttribute('data-modal-open');
                ModalManager.open(modalId);
            }
        });
    }
}

/* ============================================
   Navbar Scroll Effect
   ============================================ */
class NavbarScroll {
    constructor() {
        this.navbar = document.querySelector('.navbar');
        if (this.navbar) {
            this.init();
        }
    }

    init() {
        const onScroll = () => {
            if (window.scrollY > 50) {
                this.navbar.classList.add('scrolled');
            } else {
                this.navbar.classList.remove('scrolled');
            }
        };

        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll(); // Initial check
    }
}

/* ============================================
   Back to Top Button
   ============================================ */
class BackToTop {
    constructor() {
        this.btn = document.querySelector('.back-to-top');
        if (this.btn) {
            this.init();
        }
    }

    init() {
        window.addEventListener('scroll', () => {
            if (window.scrollY > 400) {
                this.btn.classList.add('visible');
            } else {
                this.btn.classList.remove('visible');
            }
        }, { passive: true });

        this.btn.addEventListener('click', (e) => {
            e.preventDefault();
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        });
    }
}

/* ============================================
   Password Strength Meter
   ============================================ */
class PasswordStrength {
    constructor(inputEl, meterEl) {
        this.input = inputEl;
        this.meter = meterEl;
        if (this.input && this.meter) {
            this.init();
        }
    }

    init() {
        this.input.addEventListener('input', () => {
            const strength = this.calculate(this.input.value);
            this.updateMeter(strength);
        });
    }

    calculate(password) {
        let score = 0;
        if (password.length >= 6) score++;
        if (password.length >= 10) score++;
        if (/[a-z]/.test(password) && /[A-Z]/.test(password)) score++;
        if (/[0-9]/.test(password)) score++;
        if (/[^a-zA-Z0-9]/.test(password)) score++;
        return Math.min(score, 4);
    }

    updateMeter(score) {
        const labels = ['', 'Zayıf', 'Orta', 'İyi', 'Güçlü'];
        const colors = ['', 'var(--danger)', 'var(--warning)', 'var(--accent-secondary)', 'var(--success)'];
        const widths = ['0%', '25%', '50%', '75%', '100%'];

        this.meter.innerHTML = `
            <div style="height:4px; background:var(--bg-tertiary); border-radius:2px; margin-top:8px; overflow:hidden;">
                <div style="height:100%; width:${widths[score]}; background:${colors[score]}; border-radius:2px; transition:all 0.3s ease;"></div>
            </div>
            <small style="color:${colors[score]}; font-size:0.75rem; margin-top:4px; display:block;">${labels[score]}</small>
        `;
    }
}

/* ============================================
   Countdown Display (for event pages)
   ============================================ */
class EventCountdown {
    constructor(element, targetDate) {
        this.element = element;
        this.targetDate = new Date(targetDate).getTime();
        this.intervalId = null;
    }

    start() {
        this.update();
        this.intervalId = setInterval(() => this.update(), 1000);
    }

    stop() {
        if (this.intervalId) {
            clearInterval(this.intervalId);
            this.intervalId = null;
        }
    }

    update() {
        const now = Date.now();
        const diff = this.targetDate - now;

        if (diff <= 0) {
            this.element.innerHTML = '<span class="text-danger">Etkinlik başladı!</span>';
            this.stop();
            return;
        }

        const days = Math.floor(diff / (1000 * 60 * 60 * 24));
        const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
        const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
        const seconds = Math.floor((diff % (1000 * 60)) / 1000);

        this.element.innerHTML = `
            <div class="flex-center gap-2">
                <div class="text-center">
                    <div style="font-size:1.5rem;font-weight:700;font-family:'Courier New',monospace;">${String(days).padStart(2, '0')}</div>
                    <div style="font-size:0.65rem;color:var(--text-muted);text-transform:uppercase;">Gün</div>
                </div>
                <span style="font-size:1.5rem;color:var(--text-muted);">:</span>
                <div class="text-center">
                    <div style="font-size:1.5rem;font-weight:700;font-family:'Courier New',monospace;">${String(hours).padStart(2, '0')}</div>
                    <div style="font-size:0.65rem;color:var(--text-muted);text-transform:uppercase;">Saat</div>
                </div>
                <span style="font-size:1.5rem;color:var(--text-muted);">:</span>
                <div class="text-center">
                    <div style="font-size:1.5rem;font-weight:700;font-family:'Courier New',monospace;">${String(minutes).padStart(2, '0')}</div>
                    <div style="font-size:0.65rem;color:var(--text-muted);text-transform:uppercase;">Dk</div>
                </div>
                <span style="font-size:1.5rem;color:var(--text-muted);">:</span>
                <div class="text-center">
                    <div style="font-size:1.5rem;font-weight:700;font-family:'Courier New',monospace;">${String(seconds).padStart(2, '0')}</div>
                    <div style="font-size:0.65rem;color:var(--text-muted);text-transform:uppercase;">Sn</div>
                </div>
            </div>
        `;
    }
}

/* ============================================
   Confirm Dialog (replaces native confirm)
   ============================================ */
class ConfirmDialog {
    static show(message, onConfirm, onCancel) {
        // Create modal overlay
        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay active';
        overlay.id = 'confirm-dialog-overlay';

        overlay.innerHTML = `
            <div class="modal" style="max-width:400px;">
                <div class="modal-header">
                    <h3 class="modal-title">⚠️ Onay</h3>
                    <button class="modal-close" data-modal-close>&times;</button>
                </div>
                <div class="modal-body">
                    <p style="color:var(--text-secondary); font-size:0.95rem; line-height:1.6;">${message}</p>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" id="confirm-cancel">İptal</button>
                    <button class="btn btn-danger" id="confirm-ok">Evet, Devam Et</button>
                </div>
            </div>
        `;

        document.body.appendChild(overlay);
        document.body.style.overflow = 'hidden';

        const cleanup = () => {
            overlay.classList.remove('active');
            document.body.style.overflow = '';
            setTimeout(() => overlay.remove(), 300);
        };

        overlay.querySelector('#confirm-ok').addEventListener('click', () => {
            cleanup();
            if (typeof onConfirm === 'function') onConfirm();
        });

        overlay.querySelector('#confirm-cancel').addEventListener('click', () => {
            cleanup();
            if (typeof onCancel === 'function') onCancel();
        });

        overlay.querySelector('.modal-close').addEventListener('click', () => {
            cleanup();
            if (typeof onCancel === 'function') onCancel();
        });

        // Close on backdrop click
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) {
                cleanup();
                if (typeof onCancel === 'function') onCancel();
            }
        });
    }
}

/* ============================================
   Image Lazy Loader
   ============================================ */
class LazyLoader {
    constructor() {
        this.init();
    }

    init() {
        const images = document.querySelectorAll('img[data-src]');
        if (images.length === 0) return;

        if ('IntersectionObserver' in window) {
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        img.src = img.getAttribute('data-src');
                        img.removeAttribute('data-src');
                        img.addEventListener('load', () => {
                            img.classList.add('loaded');
                        });
                        observer.unobserve(img);
                    }
                });
            }, { rootMargin: '100px' });

            images.forEach(img => observer.observe(img));
        } else {
            // Fallback: load all images
            images.forEach(img => {
                img.src = img.getAttribute('data-src');
                img.removeAttribute('data-src');
            });
        }
    }
}

/* ============================================
   Smooth Scroll for Anchor Links
   ============================================ */
class SmoothScroll {
    constructor() {
        this.init();
    }

    init() {
        document.addEventListener('click', (e) => {
            const link = e.target.closest('a[href^="#"]');
            if (!link) return;

            const targetId = link.getAttribute('href');
            if (targetId === '#') return;

            const target = document.querySelector(targetId);
            if (target) {
                e.preventDefault();
                const navHeight = document.querySelector('.navbar')?.offsetHeight || 70;
                const top = target.getBoundingClientRect().top + window.scrollY - navHeight - 20;

                window.scrollTo({
                    top: top,
                    behavior: 'smooth'
                });
            }
        });
    }
}

/* ============================================
   Copy to Clipboard Utility
   ============================================ */
function copyToClipboard(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(() => {
            toast.success('Panoya kopyalandı!');
        }).catch(() => {
            fallbackCopy(text);
        });
    } else {
        fallbackCopy(text);
    }
}

function fallbackCopy(text) {
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.left = '-9999px';
    document.body.appendChild(textarea);
    textarea.select();
    try {
        document.execCommand('copy');
        toast.success('Panoya kopyalandı!');
    } catch (err) {
        toast.error('Kopyalama başarısız oldu.');
    }
    document.body.removeChild(textarea);
}

function showFormInputError(input, message) {
    if (!input) return;
    input.classList.add('is-invalid');
    input.classList.remove('is-valid');

    const existingError = input.parentElement.querySelector('.invalid-feedback');
    if (existingError) {
        existingError.remove();
    }

    const errorEl = document.createElement('div');
    errorEl.className = 'invalid-feedback';
    errorEl.textContent = message;
    input.parentElement.appendChild(errorEl);
}

function clearFormInputError(input) {
    if (!input) return;
    input.classList.remove('is-invalid');

    const existingError = input.parentElement.querySelector('.invalid-feedback');
    if (existingError) {
        existingError.remove();
    }

    if (input.value.trim()) {
        input.classList.add('is-valid');
    } else {
        input.classList.remove('is-valid');
    }
}

function clearEventDateTimeErrors(form) {
    ['event_date_date', 'event_date_time', 'end_date_date', 'end_date_time'].forEach(name => {
        const input = form.querySelector(`[name="${name}"]`);
        if (input) {
            clearFormInputError(input);
        }
    });
}

function validateAdminEventDateTime(form) {
    const startDate = form.querySelector('[name="event_date_date"]');
    const startTime = form.querySelector('[name="event_date_time"]');
    const endDate = form.querySelector('[name="end_date_date"]');
    const endTime = form.querySelector('[name="end_date_time"]');

    if (!startDate || !startTime) {
        return true;
    }

    clearEventDateTimeErrors(form);
    let isValid = true;

    const startDateValue = startDate.value.trim();
    const startTimeValue = startTime.value.trim();
    let startDateTime = null;

    if (!startDateValue) {
        showFormInputError(startDate, 'Başlangıç tarihi zorunludur.');
        isValid = false;
    }
    if (!startTimeValue) {
        showFormInputError(startTime, 'Başlangıç saati zorunludur.');
        isValid = false;
    }

    if (startDateValue && startTimeValue) {
        startDateTime = new Date(`${startDateValue}T${startTimeValue}`);
        if (Number.isNaN(startDateTime.getTime())) {
            showFormInputError(startDate, 'Geçersiz başlangıç tarihi/saat.');
            isValid = false;
        }
    }

    if (startDateTime) {
        const now = new Date();
        if (startDateTime < now) {
            showFormInputError(startDate, 'Etkinlik tarihi bugünden önce olamaz.');
            showFormInputError(startTime, 'Etkinlik saati geçmiş olamaz.');
            isValid = false;
        }
    }

    const endDateValue = endDate ? endDate.value.trim() : '';
    const endTimeValue = endTime ? endTime.value.trim() : '';

    if ((endDate && endDateValue) || (endTime && endTimeValue)) {
        if (endDate && !endDateValue) {
            showFormInputError(endDate, 'Bitiş tarihi girilmelidir.');
            isValid = false;
        }
        if (endTime && !endTimeValue) {
            showFormInputError(endTime, 'Bitiş saati girilmelidir.');
            isValid = false;
        }

        if (endDateValue && endTimeValue) {
            const endDateTime = new Date(`${endDateValue}T${endTimeValue}`);
            if (Number.isNaN(endDateTime.getTime())) {
                if (endDate) showFormInputError(endDate, 'Geçersiz bitiş tarihi/saat.');
                isValid = false;
            } else if (startDateTime && endDateTime < startDateTime) {
                if (endDate) showFormInputError(endDate, 'Bitiş tarihi başlangıçtan sonra olmalıdır.');
                if (endTime) showFormInputError(endTime, 'Bitiş tarihi başlangıçtan sonra olmalıdır.');
                isValid = false;
            }
        }
    }

    return isValid;
}

/* ============================================
   Format Helpers
   ============================================ */
function formatCurrency(amount) {
    return new Intl.NumberFormat('tr-TR', {
        style: 'currency',
        currency: 'TRY',
        minimumFractionDigits: 2
    }).format(amount);
}

function formatDate(dateString) {
    const date = new Date(dateString);
    return new Intl.DateTimeFormat('tr-TR', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    }).format(date);
}

/* ============================================
   Initialize Everything on DOM Ready
   ============================================ */
document.addEventListener('DOMContentLoaded', () => {
    // Core UI
    new MobileNav();
    new NavbarScroll();
    new BackToTop();
    new ScrollAnimations();
    new RippleEffect();
    new SmoothScroll();
    new LazyLoader();

    // Modal system
    ModalManager.init();

    // Form validation - auto-init for forms with data-validate attribute
    document.querySelectorAll('form[data-validate]').forEach(form => {
        new FormValidator(form);
    });

    // Password strength meter
    const pwInput = document.getElementById('password');
    const pwMeter = document.getElementById('password-strength');
    if (pwInput && pwMeter) {
        new PasswordStrength(pwInput, pwMeter);
    }

    // Quantity selectors
    document.querySelectorAll('.quantity-selector').forEach(el => {
        new QuantitySelector(el);
    });

    // Search and filter
    new SearchFilter();

    // Event countdown timers
    document.querySelectorAll('[data-event-countdown]').forEach(el => {
        const targetDate = el.getAttribute('data-event-countdown');
        if (targetDate) {
            new EventCountdown(el, targetDate).start();
        }
    });

    // Reservation timers - auto-init
    document.querySelectorAll('[data-reservation-timer]').forEach(el => {
        const reservationId = el.getAttribute('data-reservation-timer');
        const expiresAt = el.getAttribute('data-expires-at');
        if (reservationId && expiresAt) {
            const timer = new ReservationTimer(reservationId, expiresAt, (id) => {
                toast.warning('Rezervasyon süresi doldu! Sayfa yenileniyor...');
                setTimeout(() => window.location.reload(), 2000);
            });
            timer.start();
        }
    });

    // Availability poller - auto-init
    const pollerEl = document.querySelector('[data-poll-event]');
    if (pollerEl) {
        const eventId = pollerEl.getAttribute('data-poll-event');
        const poller = new AvailabilityPoller(eventId);
        poller.start();
    }

    // Copy to clipboard buttons
    document.addEventListener('click', (e) => {
        const copyBtn = e.target.closest('[data-copy]');
        if (copyBtn) {
            const text = copyBtn.getAttribute('data-copy');
            copyToClipboard(text);
        }
    });

    // Confirmation dialogs on links/forms
    document.addEventListener('click', (e) => {
        const confirmEl = e.target.closest('[data-confirm]');
        if (confirmEl) {
            e.preventDefault();
            const message = confirmEl.getAttribute('data-confirm');
            const href = confirmEl.getAttribute('href');

            ConfirmDialog.show(message, () => {
                if (href) {
                    window.location.href = href;
                } else if (confirmEl.type === 'submit') {
                    confirmEl.closest('form')?.submit();
                }
            });
        }
    });

    // ===== Basit Görsel Yükleme Kontrolü =====
    const MAX_FILE_SIZE = 20 * 1024 * 1024; // 20 MB

    // Dosya seçildiğinde bilgilendirme göster
    document.addEventListener('change', (e) => {
        const input = e.target.closest('input[type="file"][name="image_file"]');
        if (!input || !input.files || input.files.length === 0) return;

        const file = input.files[0];
        const sizeMB = (file.size / (1024 * 1024)).toFixed(2);
        const isLarge = file.size > MAX_FILE_SIZE;

        if (isLarge) {
            toast.warning(`⚠️ Dosya çok büyük (${sizeMB} MB). Sunucu otomatik olarak boyutlandıracak.`);
        } else if (file.size > 5 * 1024 * 1024) {
            toast.info(`📸 Dosya boyutu: ${sizeMB} MB`);
        }
    });

    // Normal form submit
    document.addEventListener('submit', (e) => {
        const form = e.target.closest('form');
        if (!form) return;
        if (form.matches('form[data-ajax="true"]')) {
            return; // AJAX formları ayrı işle
        }

        if (!validateAdminEventDateTime(form)) {
            e.preventDefault();
            return;
        }

        const fileInput = form.querySelector('input[type="file"][name="image_file"]');
        if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
            return; // Dosya yok, normal submit yap
        }

        const file = fileInput.files[0];
        if (file.size > MAX_FILE_SIZE) {
            e.preventDefault();
            toast.error(`Dosya çok büyük (maks: 20 MB). Lütfen daha küçük bir dosya seçin.`);
            return;
        }

        // Dosya yolunda, normal submit
        form.submit();
    });

    // AJAX form submit (modal formları)
    document.addEventListener('submit', (e) => {
        const form = e.target.closest('form[data-ajax="true"]');
        if (!form) return;
        e.preventDefault();

        const fileInput = form.querySelector('input[type="file"][name="image_file"]');
        if (fileInput && fileInput.files && fileInput.files[0]) {
            const file = fileInput.files[0];
            if (file.size > MAX_FILE_SIZE) {
                toast.error(`Dosya çok büyük (maks: 20 MB).`);
                return;
            }
        }

        const modal = form.closest('.modal-overlay');
        const submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.disabled = true;

        const formData = new FormData(form);

        const formAction = form.getAttribute('action') || (typeof APP_BASE !== 'undefined' ? APP_BASE + 'admin/manage_events.php' : '');
        fetch(formAction, {
            method: (form.getAttribute('method') || 'POST').toUpperCase(),
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin'
        }).then(res => {
            const contentType = res.headers.get('content-type') || '';
            if (!contentType.includes('application/json')) {
                throw new Error('Sunucudan geçersiz yanıt alındı.');
            }
            return res.json();
        })
        .then(data => {
            if (data.success) {
                toast.success('Etkinlik oluşturuldu.');
                if (modal) ModalManager.close(modal.id);
                window.location.reload();
            } else {
                const msg = (data.errors && data.errors.length) ? data.errors.join('\n') : (data.message || 'Bir hata oluştu.');
                toast.error(msg);
            }
        }).catch(err => {
            console.error(err);
            toast.error('Sunucuya bağlanırken hata oluştu.');
        }).finally(() => {
            if (submitBtn) submitBtn.disabled = false;
        });
    });

    // Event admin search filter
    const eventSearchInput = document.getElementById('event-search');
    if (eventSearchInput) {
        eventSearchInput.addEventListener('input', (e) => {
            const searchTerm = e.target.value.toLowerCase().trim();
            const eventItems = document.querySelectorAll('.event-list-item');
            
            eventItems.forEach(item => {
                const searchText = item.getAttribute('data-search-text') || '';
                if (searchText.includes(searchTerm) || searchTerm === '') {
                    item.style.display = '';
                } else {
                    item.style.display = 'none';
                }
            });
        });
    }

    // Console greeting
    console.log(
        '%c🎫 Bilet-Geç %c v1.0 ',
        'background: linear-gradient(135deg, #7c3aed, #06b6d4); color: white; padding: 8px 12px; border-radius: 6px 0 0 6px; font-weight: bold; font-size: 14px;',
        'background: #1a1a2e; color: #94a3b8; padding: 8px 12px; border-radius: 0 6px 6px 0; font-size: 14px;'
    );
});
