/**
 * Offline Database Manager - Updated for Purchases & Locations
 * Simple, robust IndexedDB wrapper
 */

class OfflineDB {
    constructor() {
        this.dbName = 'InventoryDB';
        this.version = 13; // INCREMENTED for requisition_vouchers store
        this.db = null;
    }

    /**
     * Initialize database
     */
    async init() {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open(this.dbName, this.version);

            request.onerror = () => {
                console.error('Database failed to open:', request.error);
                reject(request.error);
            };

            request.onsuccess = () => {
                this.db = request.result;
                console.log('✓ Database initialized');
                resolve(this.db);
            };

            request.onupgradeneeded = (event) => {
                const db = event.target.result;
                console.log('Creating/updating database stores...');
                
                // Roles store - mirror of server data
                if (!db.objectStoreNames.contains('roles')) {
                    db.createObjectStore('roles', { keyPath: 'id' });
                }

                // Products store
                if (!db.objectStoreNames.contains('products')) {
                    db.createObjectStore('products', { keyPath: 'id' });
                }

                // Categories store (read-only cache)
                if (!db.objectStoreNames.contains('categories')) {
                    db.createObjectStore('categories', { keyPath: 'id' });
                }

                // Suppliers store (read-only cache for dropdowns)
                if (!db.objectStoreNames.contains('suppliers')) {
                    db.createObjectStore('suppliers', { keyPath: 'id' });
                }

                // Customers store (read-only cache for dropdowns)
                if (!db.objectStoreNames.contains('customers')) {
                    db.createObjectStore('customers', { keyPath: 'id' });
                }

                // Locations store (read-only cache for dropdowns)
                if (!db.objectStoreNames.contains('locations')) {
                    db.createObjectStore('locations', { keyPath: 'id' });
                }

                // Purchases store - with details embedded
                if (!db.objectStoreNames.contains('purchases')) {
                    db.createObjectStore('purchases', { keyPath: 'id' });
                }

                // Sales store - with details embedded
                if (!db.objectStoreNames.contains('sales')) {
                    db.createObjectStore('sales', { keyPath: 'id' });
                }

                // Product pricing store (location-specific prices)
                if (!db.objectStoreNames.contains('product_pricing')) {
                    db.createObjectStore('product_pricing', { keyPath: 'id' });
                }

                // Accounts store (cash/bank accounts for receipts)
                if (!db.objectStoreNames.contains('accounts')) {
                    db.createObjectStore('accounts', { keyPath: 'id' });
                }

                // Stock Transfers store - with details embedded
                if (!db.objectStoreNames.contains('stock_transfers')) {
                    db.createObjectStore('stock_transfers', { keyPath: 'id' });
                }

                // Cost Centres store (read-only cache for dropdowns)
                if (!db.objectStoreNames.contains('cost_centres')) {
                    db.createObjectStore('cost_centres', { keyPath: 'id' });
                }

                // Consumption Vouchers store - with details embedded
                if (!db.objectStoreNames.contains('consumption_vouchers')) {
                    db.createObjectStore('consumption_vouchers', { keyPath: 'id' });
                }

                // Requisition Vouchers store - with details embedded
                if (!db.objectStoreNames.contains('requisition_vouchers')) {
                    db.createObjectStore('requisition_vouchers', { keyPath: 'id' });
                }

                // Currencies store (read-only cache for dropdowns)
                if (!db.objectStoreNames.contains('currencies')) {
                    db.createObjectStore('currencies', { keyPath: 'id' });
                }

                // Exchange Rates store (read-only cache)
                if (!db.objectStoreNames.contains('exchange_rates')) {
                    db.createObjectStore('exchange_rates', { keyPath: 'id' });
                }

                // Sync queue - tracks pending operations
                if (!db.objectStoreNames.contains('sync_queue')) {
                    const queue = db.createObjectStore('sync_queue', { 
                        keyPath: 'id', 
                        autoIncrement: true 
                    });
                    queue.createIndex('timestamp', 'timestamp', { unique: false });
                    queue.createIndex('module', 'module', { unique: false });
                }
            };
        });
    }

    /**
     * Save/Update item
     */
    async save(store, item) {
        const tx = this.db.transaction([store], 'readwrite');
        const objectStore = tx.objectStore(store);
        
        return new Promise((resolve, reject) => {
            const request = objectStore.put(item);
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    }

    /**
     * Get single item
     */
    async get(store, id) {
        const tx = this.db.transaction([store], 'readonly');
        const objectStore = tx.objectStore(store);
        
        return new Promise((resolve, reject) => {
            const request = objectStore.get(id);
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    }

    /**
     * Get all items
     */
    async getAll(store) {
        const tx = this.db.transaction([store], 'readonly');
        const objectStore = tx.objectStore(store);
        
        return new Promise((resolve, reject) => {
            const request = objectStore.getAll();
            request.onsuccess = () => resolve(request.result || []);
            request.onerror = () => reject(request.error);
        });
    }

    /**
     * Delete item
     */
    async delete(store, id) {
        const tx = this.db.transaction([store], 'readwrite');
        const objectStore = tx.objectStore(store);
        
        return new Promise((resolve, reject) => {
            const request = objectStore.delete(id);
            request.onsuccess = () => resolve();
            request.onerror = () => reject(request.error);
        });
    }

    /**
     * Clear entire store
     */
    async clear(store) {
        const tx = this.db.transaction([store], 'readwrite');
        const objectStore = tx.objectStore(store);
        
        return new Promise((resolve, reject) => {
            const request = objectStore.clear();
            request.onsuccess = () => resolve();
            request.onerror = () => reject(request.error);
        });
    }

    /**
     * Replace all items in store (for sync)
     */
    async replaceAll(store, items) {
        const tx = this.db.transaction([store], 'readwrite');
        const objectStore = tx.objectStore(store);
        
        return new Promise((resolve, reject) => {
            // Clear first
            const clearReq = objectStore.clear();
            
            clearReq.onsuccess = () => {
                // Then add all items
                let completed = 0;
                const total = items.length;
                
                if (total === 0) {
                    resolve();
                    return;
                }
                
                items.forEach(item => {
                    const addReq = objectStore.put(item);
                    addReq.onsuccess = () => {
                        completed++;
                        if (completed === total) {
                            resolve();
                        }
                    };
                    addReq.onerror = () => reject(addReq.error);
                });
            };
            
            clearReq.onerror = () => reject(clearReq.error);
        });
    }

    /**
     * Upsert a batch of items (put by keyPath — adds new, overwrites existing, keeps others intact)
     */
    async upsertAll(store, items) {
        if (!items || items.length === 0) return;

        const tx = this.db.transaction([store], 'readwrite');
        const objectStore = tx.objectStore(store);

        return new Promise((resolve, reject) => {
            let completed = 0;
            const total = items.length;

            items.forEach(item => {
                const req = objectStore.put(item);
                req.onsuccess = () => {
                    completed++;
                    if (completed === total) resolve();
                };
                req.onerror = () => reject(req.error);
            });
        });
    }

    /**
     * Add to sync queue
     */
    async addToQueue(operation) {
        const queueItem = {
            ...operation,
            timestamp: Date.now()
        };
        return this.save('sync_queue', queueItem);
    }

    /**
     * Get sync queue
     */
    async getQueue() {
        return this.getAll('sync_queue');
    }

    /**
     * Get queue for specific module
     */
    async getQueueForModule(module) {
        const allQueue = await this.getQueue();
        return allQueue.filter(item => item.module === module);
    }

    /**
     * Clear sync queue
     */
    async clearQueue() {
        return this.clear('sync_queue');
    }

    /**
     * Remove item from queue
     */
    async removeFromQueue(id) {
        return this.delete('sync_queue', id);
    }
}

// Create singleton
window.offlineDB = new OfflineDB();
