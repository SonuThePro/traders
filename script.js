/**
 * Enhanced Frontend JavaScript for M. Pimpale Traders
 * 
 * Features:
 * - Robust error handling and retry logic
 * - Offline support with fallbacks
 * - Performance optimization
 * - Accessibility improvements
 * - Analytics tracking
 * - Progressive loading
 */

'use strict';

class PimpaleStore {
    constructor() {
        this.API_BASE = 'api.php';
        this.products = [];
        this.cart = [];
        this.isOnline = navigator.onLine;
        this.retryCount = 0;
        this.maxRetries = 3;
        this.cache = new Map();
        this.observers = new Map();
        
        this.init();
    }
    
    async init() {
        try {
            // Set up event listeners
            this.setupEventListeners();
            
            // Initialize accessibility features
            this.initAccessibility();
            
            // Load cart from storage
            this.loadCart();
            
            // Load products with fallback
            await this.loadProducts();
            
            // Set up periodic sync if online
            if (this.isOnline) {
                this.setupPeriodicSync();
            }
            
            console.log('Store initialized successfully');
        } catch (error) {
            console.error('Store initialization failed:', error);
            this.showNotification('Store failed to load. Using offline mode.', 'error');
        }
    }
    
    setupEventListeners() {
        // Network status
        window.addEventListener('online', () => {
            this.isOnline = true;
            this.showNotification('Back online! Syncing data...', 'info');
            this.loadProducts();
        });
        
        window.addEventListener('offline', () => {
            this.isOnline = false;
            this.showNotification('Offline mode activated', 'warning');
        });
        
        // DOM content loaded
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.setupUI());
        } else {
            this.setupUI();
        }
        
        // Page visibility for analytics
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                this.trackEvent('page_view');
            }
        });
        
        // Handle page unload
        window.addEventListener('beforeunload', () => {
            this.saveCart();
        });
    }
    
    setupUI() {
        const elements = {
            openCartBtn: document.getElementById('openCartBtn'),
            closeCartBtn: document.getElementById('closeCartBtn'),
            checkoutBtn: document.getElementById('checkoutBtn'),
            clearCartBtn: document.getElementById('clearCartBtn')
        };
        
        // Add event listeners with error handling
        Object.entries(elements).forEach(([key, element]) => {
            if (element) {
                const handler = this.getEventHandler(key);
                if (handler) {
                    element.addEventListener('click', (e) => {
                        try {
                            handler.call(this, e);
                        } catch (error) {
                            console.error(`Error in ${key} handler:`, error);
                            this.showNotification('Action failed. Please try again.', 'error');
                        }
                    });
                }
            }
        });
        
        // Set up search functionality
        this.setupSearch();
        
        // Update UI
        this.updateCartUI();
    }
    
    getEventHandler(key) {
        const handlers = {
            openCartBtn: this.openCart,
            closeCartBtn: this.closeCart,
            checkoutBtn: this.checkout,
            clearCartBtn: this.handleClearCart
        };
        return handlers[key];
    }
    
    initAccessibility() {
        // Add ARIA labels and keyboard navigation
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                this.closeCart();
            }
        });
        
        // Focus management for cart drawer
        const cartDrawer = document.getElementById('cartDrawer');
        if (cartDrawer) {
            cartDrawer.addEventListener('transitionend', (e) => {
                if (e.target === cartDrawer && cartDrawer.classList.contains('open')) {
                    const closeBtn = document.getElementById('closeCartBtn');
                    if (closeBtn) closeBtn.focus();
                }
            });
        }
    }
    
    async loadProducts() {
        const cacheKey = 'products';
        const cacheExpiry = 5 * 60 * 1000; // 5 minutes
        
        // Check cache first
        if (this.cache.has(cacheKey)) {
            const cached = this.cache.get(cacheKey);
            if (Date.now() - cached.timestamp < cacheExpiry) {
                this.products = cached.data;
                this.renderProducts();
                return;
            }
        }
        
        try {
            if (this.isOnline) {
                const products = await this.apiRequest('GET', 'products');
                this.products = products;
                
                // Cache successful response
                this.cache.set(cacheKey, {
                    data: products,
                    timestamp: Date.now()
                });
                
                // Save to localStorage as backup
                this.saveToStorage('products_backup', products);
            } else {
                // Use cached data or fallback
                const backup = this.getFromStorage('products_backup');
                this.products = backup || this.getFallbackProducts();
            }
            
            this.renderProducts();
            
        } catch (error) {
            console.error('Failed to load products:', error);
            
            // Try backup data
            const backup = this.getFromStorage('products_backup');
            if (backup) {
                this.products = backup;
                this.showNotification('Using cached product data', 'warning');
            } else {
                this.products = this.getFallbackProducts();
                this.showNotification('Using default product list', 'warning');
            }
            
            this.renderProducts();
        }
    }
    
    async apiRequest(method, endpoint, data = null) {
        const url = `${this.API_BASE}?endpoint=${encodeURIComponent(endpoint)}`;
        
        const options = {
            method: method,
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        };
        
        if (data && (method === 'POST' || method === 'PUT')) {
            options.body = JSON.stringify(data);
        }
        
        let lastError;
        
        for (let attempt = 0; attempt <= this.maxRetries; attempt++) {
            try {
                const response = await fetch(url, {
                    ...options,
                    signal: AbortSignal.timeout(10000) // 10 second timeout
                });
                
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                
                const result = await response.json();
                
                if (!result.success) {
                    throw new Error(result.error || 'API request failed');
                }
                
                return result.data;
                
            } catch (error) {
                lastError = error;
                
                if (attempt < this.maxRetries && this.shouldRetry(error)) {
                    const delay = Math.pow(2, attempt) * 1000; // Exponential backoff
                    await this.sleep(delay);
                    continue;
                }
                
                break;
            }
        }
        
        throw lastError;
    }
    
    shouldRetry(error) {
        // Retry on network errors, timeouts, and 5xx server errors
        return error.name === 'TypeError' || // Network error
               error.name === 'AbortError' || // Timeout
               (error.message.includes('HTTP 5')); // Server error
    }
    
    sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }
    
    renderProducts() {
        const grid = document.getElementById('productGrid');
        if (!grid) return;
        
        // Show loading state
        grid.innerHTML = '<div class="loading">Loading products...</div>';
        
        // Use requestIdleCallback for smooth rendering
        if ('requestIdleCallback' in window) {
            requestIdleCallback(() => this.doRenderProducts(grid));
        } else {
            setTimeout(() => this.doRenderProducts(grid), 0);
        }
    }
    
    doRenderProducts(grid) {
        const fragment = document.createDocumentFragment();
        
        this.products.forEach((product, index) => {
            const card = this.createProductCard(product);
            
            // Lazy load images for better performance
            if (index > 6) { // Only lazy load after first 6 items
                card.querySelector('img').setAttribute('loading', 'lazy');
            }
            
            fragment.appendChild(card);
        });
        
        grid.innerHTML = '';
        grid.appendChild(fragment);
        
        // Track products loaded
        this.trackEvent('products_loaded', { count: this.products.length });
    }
    
    createProductCard(product) {
        const card = document.createElement('div');
        card.className = 'card';
        card.setAttribute('data-product-id', product.id);
        
        const imageUrl = product.image_url || 'https://via.placeholder.com/300x160?text=No+Image';
        const unit = product.unit || 'kg';
        
        card.innerHTML = `
            <img src="${this.escapeHtml(imageUrl)}" 
                 alt="${this.escapeHtml(product.name)}" 
                 loading="lazy"
                 onerror="this.src='https://via.placeholder.com/300x160?text=No+Image'">
            <div style="padding-top:10px">
                <div style="display:flex;align-items:center;justify-content:space-between">
                    <div style="font-weight:700">${this.escapeHtml(product.name)}</div>
                    <div class="price">₹${product.price}/${unit}</div>
                </div>
                <div class="desc">${this.escapeHtml(product.description || '')}</div>
                <div style="margin-top:10px;display:flex;gap:8px;align-items:center">
                    <button class="btn quick-order" 
                            aria-label="Quick order ${this.escapeHtml(product.name)}"
                            data-product-id="${product.id}">
                        Order
                    </button>
                    <button class="btn ghost add-to-cart" 
                            aria-label="Add ${this.escapeHtml(product.name)} to cart"
                            data-product-id="${product.id}">
                        Add to cart
                    </button>
                </div>
            </div>
        `;
        
        // Add event listeners
        const quickOrderBtn = card.querySelector('.quick-order');
        const addToCartBtn = card.querySelector('.add-to-cart');
        
        quickOrderBtn.addEventListener('click', (e) => {
            e.preventDefault();
            this.quickOrder(product.id);
        });
        
        addToCartBtn.addEventListener('click', (e) => {
            e.preventDefault();
            this.addToCart(product.id);
        });
        
        return card;
    }
    
    addToCart(productId, quantity = 1) {
        try {
            const product = this.findProduct(productId);
            if (!product) {
                throw new Error('Product not found');
            }
            
            const existingItem = this.cart.find(item => item.id === productId);
            
            if (existingItem) {
                existingItem.qty += quantity;
            } else {
                this.cart.push({
                    id: product.id,
                    name: product.name,
                    price: product.price,
                    unit: product.unit || 'kg',
                    qty: quantity
                });
            }
            
            this.saveCart();
            this.updateCartUI();
            this.showNotification(`${product.name} added to cart`);
            this.trackEvent('add_to_cart', { product_id: productId, quantity });
            
        } catch (error) {
            console.error('Add to cart failed:', error);
            this.showNotification('Failed to add item to cart', 'error');
        }
    }
    
    updateQuantity(productId, delta) {
        try {
            const item = this.cart.find(item => item.id === productId);
            if (!item) return;
            
            item.qty += delta;
            
            if (item.qty <= 0) {
                this.removeItem(productId);
            } else {
                this.saveCart();
                this.updateCartUI();
            }
            
        } catch (error) {
            console.error('Update quantity failed:', error);
            this.showNotification('Failed to update quantity', 'error');
        }
    }
    
    removeItem(productId) {
        try {
            this.cart = this.cart.filter(item => item.id !== productId);
            this.saveCart();
            this.updateCartUI();
            this.trackEvent('remove_from_cart', { product_id: productId });
            
        } catch (error) {
            console.error('Remove item failed:', error);
            this.showNotification('Failed to remove item', 'error');
        }
    }
    
    clearCart() {
        try {
            this.cart = [];
            this.saveCart();
            this.updateCartUI();
            this.trackEvent('clear_cart');
            
        } catch (error) {
            console.error('Clear cart failed:', error);
            this.showNotification('Failed to clear cart', 'error');
        }
    }
    
    updateCartUI() {
        const countEl = document.getElementById('cartCount');
        const itemsEl = document.getElementById('cartItems');
        const totalEl = document.getElementById('cartTotal');
        
        const count = this.getCartCount();
        const total = this.getCartTotal();
        
        if (countEl) {
            countEl.textContent = count;
            countEl.style.display = count > 0 ? 'inline' : 'none';
        }
        
        if (totalEl) {
            totalEl.textContent = `₹${total.toLocaleString('en-IN')}`;
        }
        
        if (itemsEl) {
            if (this.cart.length === 0) {
                itemsEl.innerHTML = '<div class="note">Your cart is empty.</div>';
                return;
            }
            
            const fragment = document.createDocumentFragment();
            
            this.cart.forEach(item => {
                const row = this.createCartRow(item);
                fragment.appendChild(row);
            });
            
            itemsEl.innerHTML = '';
            itemsEl.appendChild(fragment);
        }
    }
    
    createCartRow(item) {
        const product = this.findProduct(item.id);
        const imageUrl = product?.image_url || 'https://via.placeholder.com/56x44?text=No+Image';
        
        const row = document.createElement('div');
        row.className = 'cart-row';
        row.setAttribute('data-item-id', item.id);
        
        row.innerHTML = `
            <img src="${this.escapeHtml(imageUrl)}" 
                 alt="${this.escapeHtml(item.name)}" 
                 loading="lazy"
                 onerror="this.src='https://via.placeholder.com/56x44?text=No+Image'">
            <div style="flex:1">
                <div style="display:flex;justify-content:space-between;align-items:center">
                    <div style="font-weight:700">${this.escapeHtml(item.name)}</div>
                    <div class="price">₹${item.price}/${item.unit}</div>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:6px">
                    <div class="qty">
                        <button class="qty-btn" data-action="decrease" data-item-id="${item.id}"
                                aria-label="Decrease quantity of ${this.escapeHtml(item.name)}">-</button>
                        <div class="note" style="min-width:26px;text-align:center">${item.qty} ${item.unit}</div>
                        <button class="qty-btn" data-action="increase" data-item-id="${item.id}"
                                aria-label="Increase quantity of ${this.escapeHtml(item.name)}">+</button>
                    </div>
                    <div style="display:flex;flex-direction:column;align-items:flex-end">
                        <div style="font-weight:700">₹${(item.price * item.qty).toLocaleString('en-IN')}</div>
                        <button class="ghost remove-btn" data-item-id="${item.id}"
                                style="margin-top:6px">Remove</button>
                    </div>
                </div>
            </div>
        `;
        
        // Add event listeners
        row.querySelectorAll('.qty-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const action = btn.getAttribute('data-action');
                const itemId = parseInt(btn.getAttribute('data-item-id'));
                const delta = action === 'increase' ? 1 : -1;
                this.updateQuantity(itemId, delta);
            });
        });
        
        row.querySelector('.remove-btn').addEventListener('click', (e) => {
            e.preventDefault();
            const itemId = parseInt(e.target.getAttribute('data-item-id'));
            this.removeItem(itemId);
        });
        
        return row;
    }
    
    async quickOrder(productId) {
        try {
            const product = this.findProduct(productId);
            if (!product) {
                throw new Error('Product not found');
            }
            
            const quantityStr = prompt(`Quantity (${product.unit || 'kg'}) for ${product.name}:`, '10');
            
            if (quantityStr === null) {
                return; // User cancelled
            }
            
            const quantity = parseFloat(quantityStr);
            if (isNaN(quantity) || quantity <= 0) {
                this.showNotification('Please enter a valid quantity', 'error');
                return;
            }
            
            const message = this.formatWhatsAppMessage([{
                ...product,
                qty: quantity
            }]);
            
            this.openWhatsApp(message);
            this.trackEvent('quick_order', { product_id: productId, quantity });
            
        } catch (error) {
            console.error('Quick order failed:', error);
            this.showNotification('Failed to create order', 'error');
        }
    }
    
    async checkout() {
        try {
            if (this.cart.length === 0) {
                this.showNotification('Cart is empty', 'warning');
                return;
            }
            
            // Log order to backend if online
            if (this.isOnline) {
                try {
                    await this.apiRequest('POST', 'order', { cart: this.cart });
                } catch (error) {
                    console.warn('Failed to log order to backend:', error);
                    // Continue with WhatsApp anyway
                }
            }
            
            const message = this.formatWhatsAppMessage(this.cart);
            this.openWhatsApp(message);
            this.trackEvent('checkout', { cart_value: this.getCartTotal(), items: this.cart.length });
            
        } catch (error) {
            console.error('Checkout failed:', error);
            this.showNotification('Checkout failed. Please try again.', 'error');
        }
    }
    
    formatWhatsAppMessage(items) {
        const lines = ['Hello, I would like to place an order from M. Pimpale Traders:', ''];
        
        let total = 0;
        items.forEach(item => {
            const itemTotal = item.qty * item.price;
            total += itemTotal;
            lines.push(`${item.qty} ${item.unit} × ${item.name} @ ₹${item.price}/${item.unit} = ₹${itemTotal.toLocaleString('en-IN')}`);
        });
        
        lines.push('');
        lines.push(`Total: ₹${total.toLocaleString('en-IN')}`);
        lines.push('');
        lines.push('Pickup/Delivery: (please mention your preference)');
        lines.push('Payment: (Cash/Online - please mention)');
        
        return lines.join('\n');
    }
    
    openWhatsApp(message) {
        const phoneNumber = '919112295256'; // From config
        const encodedMessage = encodeURIComponent(message);
        const whatsappUrl = `https://wa.me/${phoneNumber}?text=${encodedMessage}`;
        
        // Open in new window/tab
        window.open(whatsappUrl, '_blank', 'noopener,noreferrer');
    }
    
    // Cart drawer controls
    openCart() {
        const drawer = document.getElementById('cartDrawer');
        if (drawer) {
            drawer.classList.add('open');
            drawer.setAttribute('aria-hidden', 'false');
            this.trackEvent('cart_opened');
        }
    }
    
    closeCart() {
        const drawer = document.getElementById('cartDrawer');
        if (drawer) {
            drawer.classList.remove('open');
            drawer.setAttribute('aria-hidden', 'true');
        }
    }
    
    handleClearCart() {
        if (this.cart.length === 0) {
            this.showNotification('Cart is already empty', 'info');
            return;
        }
        
        if (confirm('Are you sure you want to clear your cart?')) {
            this.clearCart();
            this.showNotification('Cart cleared');
        }
    }
    
    // Search functionality
    setupSearch() {
        const searchInput = document.getElementById('productSearch');
        if (!searchInput) return;
        
        let searchTimeout;
        searchInput.addEventListener('input', (e) => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                this.filterProducts(e.target.value.trim());
            }, 300); // Debounce search
        });
    }
    
    filterProducts(query) {
        const grid = document.getElementById('productGrid');
        if (!grid) return;
        
        const cards = grid.querySelectorAll('.card');
        
        if (!query) {
            // Show all products
            cards.forEach(card => card.style.display = 'flex');
            return;
        }
        
        const searchTerm = query.toLowerCase();
        let visibleCount = 0;
        
        cards.forEach(card => {
            const productName = card.querySelector('[style*="font-weight:700"]')?.textContent.toLowerCase() || '';
            const productDesc = card.querySelector('.desc')?.textContent.toLowerCase() || '';
            
            if (productName.includes(searchTerm) || productDesc.includes(searchTerm)) {
                card.style.display = 'flex';
                visibleCount++;
            } else {
                card.style.display = 'none';
            }
        });
        
        this.trackEvent('search', { query, results: visibleCount });
    }
    
    // Data persistence
    saveCart() {
        try {
            const cartData = {
                items: this.cart,
                timestamp: Date.now()
            };
            localStorage.setItem('pimpale_cart', JSON.stringify(cartData));
        } catch (error) {
            console.warn('Failed to save cart:', error);
        }
    }
    
    loadCart() {
        try {
            const saved = localStorage.getItem('pimpale_cart');
            if (saved) {
                const cartData = JSON.parse(saved);
                
                // Check if cart is not too old (7 days)
                if (Date.now() - cartData.timestamp < 7 * 24 * 60 * 60 * 1000) {
                    this.cart = cartData.items || [];
                }
            }
        } catch (error) {
            console.warn('Failed to load cart:', error);
            this.cart = [];
        }
    }
    
    saveToStorage(key, data) {
        try {
            localStorage.setItem(key, JSON.stringify({
                data,
                timestamp: Date.now()
            }));
        } catch (error) {
            console.warn(`Failed to save ${key}:`, error);
        }
    }
    
    getFromStorage(key) {
        try {
            const saved = localStorage.getItem(key);
            if (saved) {
                const parsed = JSON.parse(saved);
                return parsed.data;
            }
        } catch (error) {
            console.warn(`Failed to load ${key}:`, error);
        }
        return null;
    }
    
    // Helper methods
    findProduct(productId) {
        return this.products.find(p => p.id == productId);
    }
    
    getCartCount() {
        return this.cart.reduce((sum, item) => sum + item.qty, 0);
    }
    
    getCartTotal() {
        return this.cart.reduce((sum, item) => sum + (item.price * item.qty), 0);
    }
    
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Notifications
    showNotification(message, type = 'success') {
        // Remove existing notifications
        const existing = document.querySelector('.toast-notification');
        if (existing) existing.remove();
        
        const toast = document.createElement('div');
        toast.className = `toast-notification toast-${type}`;
        toast.textContent = message;
        
        const styles = {
            position: 'fixed',
            bottom: '20px',
            left: '50%',
            transform: 'translateX(-50%)',
            padding: '12px 24px',
            borderRadius: '8px',
            color: 'white',
            fontSize: '14px',
            fontWeight: '500',
            zIndex: '10000',
            transition: 'opacity 0.3s ease',
            maxWidth: '90vw',
            textAlign: 'center'
        };
        
        const colors = {
            success: '#10b981',
            error: '#ef4444',
            warning: '#f59e0b',
            info: '#3b82f6'
        };
        
        Object.assign(toast.style, styles);
        toast.style.backgroundColor = colors[type] || colors.success;
        
        document.body.appendChild(toast);
        
        // Auto remove
        setTimeout(() => {
            toast.style.opacity = '0';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }
    
    // Analytics and tracking
    trackEvent(eventName, data = {}) {
        try {
            // Simple analytics - extend as needed
            const event = {
                name: eventName,
                data: data,
                timestamp: Date.now(),
                url: window.location.href
            };
            
            console.log('Analytics:', event);
            
            // Could send to analytics service here
            // this.sendToAnalytics(event);
            
        } catch (error) {
            console.warn('Analytics tracking failed:', error);
        }
    }
    
    // Periodic sync for online updates
    setupPeriodicSync() {
        setInterval(async () => {
            if (this.isOnline) {
                try {
                    await this.loadProducts();
                } catch (error) {
                    console.warn('Periodic sync failed:', error);
                }
            }
        }, 5 * 60 * 1000); // Every 5 minutes
    }
    
    // Fallback product data
    getFallbackProducts() {
        return [
            {id: 1, name: 'Basmati Rice', price: 90, description: 'Aromatic long-grain basmati rice, perfect for biryani and pulao.', image_url: 'https://imgs.search.brave.com/7xJYMkDUPA34f-Ff3nxLOAFyzsFx8a1zDN1MFOtGK3I/rs:fit:860:0:0:0/g:ce/aHR0cHM6Ly9zdC5k/ZXBvc2l0cGhvdG9z/LmNvbS8yMjUyNTQx/LzIzOTQvaS80NTAv/ZGVwb3NpdHBob3Rv/c18yMzk0ODM0Ny1z/dG9jay1waG90by1i/YXNtYXRpLXJpY2Uu/anBn', unit: 'kg'},
            {id: 2, name: 'Khusboo Biryani Rice', price: 80, description: 'Fragrant biryani blend that absorbs flavors beautifully.', image_url: 'https://imgs.search.brave.com/qKwAlskpaL5g6yIb3YVrzdOmEryjAFaXcy_SA_RcjUQ/rs:fit:500:0:1:0/g:ce/aHR0cHM6Ly80Lmlt/aW1nLmNvbS9kYXRh/NC9FVS9BVi9NWS0y/ODM0ODUwNi9raHVz/aGJvby1yaWNlLTI1/MHgyNTAuanBn', unit: 'kg'},
            {id: 3, name: 'Indrayani Rice', price: 70, description: 'Soft and fluffy — great for everyday meals.', image_url: 'https://imgs.search.brave.com/pIU8I-wzPa6wy9HGTgHpECTgS0_P5or-2HUAIFXkM2k/rs:fit:500:0:1:0/g:ce/aHR0cHM6Ly93d3cu/YXJhbnlhcHVyZWZv/b2QuY29tL2Nkbi9z/aG9wL2ZpbGVzL0lN/Ry0yMDI0MDIxNC1X/QTAwMDUuanBnP3Y9/MTcwNzg4ODk5MiZ3/aWR0aD0xNDQ1', unit: 'kg'},
            {id: 4, name: 'Daptari Rice', price: 80, description: 'Reliable quality rice, widely used in households.', image_url: 'https://imgs.search.brave.com/ZInrSTBmfeEZztRAXEu7YVP8CGKD9DTerZ1943rKot0/rs:fit:500:0:1:0/g:ce/aHR0cHM6Ly81Lmlt/aW1nLmNvbS9kYXRh/NS9OQS9UTi9BVi9T/RUxMRVItNDE0Mjcx/NjEvb3J5emEtZGFm/dGFyaS1yaWNlLTUw/MHg1MDAuanBn', unit: 'kg'},
            {id: 5, name: 'Chimansaal Rice', price: 80, description: 'Traditional variety known for its texture.', image_url: 'https://imgs.search.brave.com/y8ZpGdyU45_ERoayAQjiObpG0d20A71EUjZUyC8ZHW8/rs:fit:500:0:1:0/g:ce/aHR0cHM6Ly9vb29m/YXJtcy5jb20vY2Ru/L3Nob3AvcHJvZHVj/dHMvT09PX0Zhcm1z/X0NoaW1hbnNhYWxf/UmljZV9TZW1pcG9s/aXNoZWQuanBnP3Y9/MTczODY1MDQ5NCZ3/aWR0aD0xMTAw', unit: 'kg'},
            {id: 6, name: 'Udid dal Papad', price: 400, unit: 'packet', description: 'Crispy urad dal papad — perfect accompaniment to meals.', image_url: 'images/udiddal.jpg'},
            {id: 7, name: 'Kurdai Papad', price: 230, unit: 'packet', description: 'Light, crunchy papad made from moong dal.', image_url: 'images/kurdai.jpg'},
            {id: 8, name: 'Nachani che Papad', price: 80, unit: 'packet', description: 'Spiced papad with a punch of flavors.', image_url: 'images/nachani.jpg'}
        ];
    }
}

// Utility functions for backwards compatibility
function scrollToSection(id) {
    const element = document.getElementById(id);
    if (element) {
        element.scrollIntoView({ behavior: 'smooth' });
    }
}

// Initialize the store when DOM is ready
let store;

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        store = new PimpaleStore();
    });
} else {
    store = new PimpaleStore();
}

// Export functions for global access (backwards compatibility)
window.addToCart = (id, qty) => store?.addToCart(id, qty);
window.updateQuantity = (id, delta) => store?.updateQuantity(id, delta);
window.removeItem = (id) => store?.removeItem(id);
window.clearCart = () => store?.clearCart();
window.quickOrder = (id) => store?.quickOrder(id);
window.checkout = () => store?.checkout();
window.openCart = () => store?.openCart();
window.closeCart = () => store?.closeCart();
window.scrollToSection = scrollToSection;