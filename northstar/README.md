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

Storefront customers can create accounts at `/account/register` and sign in
at `/account/login`. Account creation sends the platform's email verification
message; signing in returns customers to checkout when they arrived there
from the cart. Delivery checkout offers saved addresses linked directly to the
user or through their Stockflow customer profile; newly entered order addresses
are saved with both links for reuse and displayed read-only on the account
page. When Authorize.Net is enabled and an order has a pending
payment, customers can view its details from order history. For a pending
Authorize.Net payment, the account page offers a Pay now link when the gateway
is enabled and configured; otherwise it offers an email contact action when a
store sender email is configured.

Northstar depends on the Stockflow plugin. Customer and authenticated user
roles receive only the Stockflow database operations needed to browse products
and place orders. The visual starting point remains in `storefront-static/`.
