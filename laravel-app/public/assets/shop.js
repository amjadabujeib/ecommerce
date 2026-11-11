import {createApiClient} from './api-client.js';
const $ = selector => document.querySelector(selector);
let token = sessionStorage.getItem('novacart-token');
let user = null;
let identityVersion = 0;
let currentView = 'catalog';
let catalogRequest = 0;
let detailRequest = 0;
let navigation = 0;
let currency = 'USD';
let lastCatalog = sessionStorage.getItem('novacart-last-catalog') || 'shop';
let pendingAdd = null;
let returnAfterAuth = null;
let noticeTimer;
const apiClient = createApiClient({
    initialToken: token,
    onToken(value) {
        token = value;
        if (value) sessionStorage.setItem('novacart-token', value);
        else sessionStorage.removeItem('novacart-token');
    },
    onSessionEnded() { resetPrivateViews(); user = null; updateIdentity(); },
});
const scrollPositions = new Map();
const money = value => new Intl.NumberFormat('en-US', {style: 'currency', currency}).format(Number(value));
const node = (tag, text, className) => {
    const element = document.createElement(tag);
    if (text !== undefined) element.textContent = text;
    if (className) element.className = className;
    return element;
};
function action(label, callback, className) {
    const button = node('button', label, className);
    button.type = 'button';
    button.addEventListener('click', () => run(callback, button));
    return button;
}
function link(label, path, className) {
    const element = node('a', label, className);
    element.href = `#/${path}`;
    element.addEventListener('click', event => {
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault(); run(() => go(path));
    });
    return element;
}
function notice(message, error = false, destination = null) {
    clearTimeout(noticeTimer);
    $('#notice-text').textContent = message;
    $('#notice').classList.toggle('error', error);
    $('#notice').setAttribute('role', error ? 'alert' : 'status');
    $('#notice').hidden = false;
    $('#notice-actions').replaceChildren();
    if (destination) $('#notice-actions').append(link(destination.label, destination.path));
    if (!error) noticeTimer = setTimeout(() => { $('#notice').hidden = true; }, 6000);
}
async function run(callback, button) {
    if (button) button.disabled = true;
    const errorBox = button?.closest('form')?.querySelector('[data-errors]');
    if (errorBox) errorBox.hidden = true;
    try { await callback(); }
    catch (error) {
        if (errorBox && (!errorBox.closest('dialog') || errorBox.closest('dialog').open)) { errorBox.textContent = error.message; errorBox.hidden = false; errorBox.focus(); }
        else notice(error.message, true);
    } finally { if (button) button.disabled = false; }
}
function resetPrivateViews() {
    identityVersion++;
    for (const id of ['cart-content', 'order-list', 'order-pages', 'notification-list', 'notification-pages', 'admin-orders', 'admin-order-pages', 'admin-products', 'admin-product-pages', 'categories', 'dashboard']) {
        document.getElementById(id)?.replaceChildren();
    }
    $('#cart-count').textContent = '0';
    $('#product-editor')?.close();
}
function setToken(value) {
    resetPrivateViews();
    apiClient.setToken(value);
}
async function api(path, options = {}) {
    const identity = identityVersion;
    const result = await apiClient.request(path, options);
    if (identity !== identityVersion) throw new Error('Your session changed. Please try again.');
    if (result.currency) currency = result.currency;
    return result;
}
function updateIdentity() {
    document.querySelectorAll('[data-auth]').forEach(element => { element.hidden = !user; });
    document.querySelectorAll('[data-admin]').forEach(element => { element.hidden = user?.role !== 'admin'; });
    $('#auth-forms').hidden = !!user;
    $('#account-actions').hidden = !user;
    $('#account-link').textContent = user ? user.username : 'Sign in';
    $('#identity').textContent = user ? `${user.username} · ${user.email} · ${user.role_label}` : pendingAdd ? 'Sign in or create an account to add this item to your cart.' : 'Sign in to shop, manage your cart and follow your orders.';
    if (!user) $('#cart-count').textContent = '0';
}
async function cartCount() {
    const identity = identityVersion;
    if (user) { const result = await api('/cart'); if (identity === identityVersion) $('#cart-count').textContent = result.data.item_count; }
}
function activate(view) {
    currentView = view;
    document.querySelectorAll('.view').forEach(element => { element.hidden = element.id !== view; });
    document.querySelectorAll('[data-view]').forEach(element => {
        const active = element.dataset.view === view || (view === 'product' && element.dataset.view === 'catalog');
        if (active) element.setAttribute('aria-current', 'page'); else element.removeAttribute('aria-current');
    });
}
async function go(path) {
    scrollPositions.set(location.hash, window.scrollY);
    history.pushState(null, '', `#/${path}`);
    await route();
}
async function route() {
    const visit = ++navigation;
    const path = location.hash.replace(/^#\/?/, '') || 'shop';
    const [pathname, query = ''] = path.split('?');
    const params = new URLSearchParams(query);
    let view = pathname === 'shop' ? 'catalog' : pathname.split('/')[0];
    if (view === 'products') view = 'product';
    if (['cart', 'orders', 'notifications', 'admin'].includes(view) && !user) { returnAfterAuth = path; view = 'account'; }
    if (view === 'admin' && user?.role !== 'admin') view = 'account';
    if (!['catalog', 'product', 'cart', 'orders', 'notifications', 'account', 'admin'].includes(view)) view = 'catalog';
    activate(view);
    $('#main').setAttribute('aria-busy', 'true'); $('#loading').hidden = false;
    try {
        if (view === 'catalog') {
            lastCatalog = path.startsWith('shop') ? path : 'shop';
            sessionStorage.setItem('novacart-last-catalog', lastCatalog);
            $('#filters').elements.q.value = params.get('q') || '';
            await catalog(Number(params.get('page')) || 1, params.get('category') || '');
        }
        if (view === 'product') await product(pathname.split('/')[1]);
        if (view === 'cart') await cart();
        if (view === 'orders') await orders(Number(params.get('page')) || 1);
        if (view === 'notifications') await notifications(Number(params.get('page')) || 1);
        if (view === 'admin') await admin(pathname.split('/')[1] || 'overview', Number(params.get('page')) || 1);
        if (visit === navigation) {
            const title = $(`#${view} h1`);
            document.title = `${title?.textContent || 'Shop'} · NovaCart`;
            if (title) { title.tabIndex = -1; title.focus({preventScroll: true}); }
            window.scrollTo(0, scrollPositions.get(location.hash) || 0);
        }
    } catch (error) {
        if (visit === navigation) { activate('page-error'); $('#page-error-message').textContent = error.message; }
    } finally {
        if (visit === navigation) { $('#loading').hidden = true; $('#main').removeAttribute('aria-busy'); }
    }
}
function pagination(selector, result, load) {
    const element = $(selector); element.replaceChildren();
    if (!result.meta || result.meta.last_page <= 1) return;
    const {current_page: page, last_page: last} = result.meta;
    if (page > 1) element.append(action('← Previous', () => load(page - 1), 'secondary'));
    element.append(node('span', `Page ${page} of ${last}`));
    if (page < last) element.append(action('Next →', () => load(page + 1), 'secondary'));
}
function empty(message, label = 'Browse products', path = 'shop') {
    const panel = node('div', undefined, 'empty-state');
    panel.append(node('p', message), link(label, path, 'button-link')); return panel;
}
function productImage(product, className = '') {
    if (!product.image_path) {
        const placeholder = node('div', undefined, `placeholder ${className}`);
        placeholder.append(node('span', '◇', 'placeholder-icon'), node('span', 'No image available', 'hint'));
        return placeholder;
    }
    const image = node('img', undefined, className);
    image.src = `/storage/${product.image_path.split('/').map(encodeURIComponent).join('/')}`;
    image.alt = product.title; image.loading = 'lazy';
    return image;
}
function stockText(product) {
    return product.stock_quantity === null ? 'Availability temporarily unavailable' : product.stock_quantity === 0 ? 'Out of stock' : `${product.stock_quantity} available`;
}
async function addToCart(product, quantity) {
    if (!user) { pendingAdd = {product, quantity}; updateIdentity(); await go('account'); return; }
    const result = await api(`/cart/${product.id}`, {method: 'POST', body: {quantity}});
    $('#cart-count').textContent = result.data.item_count;
    notice(`${product.title} added to your cart.`, false, {label: 'View cart →', path: 'cart'});
}
function productCard(product, isAdmin = false) {
    const card = node('article', undefined, 'card');
    const imageLink = link(undefined, `products/${product.id}`, 'product-image-link');
    imageLink.setAttribute('aria-label', `View ${product.title}`); imageLink.append(productImage(product));
    card.append(imageLink, node('p', product.category?.name || 'Uncategorized', 'category-label'), link(product.title, `products/${product.id}`, 'product-title'), node('div', money(product.price), 'price'), node('p', stockText(product), product.in_stock ? 'stock available' : 'stock'));
    const buttons = node('div', undefined, 'card-actions');
    if (isAdmin) {
        buttons.append(action('Edit product', () => editProduct(product.id), 'secondary'), action('Delete', async () => {
            if (!confirm(`Delete product "${product.title}"?`)) return;
            await api(`/admin/products/${product.id}`, {method: 'DELETE'}); await route(); notice('Product deleted.');
        }, 'danger'));
    } else {
        const add = action('Add to cart', () => addToCart(product, 1)); add.disabled = !product.in_stock;
        buttons.append(link('View details', `products/${product.id}`, 'button-link secondary'), add);
    }
    card.append(buttons); return card;
}
function categoryOptions(select, categories, selected = '', includeAll = false) {
    const options = categories.map(category => { const option = node('option', category.name); option.value = category.id; return option; });
    if (includeAll) { const all = node('option', 'All categories'); all.value = ''; options.unshift(all); }
    select.replaceChildren(...options); select.value = String(selected || (includeAll ? '' : categories[0]?.id || ''));
}
async function catalog(page = 1, category = '') {
    const request = ++catalogRequest;
    const query = new URLSearchParams({page});
    const q = $('#filters').elements.q.value.trim();
    if (q) query.set('q', q);
    if (category) query.set('category', category);
    const result = await api(`/?${query}`);
    if (request !== catalogRequest || currentView !== 'catalog') return;
    categoryOptions($('#filters').elements.category, result.categories || [], category, true);
    $('#product-results').textContent = `${result.meta.total} product${result.meta.total === 1 ? '' : 's'}${q ? ` matching “${q}”` : ''}`;
    $('#products').replaceChildren(...(result.data.length ? result.data.map(item => productCard(item)) : [empty('No products match these filters.', 'Clear filters', 'shop')]));
    pagination('#catalog-pages', result, next => { query.set('page', next); return go(`shop?${query}`); });
}
async function product(id) {
    if (!/^\d+$/.test(id || '')) throw new Error('This product could not be found.');
    const request = ++detailRequest;
    $('#product-detail').replaceChildren(node('p', 'Loading product…'));
    const result = await api(`/products/${id}`);
    if (request !== detailRequest || currentView !== 'product') return;
    const item = result.data;
    const content = $('#product-detail'); content.replaceChildren(link('← Back to results', lastCatalog, 'back-link'));
    const layout = node('div', undefined, 'product-layout');
    layout.append(productImage(item, 'detail-image'));
    const info = node('div', undefined, 'product-info');
    info.append(node('p', item.category?.name || 'Uncategorized', 'eyebrow'), node('h1', item.title), node('div', money(item.price), 'detail-price'), node('p', stockText(item), item.in_stock ? 'stock available' : 'stock'));
    const form = node('form'); form.id = 'product-purchase';
    const label = node('label', 'Quantity'); const quantity = node('input'); quantity.id = 'product-quantity'; quantity.name = 'quantity'; quantity.type = 'number'; quantity.min = '1'; quantity.max = Math.max(1, Math.min(999, item.stock_quantity || 1)); quantity.value = '1'; quantity.required = true; label.append(quantity);
    const button = node('button', user ? 'Add to cart' : 'Sign in to add to cart'); button.disabled = !item.in_stock;
    const row = node('div', undefined, 'row'); row.append(button, link('View cart', user ? 'cart' : 'account', 'button-link secondary'));
    form.append(label, row); form.addEventListener('submit', event => { event.preventDefault(); run(() => addToCart(item, Number(quantity.value)), event.submitter); });
    info.append(form, node('p', 'Prices and availability are checked again at checkout.', 'hint'), node('h2', 'About this product'), node('p', item.description, 'description')); layout.append(info); content.append(layout);
    if (result.related?.length) { content.append(node('h2', 'You may also like')); const related = node('div', undefined, 'grid'); related.append(...result.related.map(item => productCard(item))); content.append(related); }
}
async function cart() {
    const identity = identityVersion;
    const {data} = await api('/cart'); if (identity !== identityVersion) return; currency = data.currency; $('#cart-count').textContent = data.item_count;
    const content = $('#cart-content'); content.replaceChildren();
    if (!data.items.length) { content.append(empty('Your cart is empty. Find something you like in the shop.')); return; }
    const layout = node('div', undefined, 'cart-layout'); const items = node('div');
    for (const line of data.items) {
        const card = node('article', undefined, 'cart-line panel'); card.append(productImage(line.product));
        const info = node('div'); info.append(link(line.product.title, `products/${line.product.id}`, 'product-title'), node('p', `${money(line.product.price)} each`));
        const quantity = node('input'); quantity.type = 'number'; quantity.min = '1'; quantity.max = '999'; quantity.value = line.quantity; quantity.required = true; quantity.setAttribute('aria-label', `Quantity for ${line.product.title}`);
        const row = node('div', undefined, 'row'); row.append(quantity, action('Update', async () => {
            if (!quantity.reportValidity()) return;
            await api(`/cart/${line.product.id}`, {method: 'PATCH', body: {quantity: Number(quantity.value)}}); await cart();
        }, 'secondary'), action('Remove', async () => { await api(`/cart/${line.product.id}`, {method: 'DELETE'}); await cart(); }, 'text-button'));
        info.append(row); card.append(info, node('strong', money(line.line_total))); items.append(card);
    }
    const summary = node('aside', undefined, 'panel order-summary');
    summary.append(node('h2', 'Order summary'), node('p', `${data.item_count} item${data.item_count === 1 ? '' : 's'}`), node('p', `Subtotal: ${money(data.subtotal)}`, 'summary-total'), node('p', 'Review your quantities, then place your order. Prices are confirmed by the shop.'));
    summary.append(action('Place order', async () => {
        const result = await api('/orders', {method: 'POST'}); $('#cart-count').textContent = '0';
        await go('orders'); notice(`Order #${result.data.id} placed successfully.`);
    }), action('Empty cart', async () => {
        if (!confirm('Remove every item from your cart?')) return;
        await api('/cart', {method: 'DELETE'}); await cart();
    }, 'text-button'));
    layout.append(items, summary); content.append(layout);
}
async function orders(page = 1, isAdmin = false) {
    const identity = identityVersion;
    const result = await api(`${isAdmin ? '/admin' : ''}/orders?page=${page}`);
    if (identity !== identityVersion) return;
    const cards = result.data.map(order => {
        const card = node('article', undefined, 'panel order-card'); const heading = node('div', undefined, 'section-heading');
        heading.append(node('h3', `Order #${order.id}`), node('span', order.status_label, `status status-${order.status}`));
        card.append(heading, node('p', `${new Date(order.created_at).toLocaleDateString()} · Total: ${money(order.total_amount)}`));
        if (order.customer) card.append(node('p', `${order.customer.username} · ${order.customer.email}`));
        const lines = node('ul', undefined, 'order-lines');
        for (const item of order.items || []) lines.append(node('li', `${item.product_title} × ${item.quantity} — ${money(item.line_total)}`));
        card.append(lines);
        if (isAdmin && order.status !== 'cancelled') {
            const row = node('div', undefined, 'row');
            for (const status of order.status === 'pending' ? ['completed', 'cancelled'] : ['cancelled']) row.append(action(status === 'completed' ? 'Mark completed' : 'Cancel order', async () => {
                if (status === 'cancelled' && !confirm(`Cancel order #${order.id} and return its stock?`)) return;
                await api(`/admin/orders/${order.id}/status`, {method: 'PATCH', body: {status}}); await orders(page, true); notice(`Order #${order.id} is ${status}.`);
            }, 'secondary'));
            card.append(row);
        }
        return card;
    });
    $(isAdmin ? '#admin-orders' : '#order-list').replaceChildren(...(cards.length ? cards : [empty('No orders yet. Your orders will appear here after checkout.')]));
    pagination(isAdmin ? '#admin-order-pages' : '#order-pages', result, next => go(`${isAdmin ? 'admin/orders' : 'orders'}?page=${next}`));
}
async function notifications(page = 1) {
    const identity = identityVersion;
    const result = await api(`/notifications?page=${page}`);
    if (identity !== identityVersion) return;
    $('#read-all').disabled = !result.data.some(item => !item.read_at);
    $('#notification-list').replaceChildren(...result.data.map(item => {
        const card = node('article', undefined, `panel notification ${item.read_at ? '' : 'unread'}`);
        card.append(node('p', item.message), node('p', new Date(item.created_at).toLocaleString(), 'hint'));
        if (!item.read_at) card.append(action('Mark as read', async () => { await api(`/notifications/${item.id}/read`, {method: 'PATCH'}); await notifications(page); }, 'secondary'));
        return card;
    }));
    if (!result.data.length) $('#notification-list').append(empty('You are all caught up.', 'Continue shopping'));
    pagination('#notification-pages', result, next => go(`notifications?page=${next}`));
}
async function categories() {
    const identity = identityVersion;
    const {data} = await api('/admin/categories');
    if (identity !== identityVersion) return;
    $('#categories').replaceChildren(...data.map(category => {
        const row = node('div', undefined, 'category-row');
        row.append(node('span', `${category.name} · ${category.products_count ?? 0} products`), action('Edit', () => {
            const form = $('#category-form'); form.elements.id.value = category.id; form.elements.name.value = category.name;
            $('#category-heading').textContent = `Edit ${category.name}`; form.elements.name.focus();
        }, 'secondary'), action('Delete', async () => {
            if (!confirm(`Delete category "${category.name}"?`)) return;
            await api(`/admin/categories/${category.id}`, {method: 'DELETE'}); await categories(); notice('Category deleted.');
        }, 'text-button'));
        return row;
    }));
    if (!data.length) $('#categories').append(node('p', 'No categories yet. Add one to get started.'));
}
async function productEditor(id = null) {
    const identity = identityVersion;
    const {data} = await api(id ? `/admin/products/${id}/edit` : '/admin/products/create');
    if (identity !== identityVersion) return;
    const form = $('#product-form'); form.reset(); form.querySelector('[data-errors]').hidden = true;
    categoryOptions(form.elements.category_id, data.categories);
    $('#adjust-stock-control').hidden = !id;
    $('#adjust-stock').checked = false;
    form.elements.stock_quantity.disabled = !!id;
    form.elements.stock_quantity.required = !id;
    if (id) {
        for (const field of ['id', 'title', 'description', 'price', 'stock_quantity']) form.elements[field].value = data.product[field] ?? '';
        form.elements.category_id.value = data.product.category.id;
    }
    $('#product-heading').textContent = id ? `Edit ${data.product.title}` : 'Add a product';
    if (!data.categories.length) { notice('Add a category before creating a product.', true); await go('admin/categories'); return; }
    $('#product-editor').showModal(); form.elements.title.focus();
}
const editProduct = id => productEditor(id);
async function adminProducts(page = 1) {
    const identity = identityVersion;
    const result = await api(`/admin/products?page=${page}`);
    if (identity !== identityVersion) return;
    $('#admin-products').replaceChildren(...(result.data.length ? result.data.map(item => productCard(item, true)) : [empty('No products yet. Use Add product to create your first listing.')]));
    pagination('#admin-product-pages', result, next => go(`admin/products?page=${next}`));
}
async function admin(tab = 'overview', page = 1) {
    const identity = identityVersion;
    const panels = {overview: '#admin-overview', products: '#admin-product-panel', categories: '#admin-category-panel', orders: '#admin-order-panel'};
    if (!panels[tab]) tab = 'overview';
    for (const [name, selector] of Object.entries(panels)) $(selector).hidden = name !== tab;
    document.querySelectorAll('[data-admin-tab]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.adminTab === tab)));
    if (tab === 'overview') {
        const {data} = await api('/admin/dashboard'); if (identity !== identityVersion) return; currency = data.currency;
        $('#dashboard').replaceChildren(...[
            ['Products', data.total_products], ['Orders', data.total_orders], ['Completed sales', money(data.total_sales)], ['Available units', data.stock_units ?? 'Unavailable'],
        ].map(([label, value]) => { const card = node('div', undefined, 'panel stat'); card.append(node('p', label), node('strong', value)); return card; }));
    }
    if (tab === 'products') await adminProducts(page);
    if (tab === 'categories') await categories();
    if (tab === 'orders') await orders(page, true);
}
function authTab(tab) {
    $('#login').hidden = tab !== 'login'; $('#register').hidden = tab !== 'register';
    document.querySelectorAll('[data-auth-tab]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.authTab === tab)));
}
function submit(selector, callback) {
    $(selector).addEventListener('submit', event => { event.preventDefault(); run(() => callback(event.currentTarget), event.submitter); });
}
for (const element of document.querySelectorAll('[data-view]')) element.addEventListener('click', () => run(() => go(({catalog: 'shop', admin: 'admin/overview'})[element.dataset.view] || element.dataset.view)));
for (const element of document.querySelectorAll('[data-admin-tab]')) element.addEventListener('click', () => run(() => go(`admin/${element.dataset.adminTab}`)));
for (const element of document.querySelectorAll('[data-auth-tab]')) element.addEventListener('click', () => authTab(element.dataset.authTab));
for (const element of document.querySelectorAll('[data-close]')) element.addEventListener('click', () => $(`#${element.dataset.close}`).close());
$('#dismiss-notice').addEventListener('click', () => { $('#notice').hidden = true; });
$('#retry-page').addEventListener('click', () => run(route));
$('#new-product').addEventListener('click', event => run(() => productEditor(), event.currentTarget));
$('#clear-filters').addEventListener('click', () => run(() => go('shop')));
submit('#filters', form => {
    const query = new URLSearchParams();
    if (form.elements.q.value.trim()) query.set('q', form.elements.q.value.trim());
    if (form.elements.category.value) query.set('category', form.elements.category.value);
    return go(`shop${query.size ? `?${query}` : ''}`);
});
for (const endpoint of ['login', 'register']) submit(`#${endpoint}`, async form => {
    const result = await api(`/${endpoint}`, {method: 'POST', body: Object.fromEntries(new FormData(form))});
    setToken(result.token); user = result.data; updateIdentity(); form.reset();
    const pending = pendingAdd; pendingAdd = null;
    const destination = returnAfterAuth; returnAfterAuth = null;
    if (pending) { await go(`products/${pending.product.id}`); await addToCart(pending.product, pending.quantity); }
    else { await cartCount(); await go(destination || (user.role === 'admin' ? 'admin/overview' : 'shop')); notice(`Welcome, ${user.username}.`); }
});
$('#logout').addEventListener('click', () => run(async () => {
    await api('/logout', {method: 'POST'}); setToken(null); user = null; pendingAdd = null; returnAfterAuth = null; updateIdentity(); authTab('login'); await go('shop'); notice('Signed out.');
}));
$('#read-all').addEventListener('click', () => run(async () => { await api('/notifications/read-all', {method: 'POST'}); await notifications(); }));
submit('#category-form', async form => {
    const id = form.elements.id.value;
    await api(`/admin/categories${id ? `/${id}` : ''}`, {method: id ? 'PUT' : 'POST', body: {name: form.elements.name.value}});
    form.reset(); await categories(); notice('Category saved.');
});
$('#adjust-stock').addEventListener('change', event => {
    const input = $('#product-form').elements.stock_quantity;
    input.disabled = !event.target.checked;
    input.required = event.target.checked;
});
submit('#product-form', async form => {
    const data = new FormData(form); const id = data.get('id'); data.delete('id');
    if (!data.get('image').size) data.delete('image');
    if (id) data.set('_method', 'PUT');
    await api(`/admin/products${id ? `/${id}` : ''}`, {method: 'POST', body: data});
    $('#product-editor').close();
    if (id) await adminProducts(Number(new URLSearchParams(location.hash.split('?')[1] || '').get('page')) || 1);
    else await go('admin/products');
    notice('Product saved.');
});
$('#category-form').addEventListener('reset', () => { $('#category-form').elements.id.value = ''; $('#category-heading').textContent = 'Add a category'; });
$('.skip-link').addEventListener('click', event => { event.preventDefault(); $('#main').focus(); });
window.addEventListener('hashchange', () => run(route));
await run(async () => {
    if (token) { try { user = (await api('/me')).data; } catch (error) { notice(error.message, true); } }
    updateIdentity();
    if (!location.hash) history.replaceState(null, '', '#/shop');
    if (user) await cartCount();
    await route();
});
