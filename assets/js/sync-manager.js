/**
 * Sync Manager - Updated with Products & Categories Support
 * Aggressive cache-busting on every request
 */

class SyncManager {
    constructor() {
        this.isOnline = navigator.onLine;
        this.isSyncing = false;
        this.apiBase = BASE_URL + 'api/';
        
        window.addEventListener('online', () => this.handleOnline());
        window.addEventListener('offline', () => this.handleOffline());
    }

    /**
     * Generate cache-busting URL
     */
    bustCache(url) {
        const separator = url.includes('?') ? '&' : '?';
        return `${url}${separator}_=${Date.now()}`;
    }

    /**
     * Format error message with validation details
     */
    formatError(result, defaultMsg = 'Operation failed') {
        let errorMsg = result.error || defaultMsg;
        if (result.details && typeof result.details === 'object') {
            const detailMessages = Object.values(result.details);
            if (detailMessages.length > 0) {
                errorMsg = detailMessages.join(', ');
            }
        }
        return errorMsg;
    }

    /**
     * Initialize
     */
    async init() {
        console.log('ðŸš€ Initializing Sync Manager...');
        
        await offlineDB.init();
        this.updateStatusUI();
        
        if (this.isOnline) {
            console.log('ðŸŸ¢ Online - will fetch from server');
        } else {
            console.log('ðŸ”´ Offline - will use local data');
        }
        
        console.log('âœ“ Sync Manager ready');
    }

    /**
     * Handle going online
     */
    async handleOnline() {
        console.log('ðŸŸ¢ Connection restored');
        this.isOnline = true;
        this.updateStatusUI();
        this.toast('Back online - syncing...', 'success');
        await this.sync();
    }

    /**
     * Handle going offline
     */
    handleOffline() {
        console.log('ðŸ”´ Connection lost');
        this.isOnline = false;
        this.updateStatusUI();
        this.toast('Working offline', 'warning');
    }

    /**
     * Update status indicator
     */
    updateStatusUI() {
        const status = document.getElementById('onlineStatus');
        if (status) {
            if (this.isOnline) {
                status.className = 'badge bg-success';
                status.innerHTML = '<i class="bi bi-wifi"></i> Online';
            } else {
                status.className = 'badge bg-warning';
                status.innerHTML = '<i class="bi bi-wifi-off"></i> Offline';
            }
        }
    }

    /**
     * Main sync function
     */
    async sync() {
        if (this.isSyncing || !this.isOnline) {
            console.log('â­ï¸ Skipping sync');
            return false;
        }
        
        this.isSyncing = true;
        console.log('ðŸ”„ === SYNC START ===');
        
        try {
            console.log('ðŸ“¤ Step 1: Processing sync queue...');
            await this.processSyncQueue();
            
            console.log('ðŸ"¥ Step 2: Fetching fresh data from server...');
            await this.fetchRolesFromServer();
            await this.fetchCategoriesFromServer();
            await this.fetchProductsFromServer();
            await this.fetchLocationsFromServer();
            await this.fetchCostCentresFromServer();
            await this.fetchAccountsFromServer();
            await this.fetchCurrenciesFromServer();
            await this.fetchExchangeRatesFromServer();
            await this.fetchPurchasesFromServer();
            await this.fetchSalesFromServer();
            await this.fetchTransfersFromServer();
            await this.fetchConsumptionVouchersFromServer();

            console.log('âœ… === SYNC COMPLETE ===');
            return true;
        } catch (error) {
            console.error('âŒ === SYNC FAILED ===', error);
            return false;
        } finally {
            this.isSyncing = false;
        }
    }

    /**
     * Process sync queue
     */
    async processSyncQueue() {
        const queue = await offlineDB.getQueue();
        console.log(`ðŸ“‹ Found ${queue.length} items in queue`);
        
        if (queue.length === 0) return;
        
        for (const item of queue) {
            console.log(`âš™ï¸ Processing: ${item.module} ${item.action}`, item.data);
            
            try {
                await this.processQueueItem(item);
                await offlineDB.removeFromQueue(item.id);
                console.log(`âœ… Completed: ${item.module} ${item.action}`);
            } catch (error) {
                console.error(`âŒ Failed: ${item.module} ${item.action}`, error.message);
            }
        }
    }

