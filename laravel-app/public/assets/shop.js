const $ = (selector) => document.querySelector(selector);
let token = sessionStorage.getItem('novacart-token');
let user = null;
let currentView = 'catalog';
const money = value => Number(value).toFixed(2);
const node = (tag, text, className) => {
    const element = document.createElement(tag);
    if (text !== undefined) element.textContent = text;
    if (className) element.className = className;
    return element;
};
function notice(message, error = false) {
    const element = $('#notice');
    element.textContent = message;
    element.classList.toggle('error', error);
    element.hidden = false;
}
function action(label, callback, className) {
    const button = node('button', label, className);
    button.type = 'button';
    button.addEventListener('click', () => run(callback, button));
    return button;
}
async function run(callback, button) {
    if (button) button.disabled = true;
    try { await callback(); }
    catch (error) { notice(error.message, true); }
    finally { if (button) button.disabled = false; }
}
function setToken(value) {
    token = value;
    if (value) sessionStorage.setItem('novacart-token', value);
    else sessionStorage.removeItem('novacart-token');
}
async function api(path, {method = 'GET', body, refresh = true} = {}) {
    const headers = {Accept: 'application/json'};
    if (token) headers.Authorization = `Bearer ${token}`;
    if (body && !(body instanceof FormData)) {
        headers['Content-Type'] = 'application/json';
        body = JSON.stringify(body);
    }
    const response = await fetch(`/api/v1${path}`, {method, headers, body});
    const result = await response.json();
    if (response.status === 401 && token && refresh) {
        try {
            const renewed = await api('/refresh', {method: 'POST', refresh: false});
            setToken(renewed.token);
            // Body was serialized above; reconstruct it for the retry.
            return api(path, {method, body: typeof body === 'string' ? JSON.parse(body) : body, refresh: false});
        } catch {
            setToken(null); user = null; updateIdentity();
            throw new Error('Your session has ended. Please sign in again.');
        }
    }
    if (!response.ok) {
        const details = Object.values(result.errors || {}).flat().join('\n');
        throw new Error(details || result.message || `Request failed (${response.status}).`);
    }
    return result;
}
function updateIdentity() {
    document.querySelectorAll('[data-auth]').forEach(element => { element.hidden = !user; });
    document.querySelectorAll('[data-admin]').forEach(element => { element.hidden = user?.role !== 'admin'; });
    $('#auth-forms').hidden = !!user;
    $('#account-link').textContent = user ? user.username : 'Sign in';
    $('#identity').textContent = user ? `${user.username} · ${user.email} · ${user.role_label}` : 'Sign in to manage your cart and orders.';
}
async function show(view) {
    if (['cart', 'orders', 'notifications', 'admin'].includes(view) && !user) view = 'account';
    if (view === 'admin' && user?.role !== 'admin') return;
    currentView = view;
    document.querySelectorAll('.view').forEach(element => { element.hidden = element.id !== view; });
    document.querySelectorAll('[data-view]').forEach(element => {
        if (element.dataset.view === view) element.setAttribute('aria-current', 'page');
        else element.removeAttribute('aria-current');
    });
    if (view === 'catalog') await catalog();
    if (view === 'cart') await cart();
    if (view === 'orders') await orders();
    if (view === 'notifications') await notifications();
    if (view === 'admin') await admin();
}
function pagination(selector, result, load) {
    const element = $(selector); element.replaceChildren();
    if (!result.meta) return;
    const {current_page: page, last_page: last} = result.meta;
    if (page > 1) element.append(action('Previous', () => load(page - 1), 'secondary'));
    element.append(node('span', `Page ${page} of ${last}`));
    if (page < last) element.append(action('Next', () => load(page + 1), 'secondary'));
}
function productCard(product) {
    const card = node('article', undefined, 'card');
    if (product.image_url) {
        const image = node('img');
        // Images are served by this application's storage route.
        const url = new URL(product.image_url, location.origin);
        if (url.origin === location.origin) image.src = url.href;
        image.alt = product.title; image.loading = 'lazy'; card.append(image);
    } else card.append(node('div', 'N', 'placeholder'));
    card.append(node('p', `${product.category?.name || 'Uncategorized'} · Category ${product.category?.id || ''}`), node('h3', product.title), node('p', product.description), node('div', money(product.price), 'price'), node('p', product.stock_quantity === null ? 'Availability temporarily unavailable' : `${product.stock_quantity} available`));
    return card;
}
async function catalog(page = 1) {
    const query = new URLSearchParams(new FormData($('#filters')));
    for (const [key, value] of [...query]) if (!value) query.delete(key);
    query.set('page', page);
    const result = await api(`/?${query}`);
    const cards = result.data.map(product => {
        const card = productCard(product);
        card.append(action('Product details', async () => {
            const detail = (await api(`/products/${product.id}`)).data;
            notice(`${detail.title}\n${detail.description}\nPrice: ${money(detail.price)} · Available: ${detail.stock_quantity ?? 'unknown'}`);
        }, 'secondary'));
        const add = action('Add to cart', async () => {
            if (!user) { await show('account'); return; }
            await api(`/cart/${product.id}`, {method: 'POST', body: {quantity: 1}});
            notice(`${product.title} added to your cart.`);
        });
        add.disabled = !product.in_stock; card.append(add); return card;
    });
    $('#products').replaceChildren(...(cards.length ? cards : [node('p', 'No products match your search.')]));
    pagination('#catalog-pages', result, catalog);
}
async function cart() {
    const {data} = await api('/cart');
    const content = $('#cart-content'); content.replaceChildren();
    for (const line of data.items) {
        const card = node('article', undefined, 'panel');
        const quantity = node('input'); quantity.type = 'number'; quantity.min = '1'; quantity.max = '999'; quantity.value = line.quantity;
        quantity.setAttribute('aria-label', `Quantity for ${line.product.title}`);
        const row = node('div', undefined, 'row');
        row.append(quantity, action('Update quantity', async () => {
            if (!quantity.reportValidity()) return;
            await api(`/cart/${line.product.id}`, {method: 'PATCH', body: {quantity: Number(quantity.value)}}); await cart();
        }), action('Remove', async () => { await api(`/cart/${line.product.id}`, {method: 'DELETE'}); await cart(); }, 'secondary'));
        card.append(node('h2', line.product.title), node('p', `Line total: ${line.line_total} ${data.currency}`), row); content.append(card);
    }
    content.append(node('h2', `Subtotal: ${data.subtotal} ${data.currency}`));
    if (!data.items.length) { content.append(node('p', 'Your cart is empty. Browse the shop to get started.')); return; }
    content.append(action('Place order', async () => {
        const result = await api('/orders', {method: 'POST'});
        notice(`Order #${result.data.id} placed successfully.`); await show('orders');
    }), action('Empty cart', async () => { await api('/cart', {method: 'DELETE'}); await cart(); }, 'secondary'));
}
async function orders(page = 1, isAdmin = false) {
    const result = await api(`${isAdmin ? '/admin' : ''}/orders?page=${page}`);
    const cards = result.data.map(order => {
        const card = node('article', undefined, 'panel');
        card.append(node('h3', `Order #${order.id} · ${order.status_label}`), node('p', `Total: ${money(order.total_amount)} · ${new Date(order.created_at).toLocaleString()}`));
        if (order.customer) card.append(node('p', `${order.customer.username} · ${order.customer.email}`));
        const lines = node('ul');
        for (const item of order.items || []) lines.append(node('li', `${item.product_title} × ${item.quantity} · ${item.line_total}`));
        card.append(lines);
        if (isAdmin && order.status !== 'cancelled') {
            const row = node('div', undefined, 'row');
            for (const status of order.status === 'pending' ? ['completed', 'cancelled'] : ['cancelled']) row.append(action(`Mark ${status}`, async () => {
                await api(`/admin/orders/${order.id}/status`, {method: 'PATCH', body: {status}});
                notice(`Order #${order.id} is ${status}.`); await orders(page, true);
            }, 'secondary'));
            card.append(row);
        }
        return card;
    });
    $(isAdmin ? '#admin-orders' : '#order-list').replaceChildren(...(cards.length ? cards : [node('p', 'No orders yet.')]));
    pagination(isAdmin ? '#admin-order-pages' : '#order-pages', result, next => orders(next, isAdmin));
}
async function notifications(page = 1) {
    const result = await api(`/notifications?page=${page}`);
    $('#notification-list').replaceChildren(...result.data.map(item => {
        const card = node('article', undefined, 'panel');
        card.append(node('p', item.message));
        if (!item.read_at) card.append(action('Mark as read', async () => { await api(`/notifications/${item.id}/read`, {method: 'PATCH'}); await notifications(page); }, 'secondary'));
        return card;
    }));
    if (!result.data.length) $('#notification-list').append(node('p', 'You are all caught up.'));
    pagination('#notification-pages', result, notifications);
}
async function categories() {
    const {data} = await api('/admin/categories');
    const select = $('#product-form select'); const selected = select.value;
    select.replaceChildren(...data.map(category => { const option = node('option', category.name); option.value = category.id; return option; }));
    if (data.some(category => String(category.id) === selected)) select.value = selected;
    $('#categories').replaceChildren(...data.map(category => {
        const row = node('div', undefined, 'row');
        row.append(node('span', `${category.name} (${category.products_count ?? 0})`), action('Edit', () => {
            const form = $('#category-form'); form.elements.id.value = category.id; form.elements.name.value = category.name;
        }, 'secondary'), action('Delete', async () => {
            if (!confirm(`Delete category "${category.name}"?`)) return;
            await api(`/admin/categories/${category.id}`, {method: 'DELETE'}); await categories();
        }, 'secondary'));
        return row;
    }));
}
async function adminProducts(page = 1) {
    const result = await api(`/admin/products?page=${page}`);
    $('#admin-products').replaceChildren(...result.data.map(product => {
        const card = productCard(product);
        card.append(action('Edit product', async () => {
            const {data} = await api(`/admin/products/${product.id}/edit`);
            const form = $('#product-form'); form.reset();
            for (const field of ['id', 'title', 'description', 'price', 'stock_quantity']) form.elements[field].value = data.product[field] ?? '';
            form.elements.category_id.value = data.product.category.id;
            $('#product-heading').textContent = `Edit product #${product.id}`;
            form.scrollIntoView({behavior: 'smooth'}); form.elements.title.focus();
        }, 'secondary'), action('Delete product', async () => {
            if (!confirm(`Delete product "${product.title}"?`)) return;
            await api(`/admin/products/${product.id}`, {method: 'DELETE'}); await adminProducts(page);
        }, 'danger'));
        return card;
    }));
    pagination('#admin-product-pages', result, adminProducts);
}
async function admin() {
    const {data} = await api('/admin/dashboard');
    $('#dashboard').textContent = `${data.total_products} products · ${data.total_orders} orders · Completed sales: ${data.total_sales} ${data.currency} · ${data.stock_units ?? 'Unknown'} stock units`;
    await categories(); await adminProducts(); await orders(1, true);
}
function submit(selector, callback) {
    $(selector).addEventListener('submit', event => {
        event.preventDefault(); run(() => callback(event.currentTarget), event.submitter);
    });
}
for (const element of document.querySelectorAll('[data-view]')) element.addEventListener('click', () => run(() => show(element.dataset.view)));
submit('#filters', () => catalog());
for (const endpoint of ['login', 'register']) submit(`#${endpoint}`, async form => {
    const result = await api(`/${endpoint}`, {method: 'POST', body: Object.fromEntries(new FormData(form))});
    setToken(result.token); user = result.data; updateIdentity(); form.reset();
    notice(`Welcome, ${user.username}.`); await show(user.role === 'admin' ? 'admin' : 'catalog');
});
$('#logout').addEventListener('click', () => run(async () => {
    await api('/logout', {method: 'POST'}); setToken(null); user = null; updateIdentity(); await show('catalog'); notice('Signed out.');
}));
$('#read-all').addEventListener('click', () => run(async () => { await api('/notifications/read-all', {method: 'POST'}); await notifications(); }));
submit('#category-form', async form => {
    const id = form.elements.id.value;
    await api(`/admin/categories${id ? `/${id}` : ''}`, {method: id ? 'PUT' : 'POST', body: {name: form.elements.name.value}});
    form.reset(); form.elements.id.value = ''; await categories(); notice('Category saved.');
});
submit('#product-form', async form => {
    const data = new FormData(form); const id = data.get('id'); data.delete('id');
    if (!data.get('image').size) data.delete('image');
    if (id) data.set('_method', 'PUT');
    await api(`/admin/products${id ? `/${id}` : ''}`, {method: 'POST', body: data});
    form.reset(); form.elements.id.value = ''; await adminProducts(); notice('Product saved.');
});
$('#product-form').addEventListener('reset', () => { $('#product-form').elements.id.value = ''; $('#product-heading').textContent = 'Add a product'; });
$('#category-form').addEventListener('reset', () => { $('#category-form').elements.id.value = ''; });
await run(async () => {
    if (token) { try { user = (await api('/me')).data; } catch (error) { setToken(null); notice(error.message, true); } }
    updateIdentity(); await show(currentView);
});
