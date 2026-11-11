<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="NovaCart shop and order management">
    <title>NovaCart · Shop</title>
    <link rel="stylesheet" href="/assets/shop.css">
    <script type="module" src="/assets/shop.js"></script>
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<header class="site-header">
    <a class="brand" href="#/shop">NovaCart<span>Everyday essentials.</span></a>
    <nav aria-label="Main navigation">
        <button data-view="catalog">Shop</button>
        <button data-view="cart" data-auth hidden>Cart <span id="cart-count" class="count">0</span></button>
        <button data-view="orders" data-auth hidden>Your orders</button>
        <button data-view="notifications" data-auth hidden>Notifications</button>
        <button data-view="admin" data-admin hidden>Manage shop</button>
        <button data-view="account" id="account-link">Sign in</button>
        <button id="logout" data-auth hidden>Sign out</button>
    </nav>
</header>
<main id="main" tabindex="-1">
    <p id="loading" class="loading" role="status" hidden>Loading…</p>
    <section id="catalog" class="view">
        <div class="intro"><p class="eyebrow">THE NOVACART COLLECTION</p><h1>Find your everyday favorite.</h1><p>Explore the shop. Open a product to see its details and choose a quantity.</p></div>
        <form id="filters" class="toolbar" role="search">
            <label>Search products<input name="q" type="search" placeholder="What are you looking for?"></label>
            <label>Category<select name="category"><option value="">All categories</option></select></label>
            <button>Search</button><button type="button" id="clear-filters" class="secondary">Clear filters</button>
        </form>
        <p id="product-results" class="muted" aria-live="polite"></p>
        <div id="products" class="grid"></div><div id="catalog-pages" class="pagination"></div>
    </section>
    <section id="product" class="view" hidden><div id="product-detail"></div></section>
    <section id="account" class="view narrow" hidden>
        <h1>Your account</h1><p id="identity"></p>
        <div id="auth-forms" class="panel">
            <div class="tabs" aria-label="Account options"><button type="button" data-auth-tab="login" aria-pressed="true">Sign in</button><button type="button" data-auth-tab="register" aria-pressed="false">Create an account</button></div>
            <form id="login"><h2>Welcome back</h2><label>Email<input name="email" type="email" required autocomplete="username"></label><label>Password<input name="password" type="password" required autocomplete="current-password"></label><p class="form-error" data-errors role="alert" tabindex="-1" hidden></p><button>Sign in</button></form>
            <form id="register" hidden><h2>Create your account</h2><label>Username<input name="username" required maxlength="50" autocomplete="username"></label><label>Email<input name="email" type="email" required autocomplete="email"></label><label>Password<input name="password" type="password" minlength="8" required autocomplete="new-password"></label><label>Confirm password<input name="password_confirmation" type="password" minlength="8" required autocomplete="new-password"></label><p class="form-error" data-errors role="alert" tabindex="-1" hidden></p><button>Create account</button></form>
        </div>
        <div id="account-actions" class="row" hidden><button data-view="orders">Your orders</button><button data-view="catalog" class="secondary">Continue shopping</button></div>
    </section>
    <section id="cart" class="view" hidden><div class="section-heading"><div><p class="eyebrow">CHECKOUT</p><h1>Your cart</h1></div><button data-view="catalog" class="secondary">Continue shopping</button></div><div id="cart-content"></div></section>
    <section id="orders" class="view" hidden><h1>Your orders</h1><p>Check your items and follow each order’s status.</p><div id="order-list"></div><div id="order-pages" class="pagination"></div></section>
    <section id="notifications" class="view" hidden><div class="section-heading"><h1>Notifications</h1><button id="read-all" class="secondary">Mark all as read</button></div><div id="notification-list"></div><div id="notification-pages" class="pagination"></div></section>
    <section id="admin" class="view" hidden>
        <div class="section-heading"><div><p class="eyebrow">ADMINISTRATION</p><h1>Manage your shop</h1></div><button data-view="catalog" class="secondary">View storefront</button></div>
        <nav class="tabs" aria-label="Shop management"><button data-admin-tab="overview">Overview</button><button data-admin-tab="products">Products</button><button data-admin-tab="categories">Categories</button><button data-admin-tab="orders">Orders</button></nav>
        <section id="admin-overview" class="admin-panel"><h2>Shop overview</h2><div id="dashboard" class="stats"></div></section>
        <section id="admin-product-panel" class="admin-panel" hidden><div class="section-heading"><h2>Products</h2><button id="new-product">Add product</button></div><div id="admin-products" class="grid"></div><div id="admin-product-pages" class="pagination"></div></section>
        <section id="admin-category-panel" class="admin-panel" hidden><h2>Categories</h2><div class="category-layout"><form id="category-form" class="panel"><h3 id="category-heading">Add a category</h3><input name="id" type="hidden"><label>Name<input name="name" required maxlength="100"></label><p class="form-error" data-errors role="alert" tabindex="-1" hidden></p><button>Save category</button><button type="reset" class="secondary">Cancel / new category</button></form><div id="categories" class="panel"></div></div></section>
        <section id="admin-order-panel" class="admin-panel" hidden><h2>Customer orders</h2><div id="admin-orders"></div><div id="admin-order-pages" class="pagination"></div></section>
    </section>
    <section id="page-error" class="view" hidden><h1>Something went wrong</h1><p id="page-error-message"></p><div class="row"><button id="retry-page">Try again</button><button data-view="catalog" class="secondary">Back to shop</button></div></section>
</main>
<dialog id="product-editor" aria-labelledby="product-heading">
    <div class="section-heading"><h2 id="product-heading">Add a product</h2><button type="button" data-close="product-editor" class="icon-button" aria-label="Close product editor">×</button></div>
    <form id="product-form"><input name="id" type="hidden"><label>Title<input name="title" maxlength="255" required></label><label>Description<textarea name="description" maxlength="5000" required></textarea></label><div class="form-columns"><label>Price (USD)<input name="price" type="number" min="0.01" max="99999999.99" step="0.01" required></label><label>Stock quantity<input name="stock_quantity" type="number" min="0" max="1000000" required></label></div><label>Category<select name="category_id" required></select></label><label>Image<input name="image" type="file" accept="image/jpeg,image/png,image/webp"><span class="hint">JPG, PNG or WebP, up to 5 MiB. Leave empty when editing to keep the current image.</span></label><p class="form-error" data-errors role="alert" tabindex="-1" hidden></p><div class="row"><button>Save product</button><button type="button" data-close="product-editor" class="secondary">Cancel</button></div></form>
</dialog>
<div id="notice" class="toast" role="status" aria-live="polite" hidden><div><span id="notice-text"></span><div id="notice-actions"></div></div><button id="dismiss-notice" class="icon-button" aria-label="Dismiss message">×</button></div>
<footer>NovaCart · Thoughtfully simple shopping</footer>
</body>
</html>
