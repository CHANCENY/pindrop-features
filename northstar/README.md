# Northstar storefront

Northstar is the public storefront for products managed by Stockflow. It uses
Stockflow's catalog, inventory, shipping, tax, discount, payment, and order
services rather than maintaining a second commerce database.

The storefront is available at `/shop`; product listings, product details,
the session cart, checkout, order confirmation, and signed-in order history
are served by the Northstar plugin. Checkout reviews the full order total
before accepting confirmation. Enabled Stockflow payment methods are used,
and Authorize.Net card details are tokenized with Accept.js before they reach
the application.

Northstar depends on the Stockflow plugin. Customer and authenticated user
roles receive only the Stockflow database operations needed to browse products
and place orders. The visual starting point remains in `storefront-static/`.