    /**
     * Process single queue item
     */
    async processQueueItem(item) {
        const { module, action, data, localId } = item;
        
        const url = this.bustCache(this.apiBase + `${module}-sync.php`);
        
        const response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            cache: 'no-store',
            body: JSON.stringify({ action, data })
        });
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        
        const result = await response.json();
        console.log(`ðŸ“¨ Server response:`, result);
        
        if (!result.success) {
            throw new Error(result.error || 'Operation failed');
        }
        
        // If this was a CREATE with temp ID, update the local record
        if (action === 'create' && localId && result.serverId) {
            console.log(`ðŸ”„ Replacing temp ID ${localId} with server ID ${result.serverId}`);
            
            // Delete temp record
            await offlineDB.delete(module, localId);
            
            // Save with real ID (will be replaced by full sync anyway)
            if (result[module.slice(0, -1)]) {
                await offlineDB.save(module, result[module.slice(0, -1)]);
            }
        }
    }

    // ==================== ROLES ====================

    /**
     * Fetch roles from server
     */
    async fetchRolesFromServer() {
        console.log('ðŸŒ Fetching roles from server...');
        
        const url = this.bustCache(this.apiBase + 'roles.php');
        
        const response = await fetch(url, {
            method: 'GET',
            headers: {
                'Cache-Control': 'no-cache, no-store, must-revalidate',
                'Pragma': 'no-cache',
                'Expires': '0'
            },
            cache: 'no-store'
        });
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.error || 'Failed to fetch');
        }
        
        console.log(`ðŸ’¾ Replacing IndexedDB with ${result.roles.length} roles`);
        await offlineDB.replaceAll('roles', result.roles);
    }

    /**
     * Get all roles
     */
    async getRoles() {
        console.log('ðŸ“– getRoles() called');
        
        if (this.isOnline) {
            console.log('ðŸŸ¢ Online - fetching from server');
            try {
                await this.fetchRolesFromServer();
            } catch (error) {
                console.error('âŒ Server fetch failed:', error.message);
                console.log('ðŸ“¦ Using IndexedDB');
            }
        } else {
            console.log('ðŸ”´ Offline - using IndexedDB');
        }
        
        const roles = await offlineDB.getAll('roles');
        console.log(`ðŸ“Š Returning ${roles.length} roles`);
        return roles;
    }

    /**
     * Create role
     */
    async createRole(data) {
        console.log('âž• CREATE ROLE:', data);
        
        if (this.isOnline) {
            console.log('ðŸŸ¢ Online - saving to server');
            
            try {
                const url = this.bustCache(this.apiBase + 'roles-sync.php');
                
                const response = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    cache: 'no-store',
                    body: JSON.stringify({ action: 'create', data })
                });
                
                const result = await response.json();
                
                if (!result.success) {
                    throw new Error(result.error || 'Failed to create');
                }
                
                await offlineDB.save('roles', result.role);
                
                this.toast('Role created', 'success');
                return result.role;
                
            } catch (error) {
                console.error('âŒ CREATE failed:', error);
                this.toast(error.message, 'danger');
                throw error;
            }
        } else {
            console.log('ðŸ”´ Offline - saving locally');
            
            const tempId = 'temp_' + Date.now();
            const role = {
                id: tempId,
                ...data,
                user_count: 0,
                created_at: new Date().toISOString(),
                updated_at: new Date().toISOString()
            };
            
            await offlineDB.save('roles', role);
            await offlineDB.addToQueue({
                module: 'roles',
                action: 'create',
                data: data,
                localId: tempId
            });
            
            this.toast('Role saved offline', 'warning');
            return role;
        }
    }

    /**
     * Update role
     */
    async updateRole(data) {
        console.log('âœï¸ UPDATE ROLE:', data);
        
        if (this.isOnline) {
            try {
                const url = this.bustCache(this.apiBase + 'roles-sync.php');
                
                const response = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    cache: 'no-store',
                    body: JSON.stringify({ action: 'update', data })
                });
                
                const result = await response.json();
                
                if (!result.success) {
                    throw new Error(result.error || 'Failed to update');
                }
                
                await offlineDB.save('roles', result.role);
                
                this.toast('Role updated', 'success');
                return result.role;
                
            } catch (error) {
                console.error('âŒ UPDATE failed:', error);
                this.toast(error.message, 'danger');
                throw error;
            }
        } else {
            data.updated_at = new Date().toISOString();
            await offlineDB.save('roles', data);
            await offlineDB.addToQueue({
                module: 'roles',
                action: 'update',
                data: data
            });
            
            this.toast('Role updated offline', 'warning');
            return data;
        }
    }

    /**
     * Delete role
     */
    async deleteRole(id) {
        console.log('ðŸ—‘ï¸ DELETE ROLE:', id);
        
        await offlineDB.delete('roles', id);
        
        if (this.isOnline) {
            try {
                const url = this.bustCache(this.apiBase + 'roles-sync.php');
                
                const response = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    cache: 'no-store',
                    body: JSON.stringify({ action: 'delete', data: { id } })
                });
                
                const result = await response.json();
                
                if (!result.success) {
                    throw new Error(result.error || 'Failed to delete');
                }
                
                this.toast('Role deleted', 'success');
                
            } catch (error) {
                console.error('âŒ DELETE failed:', error);
                this.toast(error.message, 'danger');
                throw error;
            }
        } else {
            await offlineDB.addToQueue({
                module: 'roles',
                action: 'delete',
                data: { id }
            });
            
            this.toast('Role deleted offline', 'warning');
        }
    }

    // ==================== CATEGORIES ====================

    /**
     * Fetch categories from server (for caching only)
     */
    async fetchCategoriesFromServer() {
        console.log('ðŸŒ Fetching categories from server...');
        
        const url = this.bustCache(this.apiBase + 'categories.php');
        
        const response = await fetch(url, {
            method: 'GET',
            headers: {
                'Cache-Control': 'no-cache, no-store, must-revalidate',
                'Pragma': 'no-cache',
                'Expires': '0'
            },
            cache: 'no-store'
        });
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.error || 'Failed to fetch');
        }
        
        console.log(`ðŸ’¾ Caching ${result.categories.length} categories`);
        await offlineDB.replaceAll('categories', result.categories);
    }

    /**
     * Get all categories (read-only, for dropdowns)
     */
    async getCategories() {
        console.log('ðŸ“– getCategories() called');
        
        if (this.isOnline) {
            try {
                await this.fetchCategoriesFromServer();
            } catch (error) {
                console.error('âŒ Server fetch failed:', error.message);
            }
        }
        
        const categories = await offlineDB.getAll('categories');
        console.log(`ðŸ“Š Returning ${categories.length} categories`);
        return categories.filter(c => c.is_active == 1); // Only active
    }

    // ==================== PRODUCTS ====================

    /**
     * Fetch products from server
     */
    async fetchProductsFromServer() {
        console.log('ðŸŒ Fetching products from server...');
        
        const url = this.bustCache(this.apiBase + 'products.php');
        
        const response = await fetch(url, {
            method: 'GET',
            headers: {
                'Cache-Control': 'no-cache, no-store, must-revalidate',
                'Pragma': 'no-cache',
                'Expires': '0'
            },
            cache: 'no-store'
        });
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.error || 'Failed to fetch');
        }
        
        console.log(`ðŸ’¾ Replacing IndexedDB with ${result.products.length} products`);
        await offlineDB.replaceAll('products', result.products);
    }

    /**
     * Get all products
     */
    async getProducts() {
        console.log('ðŸ“– getProducts() called');
        
        if (this.isOnline) {
            console.log('ðŸŸ¢ Online - fetching from server');
            try {
                await this.fetchProductsFromServer();
            } catch (error) {
                console.error('âŒ Server fetch failed:', error.message);
                console.log('ðŸ“¦ Using IndexedDB');
            }
        } else {
            console.log('ðŸ”´ Offline - using IndexedDB');
        }
        
        const products = await offlineDB.getAll('products');
        console.log(`ðŸ“Š Returning ${products.length} products`);
        return products;
    }

    /**
     * Create product
     */
    async createProduct(data) {
        console.log('âž• CREATE PRODUCT:', data);
        
        if (this.isOnline) {
            console.log('ðŸŸ¢ Online - saving to server');
            
            try {
                const url = this.bustCache(this.apiBase + 'products-sync.php');
                
                const response = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    cache: 'no-store',
                    body: JSON.stringify({ action: 'create', data })
                });
                
                const result = await response.json();
                
                if (!result.success) {
                    throw new Error(result.error || 'Failed to create');
                }
                
                await offlineDB.save('products', result.product);
                
                this.toast('Product created', 'success');
                return result.product;
                
            } catch (error) {
                console.error('âŒ CREATE failed:', error);
                this.toast(error.message, 'danger');
                throw error;
            }
        } else {
            console.log('ðŸ”´ Offline - saving locally');
            
            const tempId = 'temp_' + Date.now();
            
            // Get category name for display
            const categories = await offlineDB.getAll('categories');
            const category = categories.find(c => c.id == data.category_id);
            
            const product = {
                id: tempId,
                ...data,
                category_name: category ? category.name : 'Unknown',
                total_stock: 0,
                purchase_count: 0,
                sale_count: 0,
                created_at: new Date().toISOString(),
                updated_at: new Date().toISOString()
            };
            
            await offlineDB.save('products', product);
            await offlineDB.addToQueue({
                module: 'products',
                action: 'create',
                data: data,
                localId: tempId
            });
            
            this.toast('Product saved offline', 'warning');
            return product;
        }
    }

    /**
     * Update product
     */
    async updateProduct(data) {
        console.log('âœï¸ UPDATE PRODUCT:', data);
        
        if (this.isOnline) {
            try {
                const url = this.bustCache(this.apiBase + 'products-sync.php');
                
                const response = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    cache: 'no-store',
                    body: JSON.stringify({ action: 'update', data })
                });
                
                const result = await response.json();
                
                if (!result.success) {
                    throw new Error(result.error || 'Failed to update');
                }
                
                await offlineDB.save('products', result.product);
                
                this.toast('Product updated', 'success');
                return result.product;
                
            } catch (error) {
                console.error('âŒ UPDATE failed:', error);
                this.toast(error.message, 'danger');
                throw error;
            }
        } else {
            // Get category name for display
            const categories = await offlineDB.getAll('categories');
            const category = categories.find(c => c.id == data.category_id);
            
            if (category) {
                data.category_name = category.name;
            }
            
            data.updated_at = new Date().toISOString();
            await offlineDB.save('products', data);
            await offlineDB.addToQueue({
                module: 'products',
                action: 'update',
                data: data
            });
            
            this.toast('Product updated offline', 'warning');
            return data;
        }
    }

    /**
     * Delete product (ONLINE ONLY)
     */
    async deleteProduct(id) {
        console.log('ðŸ—‘ï¸ DELETE PRODUCT:', id);
        
        if (!this.isOnline) {
            throw new Error('Delete is only available when online');
        }
        
        try {
            const url = this.bustCache(this.apiBase + 'products-sync.php');
            
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                cache: 'no-store',
                body: JSON.stringify({ action: 'delete', data: { id } })
            });
            
            const result = await response.json();
            
            if (!result.success) {
                throw new Error(result.error || 'Failed to delete');
            }
            
            // Remove from IndexedDB
            await offlineDB.delete('products', id);
            
            this.toast('Product deleted', 'success');
            
        } catch (error) {
            console.error('âŒ DELETE failed:', error);
            this.toast(error.message, 'danger');
            throw error;
        }
    }


    // ==================== CURRENCIES ====================

    /**
     * Fetch currencies from server (read-only cache)
     */
    async fetchCurrenciesFromServer() {
        console.log('Fetching currencies from server...');

        const url = this.bustCache(this.apiBase + 'currencies.php');

        const response = await fetch(url, {
            method: 'GET',
            headers: {
                'Cache-Control': 'no-cache, no-store, must-revalidate',
                'Pragma': 'no-cache',
                'Expires': '0'
            },
            cache: 'no-store'
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const result = await response.json();

        if (!result.success) {
            throw new Error(result.error || 'Failed to fetch');
        }

        console.log(`Caching ${result.currencies.length} currencies`);
        await offlineDB.replaceAll('currencies', result.currencies);
    }

    /**
     * Get all currencies (read-only, for dropdowns)
     */
    async getCurrencies() {
        console.log('getCurrencies() called');

        if (this.isOnline) {
            try {
                await this.fetchCurrenciesFromServer();
            } catch (error) {
                console.error('Server fetch failed:', error.message);
            }
        }

        const currencies = await offlineDB.getAll('currencies');
        console.log(`Returning ${currencies.length} currencies`);
        return currencies.filter(c => c.is_active == 1);
    }

    // ==================== EXCHANGE RATES ====================

    /**
     * Fetch exchange rates from server (read-only cache)
     */
    async fetchExchangeRatesFromServer() {
        console.log('Fetching exchange rates from server...');

        const url = this.bustCache(this.apiBase + 'exchange-rates.php');

        const response = await fetch(url, {
            method: 'GET',
            headers: {
                'Cache-Control': 'no-cache, no-store, must-revalidate',
                'Pragma': 'no-cache',
                'Expires': '0'
            },
            cache: 'no-store'
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const result = await response.json();

        if (!result.success) {
            throw new Error(result.error || 'Failed to fetch');
        }

        console.log(`Caching ${result.exchange_rates.length} exchange rates`);
        await offlineDB.replaceAll('exchange_rates', result.exchange_rates);
    }

    /**
     * Get all exchange rates (read-only cache)
     */
    async getExchangeRates() {
        console.log('getExchangeRates() called');

        if (this.isOnline) {
            try {
                await this.fetchExchangeRatesFromServer();
            } catch (error) {
                console.error('Server fetch failed:', error.message);
            }
        }

        const rates = await offlineDB.getAll('exchange_rates');
        console.log(`Returning ${rates.length} exchange rates`);
        return rates;
    }

    /**
     * Get exchange rate for a specific currency pair and date (from local cache)
     * Returns the latest rate on or before the given date
     */
    async getExchangeRate(fromCurrencyId, toCurrencyId, date) {
        if (fromCurrencyId == toCurrencyId) return 1;

        const rates = await offlineDB.getAll('exchange_rates');

        // Filter matching pair and effective_date <= date, sort by date DESC
        const matching = rates
            .filter(r => r.from_currency_id == fromCurrencyId && r.to_currency_id == toCurrencyId && r.effective_date <= date)
            .sort((a, b) => b.effective_date.localeCompare(a.effective_date));

        if (matching.length > 0) {
            return parseFloat(matching[0].rate);
        }

        // Try reverse rate
        const reverse = rates
            .filter(r => r.from_currency_id == toCurrencyId && r.to_currency_id == fromCurrencyId && r.effective_date <= date)
            .sort((a, b) => b.effective_date.localeCompare(a.effective_date));

        if (reverse.length > 0) {
            return 1 / parseFloat(reverse[0].rate);
        }

        return null; // No rate found
    }

    // ==================== PURCHASES ====================

    /**
     * Fetch purchases from server
     */
    async fetchPurchasesFromServer(dateFrom = null, dateTo = null) {
        console.log('🌐 Fetching purchases from server...', { dateFrom, dateTo });

        const params = new URLSearchParams();
        if (dateFrom) params.set('date_from', dateFrom);
        if (dateTo) params.set('date_to', dateTo);
        const qs = params.toString();

        const url = this.bustCache(this.apiBase + 'purchases.php' + (qs ? '?' + qs : ''));

        const response = await fetch(url, {
            method: 'GET',
            headers: {
                'Cache-Control': 'no-cache, no-store, must-revalidate',
                'Pragma': 'no-cache',
                'Expires': '0'
            },
            cache: 'no-store'
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const result = await response.json();

        if (!result.success) {
            throw new Error(result.error || 'Failed to fetch');
        }

        console.log(`💾 Upserting ${result.purchases.length} purchases into IndexedDB`);
        await offlineDB.upsertAll('purchases', result.purchases);
    }

    /**
     * Fetch locations from server (for dropdown cache)
     */
    async fetchLocationsFromServer() {
        console.log('🌐 Fetching locations from server...');
        
        const url = this.bustCache(this.apiBase + 'locations.php');
        
        const response = await fetch(url, {
            method: 'GET',
            headers: {
                'Cache-Control': 'no-cache, no-store, must-revalidate',
                'Pragma': 'no-cache',
                'Expires': '0'
            },
            cache: 'no-store'
        });
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.error || 'Failed to fetch');
        }
        
        console.log(`💾 Caching ${result.locations.length} locations`);
        await offlineDB.replaceAll('locations', result.locations);
    }

    /**
     * Fetch cost centres from server and cache locally
     */
    async fetchCostCentresFromServer() {
        console.log('🌐 Fetching cost centres from server...');

        const url = this.bustCache(this.apiBase + 'cost_centres.php');

        const response = await fetch(url, {
            method: 'GET',
            headers: {
                'Cache-Control': 'no-cache, no-store, must-revalidate',
                'Pragma': 'no-cache',
                'Expires': '0'
            },
            cache: 'no-store'
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const result = await response.json();

        if (!result.success) {
            throw new Error(result.error || 'Failed to fetch');
        }

        console.log(`💾 Caching ${result.cost_centres.length} cost centres`);
        await offlineDB.replaceAll('cost_centres', result.cost_centres);
    }

    /**
     * Get all purchases
     */
    async getPurchases(dateFrom = null, dateTo = null) {
        console.log('📖 getPurchases() called', { dateFrom, dateTo });

        if (this.isOnline) {
            console.log('🟢 Online - fetching from server');
            try {
                await this.fetchPurchasesFromServer(dateFrom, dateTo);
            } catch (error) {
                console.error('❌ Server fetch failed:', error.message);
                console.log('📦 Using IndexedDB');
            }
        } else {
            console.log('🔴 Offline - using IndexedDB');
        }

        let purchases = await offlineDB.getAll('purchases');

        if (dateFrom) {
            purchases = purchases.filter(p => p.purchase_date >= dateFrom);
        }
        if (dateTo) {
            purchases = purchases.filter(p => p.purchase_date <= dateTo);
        }

        console.log(`📊 Returning ${purchases.length} purchases`);
        return purchases;
    }

    /**
     * Get locations (for dropdowns)
     */
    async getLocations() {
        console.log('📖 getLocations() called');
        
        if (this.isOnline) {
            try {
                await this.fetchLocationsFromServer();
            } catch (error) {
                console.error('❌ Server fetch failed:', error.message);
            }
        }
        
        const locations = await offlineDB.getAll('locations');
        console.log(`📊 Returning ${locations.length} locations`);
        return locations.filter(l => l.is_active == 1); // Only active
    }

    /**
     * Get suppliers (for dropdowns) - already cached by suppliers.php
     */
    async getSuppliers() {
        console.log('📖 getSuppliers() called');
        const suppliers = await offlineDB.getAll('suppliers');
        console.log(`📊 Returning ${suppliers.length} suppliers`);
        return suppliers.filter(s => s.is_active == 1); // Only active
    }

    /**
     * Create purchase
     */
    async createPurchase(data) {
        console.log('➕ CREATE PURCHASE:', data);
        
        if (this.isOnline) {
            console.log('🟢 Online - saving to server');
            
            try {
                const url = this.bustCache(this.apiBase + 'purchases-sync.php');
                
                const response = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    cache: 'no-store',
                    body: JSON.stringify({ action: 'create', data })
                });
                
                const result = await response.json();

                if (!result.success) {
                    throw new Error(this.formatError(result, 'Failed to create purchase'));
                }

                await offlineDB.save('purchases', result.purchase);

                this.toast('Purchase created', 'success');
                return result.purchase;
                
            } catch (error) {
                console.error('❌ CREATE failed:', error);
                this.toast(error.message, 'danger');
                throw error;
            }
        } else {
            console.log('🔴 Offline - saving locally');
            
            const tempId = 'temp_' + Date.now();
            
            // Get related data for display
            const suppliers = await offlineDB.getAll('suppliers');
            const supplier = suppliers.find(s => s.id == data.supplier_id);
            
            const locations = await offlineDB.getAll('locations');
            const location = locations.find(l => l.id == data.location_id);

            const costCentres = await offlineDB.getAll('cost_centres');
            const costCentre = data.cost_centre_id ? costCentres.find(cc => cc.id == data.cost_centre_id) : null;

            // Enrich details with product names
            const products = await offlineDB.getAll('products');
            const enrichedDetails = data.details.map(detail => {
                const product = products.find(p => p.id == detail.product_id);
                return {
                    ...detail,
                    product_name: product ? product.name : 'Unknown',
                    unit: product ? product.unit : '',
                    category_name: product ? product.category_name : ''
                };
            });

            const purchase = {
                id: tempId,
                purchase_number: 'TEMP_PUR_' + Date.now(),
                purchase_date: data.purchase_date,
                supplier_id: data.supplier_id,
                supplier_name: supplier ? supplier.name : 'Unknown',
                location_id: data.location_id,
                location_name: location ? location.name : 'Unknown',
                cost_centre_id: data.cost_centre_id || null,
                cost_centre_name: costCentre ? costCentre.name : null,
                cost_centre_code: costCentre ? costCentre.code : null,
                total_amount: data.details.reduce((sum, item) =>
                    sum + (parseFloat(item.quantity) * parseFloat(item.rate_per_unit)), 0
                ),
                vat_amount: parseFloat(data.vat_amount || 0),
                notes: data.notes || '',
                details: enrichedDetails,
                created_by: data.created_by || null,
                created_by_name: '', // Will be set by server
                created_at: new Date().toISOString(),
                updated_at: new Date().toISOString(),
                synced: false // Mark as not synced
            };
            
            await offlineDB.save('purchases', purchase);
            await offlineDB.addToQueue({
                module: 'purchases',
                action: 'create',
                data: data,
                localId: tempId,
                tempId: tempId
            });
            
            this.toast('Purchase saved offline - will sync when online', 'warning');
            return purchase;
        }
    }

    /**
     * Update purchase (ONLINE ONLY)
     */
    async updatePurchase(data) {
        console.log('✏️ UPDATE PURCHASE:', data);
        
        if (!this.isOnline) {
            throw new Error('Editing purchases is only available when online');
        }
        
        try {
            const url = this.bustCache(this.apiBase + 'purchases-sync.php');
            
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                cache: 'no-store',
                body: JSON.stringify({ action: 'update', data })
            });
            
            const result = await response.json();

            if (!result.success) {
                throw new Error(this.formatError(result, 'Failed to update purchase'));
            }

            await offlineDB.save('purchases', result.purchase);

            this.toast('Purchase updated', 'success');
            return result.purchase;

        } catch (error) {
            console.error('❌ UPDATE failed:', error);
            this.toast(error.message, 'danger');
            throw error;
        }
    }

    /**
     * Delete purchase (ONLINE ONLY)
     */
    async deletePurchase(id) {
        console.log('🗑️ DELETE PURCHASE:', id);

        if (!this.isOnline) {
            throw new Error('Delete is only available when online');
        }

        try {
            const url = this.bustCache(this.apiBase + 'purchases-sync.php');

            const response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                cache: 'no-store',
                body: JSON.stringify({ action: 'delete', data: { id } })
            });

            const result = await response.json();

            if (!result.success) {
                throw new Error(this.formatError(result, 'Failed to delete purchase'));
            }
            
            // Remove from IndexedDB
            await offlineDB.delete('purchases', id);
            
            this.toast('Purchase deleted', 'success');
            
        } catch (error) {
            console.error('❌ DELETE failed:', error);
            this.toast(error.message, 'danger');
            throw error;
        }
    }


    // ==================== CUSTOMERS ====================

    /**
     * Create customer (online/offline)
     */
    async createCustomer(data) {
        console.log('➕ CREATE CUSTOMER:', data);
        
        if (this.isOnline) {
            console.log('🟢 Online - saving to server');
            
            try {
                const url = this.bustCache(this.apiBase + 'customers-sync.php');
                
                const response = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    cache: 'no-store',
                    body: JSON.stringify({ 
                        action: 'create', 
                        data: {
                            name: data.name,
                            contact_person: data.contact_person || '',
                            phone: data.phone || '',
                            email: data.email || '',
                            address: data.address || '',
                            gstin: data.gstin || '',
                            pan: data.pan || '',
                            opening_balance: data.opening_balance || 0,
                            is_active: 1
                        }
                    })
                });
                
                const result = await response.json();
                
                if (!result.success) {
                    throw new Error(result.error || 'Failed to create customer');
                }
                
                // Cache the customer
                const customer = {
                    id: result.serverId,
                    name: data.name,
                    contact_person: data.contact_person || '',
                    phone: data.phone || '',
                    email: data.email || '',
                    address: data.address || '',
                    is_active: 1,
                    synced: true
                };
                await offlineDB.save('customers', customer);
                
                this.toast('Customer created', 'success');
                return { 
                    success: true,
                    serverId: result.serverId,
                    customer: customer
                };
                
            } catch (error) {
                console.error('❌ CREATE CUSTOMER failed:', error);
                this.toast(error.message, 'danger');
                throw error;
            }
        } else {
            console.log('🔴 Offline - saving locally');
            
            const tempId = 'TEMP_CUST_' + Date.now();
            
            const customer = {
                id: tempId,
                name: data.name,
                contact_person: data.contact_person || '',
                phone: data.phone || '',
                email: data.email || '',
                address: data.address || '',
                is_active: 1,
                synced: false
            };
            
            await offlineDB.save('customers', customer);
            await offlineDB.addToQueue({
                module: 'customers',
                action: 'create',
                data: {
                    name: data.name,
                    contact_person: data.contact_person || '',
                    phone: data.phone || '',
                    email: data.email || '',
                    address: data.address || ''
                },
                localId: tempId,
                tempId: tempId
            });
            
            this.toast('Customer saved offline - will sync when online', 'warning');
            return { 
                success: true,
                tempId: tempId,
                customer: customer
            };
        }
    }

    /**
     * Get customers (from cache or server)
     */
    async getCustomers() {
        console.log('📖 getCustomers() called');
        
        // Try cache first
        let customers = await offlineDB.getAll('customers');
        
        // If empty and online, fetch from server
        if (customers.length === 0 && this.isOnline) {
            console.log('📡 No customers in cache, fetching from server...');
            try {
                const response = await fetch(BASE_URL + 'api/customers.php?_=' + Date.now(), {
                    method: 'GET',
                    headers: {
                        'Cache-Control': 'no-cache, no-store, must-revalidate',
                        'Pragma': 'no-cache'
                    },
                    cache: 'no-store'
                });
                
                if (response.ok) {
                    const result = await response.json();
                    if (result.success && result.customers) {
                        customers = result.customers;
                        // Cache them
                        await offlineDB.replaceAll('customers', customers);
                        console.log(`✅ Fetched and cached ${customers.length} customers from server`);
                    }
                }
            } catch (error) {
                console.error('❌ Failed to fetch customers from server:', error);
            }
        }
        
        // Filter active customers
        customers = customers.filter(c => c.is_active == 1);
        
        console.log(`📊 Returning ${customers.length} active customers`);
        return customers;
    }
    // ==================== ACCOUNTS (Cash/Bank) ====================

    /**
     * Fetch accounts from server
     */
    async fetchAccountsFromServer() {
        console.log('🌐 Fetching accounts from server...');

        const url = this.bustCache(this.apiBase + 'accounts.php');

        const response = await fetch(url, {
            method: 'GET',
            headers: {
                'Cache-Control': 'no-cache, no-store, must-revalidate',
                'Pragma': 'no-cache',
                'Expires': '0'
            },
            cache: 'no-store'
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const result = await response.json();

        if (!result.success) {
            throw new Error(result.error || 'Failed to fetch');
        }

        console.log(`💾 Caching ${result.accounts.length} accounts`);
        await offlineDB.replaceAll('accounts', result.accounts);
    }

    /**
     * Get all accounts (cash/bank)
     */
    async getAccounts() {
        console.log('📖 getAccounts() called');

        if (this.isOnline) {
            console.log('🟢 Online - fetching from server');
            try {
                await this.fetchAccountsFromServer();
            } catch (error) {
                console.error('❌ Server fetch failed:', error.message);
                console.log('📦 Using IndexedDB');
            }
        } else {
            console.log('🔴 Offline - using IndexedDB');
        }

        const accounts = await offlineDB.getAll('accounts');
        console.log(`📊 Returning ${accounts.length} accounts`);
        return accounts.filter(a => a.is_active == 1); // Only active
    }

    /**
     * Create account (cash/bank)
     */
    async createAccount(data) {
        console.log('➕ CREATE ACCOUNT:', data);

        if (this.isOnline) {
            console.log('🟢 Online - saving to server');

            try {
                const url = this.bustCache(this.apiBase + 'accounts-sync.php');

                const response = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    cache: 'no-store',
                    body: JSON.stringify({ action: 'create', data })
                });

                const result = await response.json();

                if (!result.success) {
                    throw new Error(result.error || 'Failed to create');
                }

                await offlineDB.save('accounts', result.account);

                this.toast('Account created', 'success');
                return {
                    success: true,
                    serverId: result.serverId,
                    account: result.account
                };

            } catch (error) {
                console.error('❌ CREATE failed:', error);
                this.toast(error.message, 'danger');
                throw error;
            }
        } else {
            console.log('🔴 Offline - saving locally');

            const tempId = 'TEMP_ACC_' + Date.now();

            const account = {
                id: tempId,
                name: data.name,
                account_type: data.account_type,
                current_balance: 0,
                is_active: 1,
                synced: false
            };

            await offlineDB.save('accounts', account);
            await offlineDB.addToQueue({
                module: 'accounts',
                action: 'create',
                data: data,
                localId: tempId,
                tempId: tempId
            });

            this.toast('Account saved offline - will sync when online', 'warning');
            return {
                success: true,
                tempId: tempId,
                account: account
            };
        }
    }

    // ==================== SALES ====================

    /**
     * Fetch sales from server
     */
    async fetchSalesFromServer() {
        console.log('🌐 Fetching sales from server...');
        
        const url = this.bustCache(this.apiBase + 'sales.php');
        
        const response = await fetch(url, {
            method: 'GET',
            headers: {
                'Cache-Control': 'no-cache, no-store, must-revalidate',
                'Pragma': 'no-cache',
                'Expires': '0'
            },
            cache: 'no-store'
        });
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        
        const sales = await response.json();
        
        // API returns array directly, not wrapped in {success, sales}
        if (Array.isArray(sales)) {
            console.log(`💾 Replacing IndexedDB with ${sales.length} sales`);
            await offlineDB.replaceAll('sales', sales);
        } else {
            throw new Error('Invalid response format from sales API');
        }
    }

    /**
     * Get all sales
     */
    async getSales() {
        console.log('📖 getSales() called');
        
        if (this.isOnline) {
            console.log('🟢 Online - fetching from server');
            try {
                await this.fetchSalesFromServer();
            } catch (error) {
                console.error('❌ Server fetch failed:', error.message);
                console.log('📦 Using IndexedDB');
            }
        } else {
            console.log('🔴 Offline - using IndexedDB');
        }
        
        const sales = await offlineDB.getAll('sales');
        console.log(`📊 Returning ${sales.length} sales`);
        return sales;
    }

    /**
     * Create sale (will be implemented later when sales.php is ready)
     */
    async createSale(data) {
        console.log('➕ CREATE SALE:', data);
        
        if (this.isOnline) {
            console.log('🟢 Online - saving to server');
            
            try {
                const url = this.bustCache(this.apiBase + 'sales-sync.php');
                
                const response = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    cache: 'no-store',
                    body: JSON.stringify({ action: 'create', data })
                });
                
                const result = await response.json();
                
                if (!result.success) {
                    throw new Error(result.error || 'Failed to create');
                }
                
                // Fetch the created sale from server
                await this.fetchSalesFromServer();
                
                this.toast('Sale created', 'success');
                return { 
                    success: true,
                    serverId: result.serverId,
                    serverNumber: result.serverNumber 
                };
                
            } catch (error) {
                console.error('❌ CREATE failed:', error);
                this.toast(error.message, 'danger');
                throw error;
            }
        } else {
            console.log('🔴 Offline - saving locally');
            
            const tempId = 'TEMP_SAL_' + Date.now();
            
            // Get related data for display
            const customers = await offlineDB.getAll('customers');
            const customer = customers.find(c => c.id == data.customer_id);
            
            const locations = await offlineDB.getAll('locations');
            const location = locations.find(l => l.id == data.location_id);

            const costCentres = await offlineDB.getAll('cost_centres');
            const costCentre = data.cost_centre_id ? costCentres.find(cc => cc.id == data.cost_centre_id) : null;

            // Enrich details with product names
            const products = await offlineDB.getAll('products');
            const enrichedDetails = data.details.map(detail => {
                const product = products.find(p => p.id == detail.product_id);
                return {
                    ...detail,
                    product_name: product ? product.name : 'Unknown',
                    unit: product ? product.unit : '',
                    category_name: product ? product.category_name : ''
                };
            });

            const sale = {
                id: tempId,
                sale_number: 'TEMP_SAL_' + Date.now(),
                sale_date: data.sale_date,
                customer_id: data.customer_id,
                customer_name: customer ? customer.name : 'Unknown',
                print_name: data.print_name || (customer ? customer.name : 'Cash'),
                location_id: data.location_id,
                location_name: location ? location.name : 'Unknown',
                cost_centre_id: data.cost_centre_id || null,
                cost_centre_name: costCentre ? costCentre.name : null,
                cost_centre_code: costCentre ? costCentre.code : null,
                total_amount: data.details.reduce((sum, item) =>
                    sum + (parseFloat(item.quantity) * parseFloat(item.rate_per_unit)), 0
                ),
                payment_mode: data.payment_mode || 'cash',
                to_account_id: data.to_account_id || null, // Cash/Bank account for receipt
                remarks: data.remarks || '',
                details: enrichedDetails,
                created_by: data.created_by || null,
                created_by_name: '', // Will be set by server
                created_at: new Date().toISOString(),
                updated_at: new Date().toISOString(),
                synced: false // Mark as not synced
            };
            
            await offlineDB.save('sales', sale);
            await offlineDB.addToQueue({
                module: 'sales',
                action: 'create',
                data: data,
                localId: tempId,
                tempId: tempId
            });
            
            this.toast('Sale saved offline - will sync when online', 'warning');
            return { 
                success: true,
                sale: sale 
            };
        }
    }

    /**
     * Update sale (ONLINE ONLY)
     */
    async updateSale(data) {
        console.log('✏️ UPDATE SALE:', data);
        
        if (!this.isOnline) {
            throw new Error('Editing sales is only available when online');
        }
        
        try {
            const url = this.bustCache(this.apiBase + 'sales-sync.php');
            
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                cache: 'no-store',
                body: JSON.stringify({ action: 'update', data })
            });
            
            const result = await response.json();
            
            if (!result.success) {
                throw new Error(result.error || 'Failed to update');
            }
            
            await offlineDB.save('sales', result.sale);
            
            this.toast('Sale updated', 'success');
            return result.sale;
            
        } catch (error) {
            console.error('❌ UPDATE failed:', error);
            this.toast(error.message, 'danger');
            throw error;
        }
    }

    /**
     * Delete sale (ONLINE ONLY)
     */
    async deleteSale(id) {
        console.log('🗑️ DELETE SALE:', id);
        
        if (!this.isOnline) {
            throw new Error('Delete is only available when online');
        }
        
        try {
            const url = this.bustCache(this.apiBase + 'sales-sync.php');
            
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                cache: 'no-store',
                body: JSON.stringify({ action: 'delete', data: { id } })
            });
            
            const result = await response.json();
            
            if (!result.success) {
                throw new Error(result.error || 'Failed to delete');
            }
            
            // Remove from IndexedDB
            await offlineDB.delete('sales', id);
            
            this.toast('Sale deleted', 'success');
            
        } catch (error) {
            console.error('❌ DELETE failed:', error);
            this.toast(error.message, 'danger');
            throw error;
        }
    }
    // ==================== STOCK TRANSFERS ====================

    /**
     * Fetch transfers from server
     */
    async fetchTransfersFromServer() {
        console.log('🌐 Fetching stock transfers from server...');

        const url = this.bustCache(this.apiBase + 'transfers.php');

        const response = await fetch(url, {
            method: 'GET',
            headers: {
                'Cache-Control': 'no-cache, no-store, must-revalidate',
                'Pragma': 'no-cache',
                'Expires': '0'
            },
            cache: 'no-store'
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const result = await response.json();

        if (!result.success) {
            throw new Error(result.error || 'Failed to fetch');
        }

        console.log(`💾 Replacing IndexedDB with ${result.transfers.length} transfers`);
        await offlineDB.replaceAll('stock_transfers', result.transfers);
    }

    /**
     * Get all transfers
     */
    async getTransfers() {
        console.log('📖 getTransfers() called');

        if (this.isOnline) {
            console.log('🟢 Online - fetching from server');
            try {
                await this.fetchTransfersFromServer();
            } catch (error) {
                console.error('❌ Server fetch failed:', error.message);
                console.log('📦 Using IndexedDB');
            }
        } else {
            console.log('🔴 Offline - using IndexedDB');
        }

        const transfers = await offlineDB.getAll('stock_transfers');
        console.log(`📊 Returning ${transfers.length} transfers`);
        return transfers;
    }

    /**
     * Create transfer (offline-capable)
     */
    async createTransfer(data) {
        console.log('➕ CREATE TRANSFER:', data);

        if (this.isOnline) {
            console.log('🟢 Online - saving to server');

            try {
                const url = this.bustCache(this.apiBase + 'transfers-sync.php');

                const response = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    cache: 'no-store',
                    body: JSON.stringify({ action: 'create', data })
                });

                const result = await response.json();

                if (!result.success) {
                    throw new Error(result.error || 'Failed to create');
                }

                // Refresh transfers from server
                await this.fetchTransfersFromServer();

                this.toast('Transfer created', 'success');
                return {
                    success: true,
                    serverId: result.serverId,
                    serverNumber: result.serverNumber
                };

            } catch (error) {
                console.error('❌ CREATE failed:', error);
                this.toast(error.message, 'danger');
                throw error;
            }
        } else {
            console.log('🔴 Offline - saving locally');

            const tempId = 'TEMP_TRF_' + Date.now();

            // Get related data for display
            const locations = await offlineDB.getAll('locations');
            const fromLocation = locations.find(l => l.id == data.from_location_id);
            const toLocation = locations.find(l => l.id == data.to_location_id);

            // Enrich details with product names
            const products = await offlineDB.getAll('products');
            const enrichedDetails = data.details.map(detail => {
                const product = products.find(p => p.id == detail.product_id);
                return {
                    ...detail,
                    product_name: product ? product.name : 'Unknown',
                    unit: product ? product.unit : ''
                };
            });

            const transfer = {
                id: tempId,
                transfer_number: 'TEMP_TRF_' + Date.now(),
                transfer_date: data.transfer_date,
                from_location_id: data.from_location_id,
                from_location_name: fromLocation ? fromLocation.name : 'Unknown',
                to_location_id: data.to_location_id,
                to_location_name: toLocation ? toLocation.name : 'Unknown',
                vehicle_number: data.vehicle_number || '',
                driver_name: data.driver_name || '',
                driver_phone: data.driver_phone || '',
                remarks: data.remarks || '',
                status: 'draft',
                total_quantity: data.details.reduce((sum, item) =>
                    sum + parseFloat(item.quantity), 0
                ),
                total_amount: data.details.reduce((sum, item) =>
                    sum + (parseFloat(item.quantity) * parseFloat(item.rate_per_unit)), 0
                ),
                details: enrichedDetails,
                created_at: new Date().toISOString(),
                updated_at: new Date().toISOString(),
                synced: false
            };

            await offlineDB.save('stock_transfers', transfer);
            await offlineDB.addToQueue({
                module: 'stock_transfers',
                action: 'create',
                data: data,
                localId: tempId,
                tempId: tempId
            });

            this.toast('Transfer saved offline - will sync when online', 'warning');
            return {
                success: true,
                transfer: transfer
            };
        }
    }

    /**
     * Dispatch transfer (ONLINE ONLY for now - involves stock changes)
     */
    async dispatchTransfer(transferId) {
        console.log('🚚 DISPATCH TRANSFER:', transferId);

        if (!this.isOnline) {
            throw new Error('Dispatch is only available when online');
        }

        try {
            const url = this.bustCache(BASE_URL + 'ajax/dispatch_transfer.php');

            const response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                cache: 'no-store',
                body: JSON.stringify({ transfer_id: transferId })
            });

            const result = await response.json();

            if (!result.success) {
                throw new Error(result.message || 'Failed to dispatch');
            }

            // Refresh transfers
            await this.fetchTransfersFromServer();

            this.toast('Transfer dispatched', 'success');
            return result;

        } catch (error) {
            console.error('❌ DISPATCH failed:', error);
            this.toast(error.message, 'danger');
            throw error;
        }
    }

    /**
     * Receive transfer (ONLINE ONLY - involves stock changes)
     */
    async receiveTransfer(transferId, items, remarks) {
        console.log('📦 RECEIVE TRANSFER:', transferId);

        if (!this.isOnline) {
            throw new Error('Receive is only available when online');
        }

        try {
            const url = this.bustCache(BASE_URL + 'ajax/receive_transfer.php');

            const response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                cache: 'no-store',
                body: JSON.stringify({
                    transfer_id: transferId,
                    items: items,
                    receive_remarks: remarks
                })
            });

            const result = await response.json();

            if (!result.success) {
                throw new Error(result.message || 'Failed to receive');
            }

            // Refresh transfers
            await this.fetchTransfersFromServer();

            this.toast('Transfer received', 'success');
            return result;

        } catch (error) {
            console.error('❌ RECEIVE failed:', error);
            this.toast(error.message, 'danger');
            throw error;
        }
    }

    /**
     * Cancel transfer (ONLINE ONLY - may involve stock reversal)
     */
    async cancelTransfer(transferId, reason) {
        console.log('❌ CANCEL TRANSFER:', transferId);

        if (!this.isOnline) {
            throw new Error('Cancel is only available when online');
        }

        try {
            const url = this.bustCache(BASE_URL + 'ajax/cancel_transfer.php');

            const response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                cache: 'no-store',
                body: JSON.stringify({
                    transfer_id: transferId,
                    cancel_reason: reason
                })
            });

            const result = await response.json();

            if (!result.success) {
                throw new Error(result.message || 'Failed to cancel');
            }

            // Refresh transfers
            await this.fetchTransfersFromServer();

            this.toast('Transfer cancelled', 'success');
            return result;

        } catch (error) {
            console.error('❌ CANCEL failed:', error);
            this.toast(error.message, 'danger');
            throw error;
        }
    }

    // ==================== CONSUMPTION VOUCHERS ====================

    /**
     * Fetch consumption vouchers from server
     */
    async fetchConsumptionVouchersFromServer() {
        console.log('ðŸŒ Fetching consumption vouchers from server...');

        const url = this.bustCache(this.apiBase + 'consumption-vouchers.php');

        const response = await fetch(url, {
            method: 'GET',
            headers: {
                'Cache-Control': 'no-cache, no-store, must-revalidate',
                'Pragma': 'no-cache',
                'Expires': '0'
            },
            cache: 'no-store'
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const result = await response.json();

        if (!result.success) {
            throw new Error(result.error || 'Failed to fetch');
        }

        console.log(`ðŸ'¾ Replacing IndexedDB with ${result.consumption_vouchers.length} consumption vouchers`);
        await offlineDB.replaceAll('consumption_vouchers', result.consumption_vouchers);
    }

    /**
     * Get all consumption vouchers
     */
    async getConsumptionVouchers() {
        console.log('ðŸ"– getConsumptionVouchers() called');

        if (this.isOnline) {
            console.log('ðŸŸ¢ Online - fetching from server');
            try {
                await this.fetchConsumptionVouchersFromServer();
            } catch (error) {
                console.error('âŒ Server fetch failed:', error.message);
                console.log('ðŸ"¦ Using IndexedDB');
            }
        } else {
            console.log('ðŸ"´ Offline - using IndexedDB');
        }

        const vouchers = await offlineDB.getAll('consumption_vouchers');
        console.log(`ðŸ"Š Returning ${vouchers.length} consumption vouchers`);
        return vouchers;
    }

    /**
     * Create consumption voucher (offline-capable)
     */
    async createConsumptionVoucher(data) {
        console.log('âž• CREATE CONSUMPTION VOUCHER:', data);

        if (this.isOnline) {
            console.log('ðŸŸ¢ Online - saving to server');

            try {
                const url = this.bustCache(this.apiBase + 'consumption-vouchers-sync.php');

                const response = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    cache: 'no-store',
                    body: JSON.stringify({ action: 'create', data })
                });

                const result = await response.json();

                if (!result.success) {
                    throw new Error(this.formatError(result, 'Failed to create consumption voucher'));
                }

                // Refresh from server
                await this.fetchConsumptionVouchersFromServer();

                this.toast('Consumption voucher created', 'success');
                return {
                    success: true,
                    serverId: result.serverId,
                    serverNumber: result.serverNumber
                };

            } catch (error) {
                console.error('âŒ CREATE failed:', error);
                this.toast(error.message, 'danger');
                throw error;
            }
        } else {
            console.log('ðŸ"´ Offline - saving locally');

            const tempId = 'TEMP_CV_' + Date.now();

            // Get related data for display
            const locations = await offlineDB.getAll('locations');
            const location = locations.find(l => l.id == data.location_id);

            const costCentres = await offlineDB.getAll('cost_centres');
            const costCentre = data.cost_centre_id ? costCentres.find(cc => cc.id == data.cost_centre_id) : null;

            // Enrich details with product names
            const products = await offlineDB.getAll('products');
            const enrichedDetails = data.details.map(detail => {
                const product = products.find(p => p.id == detail.product_id);
                return {
                    ...detail,
                    product_name: product ? product.name : 'Unknown',
                    unit: product ? product.unit : '',
                    category_name: product ? product.category_name : ''
                };
            });

            const voucher = {
                id: tempId,
                voucher_number: 'TEMP_CV_' + Date.now(),
                voucher_date: data.voucher_date,
                location_id: data.location_id,
                location_name: location ? location.name : 'Unknown',
                cost_centre_id: data.cost_centre_id || null,
                cost_centre_name: costCentre ? costCentre.name : null,
                cost_centre_code: costCentre ? costCentre.code : null,
                total_amount: enrichedDetails.reduce((sum, item) =>
                    sum + (parseFloat(item.quantity) * parseFloat(item.rate_per_unit || 0)), 0
                ),
                remarks: data.remarks || '',
                details: enrichedDetails,
                created_by_name: '',
                created_at: new Date().toISOString(),
                updated_at: new Date().toISOString(),
                synced: false
            };

            await offlineDB.save('consumption_vouchers', voucher);
            await offlineDB.addToQueue({
                module: 'consumption-vouchers',
                action: 'create',
                data: data,
                localId: tempId,
                tempId: tempId
            });

            this.toast('Consumption voucher saved offline - will sync when online', 'warning');
            return {
                success: true,
                voucher: voucher
            };
        }
    }

    /**
     * Delete consumption voucher (ONLINE ONLY - involves stock reversal)
     */
    async deleteConsumptionVoucher(id) {
        console.log("DELETE CONSUMPTION VOUCHER:", id);

        if (!this.isOnline) {
            throw new Error('Delete is only available when online');
        }

        try {
            const url = this.bustCache(this.apiBase + 'consumption-vouchers-sync.php');

            const response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                cache: 'no-store',
                body: JSON.stringify({ action: 'delete', data: { id } })
            });

            const result = await response.json();

            if (!result.success) {
                throw new Error(this.formatError(result, 'Failed to delete consumption voucher'));
            }

            // Remove from IndexedDB
            await offlineDB.delete('consumption_vouchers', id);

            this.toast('Consumption voucher deleted', 'success');

        } catch (error) {
            console.error('âŒ DELETE failed:', error);
            this.toast(error.message, 'danger');
            throw error;
        }
    }

    // ==================== REQUISITION VOUCHERS ====================

    /**
     * Fetch requisition vouchers from server
     */
    async fetchRequisitionVouchersFromServer() {
        console.log('Fetching requisition vouchers from server...');

        const url = this.bustCache(this.apiBase + 'requisition-vouchers.php');

        const response = await fetch(url, {
            method: 'GET',
            headers: {
                'Cache-Control': 'no-cache, no-store, must-revalidate',
                'Pragma': 'no-cache',
                'Expires': '0'
            },
            cache: 'no-store'
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const result = await response.json();

        if (!result.success) {
            throw new Error(result.error || 'Failed to fetch');
        }

        console.log(`Replacing IndexedDB with ${result.requisition_vouchers.length} requisition vouchers`);
        await offlineDB.replaceAll('requisition_vouchers', result.requisition_vouchers);
    }

    /**
     * Get all requisition vouchers
     */
    async getRequisitionVouchers() {
        if (this.isOnline) {
            try {
                await this.fetchRequisitionVouchersFromServer();
            } catch (error) {
                console.error('Server fetch failed:', error.message);
            }
        }

        const vouchers = await offlineDB.getAll('requisition_vouchers');
        return vouchers;
    }

    /**
     * Create requisition voucher (offline-capable)
     */
    async createRequisitionVoucher(data) {
        console.log('CREATE REQUISITION VOUCHER:', data);

        if (this.isOnline) {
            try {
                const url = this.bustCache(this.apiBase + 'requisition-vouchers-sync.php');

                const response = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    cache: 'no-store',
                    body: JSON.stringify({ action: 'create', data })
                });

                const result = await response.json();

                if (!result.success) {
                    throw new Error(this.formatError(result, 'Failed to create requisition voucher'));
                }

                await this.fetchRequisitionVouchersFromServer();

                this.toast('Requisition voucher created', 'success');
                return {
                    success: true,
                    serverId: result.serverId,
                    serverNumber: result.serverNumber
                };

            } catch (error) {
                console.error('CREATE failed:', error);
                this.toast(error.message, 'danger');
                throw error;
            }
        } else {
            const tempId = 'TEMP_RV_' + Date.now();

            const locations = await offlineDB.getAll('locations');
            const location = locations.find(l => l.id == data.destination_location_id);

            const costCentres = await offlineDB.getAll('cost_centres');
            const costCentre = data.cost_centre_id ? costCentres.find(cc => cc.id == data.cost_centre_id) : null;

            const products = await offlineDB.getAll('products');
            const enrichedDetails = data.details.map(detail => {
                const product = products.find(p => p.id == detail.product_id);
                return {
                    ...detail,
                    product_name: product ? product.name : 'Unknown',
                    unit: product ? product.unit : ''
                };
            });

            const voucher = {
                id: tempId,
                voucher_number: 'TEMP_RV_' + Date.now(),
                voucher_date: data.voucher_date,
                destination_location_id: data.destination_location_id,
                destination_location_name: location ? location.name : 'Unknown',
                cost_centre_id: data.cost_centre_id || null,
                cost_centre_name: costCentre ? costCentre.name : null,
                status: 'draft',
                remarks: data.remarks || '',
                details: enrichedDetails,
                created_by_name: '',
                created_at: new Date().toISOString(),
                synced: false
            };

            await offlineDB.save('requisition_vouchers', voucher);
            await offlineDB.addToQueue({
                module: 'requisition-vouchers',
                action: 'create',
                data: data,
                localId: tempId,
                tempId: tempId
            });

            this.toast('Requisition voucher saved offline - will sync when online', 'warning');
            return {
                success: true,
                voucher: voucher
            };
        }
    }

    /**
     * Show toast notification
     */
    toast(message, type = 'info') {
        document.querySelectorAll('.sync-toast').forEach(t => t.remove());
        
        const toast = document.createElement('div');
        toast.className = `sync-toast alert alert-${type} alert-dismissible fade show`;
        toast.style.cssText = `
            position: fixed;
            top: 80px;
            right: 20px;
            z-index: 9999;
            min-width: 300px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.2);
        `;
        toast.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 150);
        }, 5000);
    }
}

// Create singleton
window.syncManager = new SyncManager();