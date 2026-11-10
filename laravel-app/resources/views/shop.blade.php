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
<header><a class="brand" href="/">NovaCart<span>Everyday essentials.</span></a><nav aria-label="Main navigation"><button data-view="catalog">Shop</button><button data-view="cart" data-auth hidden>Cart</button><button data-view="orders" data-auth hidden>Orders</button><button data-view="notifications" data-auth hidden>Notifications</button><button data-view="admin" data-admin hidden>Manage shop</button><button data-view="account" id="account-link">Sign in</button><button id="logout" data-auth hidden>Sign out</button></nav></header>
<main>
    <div id="notice" role="status" aria-live="polite" hidden></div>
    <section id="catalog" class="view"><div class="intro"><p class="eyebrow">THE NOVACART COLLECTION</p><h1>Find your next everyday favorite.</h1><p>Browse the collection, check availability, and track your orders in one place.</p></div><form id="filters" class="toolbar"><label>Search<input name="q" type="search" placeholder="Find a product"></label><label>Category ID<input name="category" type="number" min="1" placeholder="All categories"></label><button>Find products</button></form><div id="products" class="grid"></div><div id="catalog-pages" class="pagination"></div></section>
    <section id="account" class="view" hidden><h1>Your account</h1><p id="identity"></p><div id="auth-forms" class="grid"><form id="login" class="panel"><h2>Welcome back</h2><label>Email<input name="email" type="email" required autocomplete="username"></label><label>Password<input name="password" type="password" required autocomplete="current-password"></label><button>Sign in</button></form><form id="register" class="panel"><h2>Join NovaCart</h2><label>Username<input name="username" required maxlength="50" autocomplete="username"></label><label>Email<input name="email" type="email" required autocomplete="email"></label><label>Password<input name="password" type="password" minlength="8" required autocomplete="new-password"></label><label>Confirm password<input name="password_confirmation" type="password" minlength="8" required autocomplete="new-password"></label><button>Create account</button></form></div></section>
    <section id="cart" class="view" hidden><h1>Your cart</h1><div id="cart-content"></div></section>
    <section id="orders" class="view" hidden><h1>Your orders</h1><div id="order-list"></div><div id="order-pages" class="pagination"></div></section>
    <section id="notifications" class="view" hidden><h1>Notifications</h1><button id="read-all">Mark all as read</button><div id="notification-list"></div><div id="notification-pages" class="pagination"></div></section>
    <section id="admin" class="view" hidden><h1>Manage your shop</h1><div id="dashboard" class="panel"></div><div class="grid"><form id="product-form" class="panel"><h2 id="product-heading">Add a product</h2><input name="id" type="hidden"><label>Title<input name="title" maxlength="255" required></label><label>Description<textarea name="description" maxlength="5000" required></textarea></label><label>Price<input name="price" type="number" min="0.01" max="99999999.99" step="0.01" required></label><label>Stock quantity<input name="stock_quantity" type="number" min="0" max="1000000" required></label><label>Category<select name="category_id" required></select></label><label>Image (JPG, PNG, WebP; up to 5 MiB)<input name="image" type="file" accept="image/jpeg,image/png,image/webp"></label><button>Save product</button><button type="reset" class="secondary">New product</button></form><div class="panel"><h2>Categories</h2><form id="category-form"><input name="id" type="hidden"><label>Name<input name="name" required maxlength="100"></label><button>Save category</button><button type="reset" class="secondary">New category</button></form><div id="categories"></div></div></div><h2>Products</h2><div id="admin-products" class="grid"></div><div id="admin-product-pages" class="pagination"></div><h2>Customer orders</h2><div id="admin-orders"></div><div id="admin-order-pages" class="pagination"></div></section>
</main>
<footer>NovaCart · Thoughtfully simple shopping</footer>
</body>
</html>
