// multiSyncSettings is injected by wp_localize_script
const { root, nonce } = window.multiSyncSettings || { root: '/wp-json/', nonce: '' };

const request = async (method, path, data = null, config = {}) => {
    const query = config.params ? `?${new URLSearchParams(config.params)}` : '';
    const controller = config.timeout ? new AbortController() : null;
    const timeout = controller ? setTimeout(() => controller.abort(), config.timeout) : null;

    try {
        const response = await fetch(`${root}multi-sync/v1${path}${query}`, {
            method,
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': nonce,
            },
            body: data === null ? undefined : JSON.stringify(data),
            signal: controller?.signal,
        });
        const text = await response.text();
        let payload = null;
        try {
            payload = text ? JSON.parse(text) : null;
        } catch (error) {
            if (response.ok) {
                throw new Error('Sunucu geçersiz bir yanıt döndürdü.');
            }
            payload = { message: response.statusText || 'Sunucu isteği başarısız oldu.' };
        }
        if (!response.ok) {
            const error = new Error(payload?.message || response.statusText);
            error.response = { data: payload, status: response.status };
            throw error;
        }
        return { data: payload };
    } catch (error) {
        if (error.name === 'AbortError') {
            const timeoutError = new Error('Request timed out');
            timeoutError.code = 'ECONNABORTED';
            throw timeoutError;
        }
        throw error;
    } finally {
        if (timeout) clearTimeout(timeout);
    }
};

const api = {
    get: (path, config) => request('GET', path, null, config),
    post: (path, data, config) => request('POST', path, data, config),
};

export default {
    getSuppliers: () => api.get('/suppliers'),
    updateSupplier: (id, data) => api.post(`/suppliers/${id}`, data),
    runSync: (supplierId, type, selectedItems = [], variationChoices = {}) => api.post('/sync/run', { supplier_id: supplierId, type, selected_items: selectedItems, variation_choices: variationChoices }),
    previewSync: (supplierId) => api.post('/sync/preview', { supplier_id: supplierId }),
    previewStockPriceSync: (
        supplierId,
        syncStock = true,
        syncPrice = false,
        selectedItems = []
    ) => api.post('/sync/stock-price-preview', {
        supplier_id: supplierId,
        stock_mode: syncPrice ? 'direct' : 'marketplace_match',
        sync_stock: syncStock,
        sync_price: syncPrice,
        selected_items: selectedItems
    }),
    previewOrders: (supplierId) => api.post('/sync/order-preview', { supplier_id: supplierId }),
    previewProductPublish: (supplierId) => api.post('/products/publish-preview', { supplier_id: supplierId }),
    publishProducts: (supplierId, selectedItems = [], productOverrides = {}) => api.post('/products/publish', { supplier_id: supplierId, selected_items: selectedItems, product_overrides: productOverrides }),
    getMarketplaceCategoryMappings: (supplierId) => api.get(`/marketplaces/category-mappings/${supplierId}`, { params: { _: Date.now() } }),
    searchMarketplaceCategories: (supplierId, query) => api.get(`/marketplaces/categories/${supplierId}`, { params: { query } }),
    getMarketplaceCategoryAttributes: (supplierId, categoryId) => api.get(`/marketplaces/categories/${supplierId}/${encodeURIComponent(categoryId)}/attributes`),
    saveMarketplaceCategoryMapping: (supplierId, data) => api.post(`/marketplaces/category-mappings/${supplierId}`, data),
    searchMarketplaceBrands: (supplierId, query, categoryId = '') => api.get(`/marketplaces/brands/${supplierId}`, { params: { query, category_id: categoryId } }),
    saveMarketplaceBrandMapping: (supplierId, data) => api.post(`/marketplaces/brand-mappings/${supplierId}`, data),
    getMarketplaceHttpDebug: (supplierId, marketplaceKey = '', options = {}) => {
        const query = new URLSearchParams();
        query.set('supplier_id', String(supplierId));
        if (marketplaceKey) {
            query.set('marketplace_key', marketplaceKey);
        }
        if (options && typeof options.limit !== 'undefined') {
            query.set('limit', String(options.limit));
        }
        if (options && options.operation) {
            query.set('operation', String(options.operation));
        }
        if (options && options.status_code) {
            query.set('status_code', String(options.status_code));
        }

        return api.get(`/debug/marketplace-http?${query.toString()}`);
    },
    getMarketplaceRawProducts: (supplierId, options = {}) => {
        const query = new URLSearchParams();
        query.set('supplier_id', String(supplierId));
        if (options && typeof options.page !== 'undefined') {
            query.set('page', String(options.page));
        }
        if (options && typeof options.size !== 'undefined') {
            query.set('size', String(options.size));
        }

        return api.get(`/debug/marketplace-products-raw?${query.toString()}`);
    },
    runStockPriceSync: (
        supplierId,
        selectedItems = [],
        syncStock = true,
        syncPrice = false
    ) => api.post('/sync/stock-price', {
        supplier_id: supplierId,
        selected_items: selectedItems,
        stock_mode: syncPrice ? 'direct' : 'marketplace_match',
        sync_stock: syncStock,
        sync_price: syncPrice
    }),
    getSyncSettings: (supplierId) => api.get(`/settings/${supplierId}`),
    saveSyncSettings: (supplierId, data) => api.post('/settings', { supplier_id: supplierId, ...data }),
    getJobs: (params = {}) => api.get('/jobs', { params }),
    getJob: (id) => api.get(`/jobs/${id}`),
    approveJob: (id) => api.post(`/jobs/${id}/approve`),
    rejectJob: (id) => api.post(`/jobs/${id}/reject`),
    deleteJob: (id) => request('DELETE', `/jobs/${id}`),
    clearJobs: () => request('DELETE', '/jobs'),
    getJobSettings: () => api.get('/jobs/settings'),
    saveJobSettings: (data) => api.post('/jobs/settings', data),
    getChanges: (params = {}) => api.get('/changes', { params }),
    getQuestions: (params = {}) => api.get('/questions', { params }),
    refreshQuestions: (supplierId = null, options = {}) => {
        const payload = {};
        if (supplierId && Number(supplierId) > 0) {
            payload.supplier_id = Number(supplierId);
        }

        const config = {};
        if (options && typeof options.timeout === 'number' && options.timeout > 0) {
            config.timeout = options.timeout;
        }

        return api.post('/questions/refresh', payload, config);
    },
    replyQuestion: (id, answerText) => api.post(`/questions/${id}/reply`, {
        answer_text: answerText
    }),
};
