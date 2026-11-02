# Stockflow

Stockflow adds a commerce-operations area to Pindrop for inventory, products,
orders, purchasing, customers, and store preferences.

## Enable and create the schema

With the database configured and the terminal-command plugin available:

1. Run `./pindro plugin:install stockflow`.
2. Run `./pindro plugin:enable stockflow`.
3. Run `./pindro db:schema:create` and select **ALL** to create the core and
   enabled-plugin schemas. The Stockflow SQL file contains multiple tables;
   the schema handler skips tables that already exist.
4. Open `/stockflow`. The catalog list is at `/stockflow/products`; use
   **Manage catalog** to open the product-creation wizard, which includes
   category selection or creation and first-variant setup.
   Catalog records can be edited or deleted from that list. Use **Variants**
   to manage a product's variants; adding a variant opens its own form page.
   Inventory adjustments, customer and supplier creation, purchase-order
   creation, and receiving each have their own form page instead of being
   embedded in a list.
   Supplier records can be edited, including their active status. Submitted
   purchase orders can be edited until stock has been received. Suppliers with
   an email address receive a notice when a purchase order is created or
   updated and when stock is received; a notification failure is reported
   separately and does not undo the saved operation.
   Shipping methods are configured at `/stockflow/shipping`. Payment providers
   discovered from `payments.yml` are configured at `/stockflow/payment-methods`.
   Taxes and discounts are configured at `/stockflow/taxes` and
   `/stockflow/discounts`. Each rule can target one product, all product lines,
   or the order subtotal; the manual-order wizard offers each rule only in its
   configured scope. Discounts can also have inclusive start/end dates and
   minimum/maximum whole-order subtotal, total quantity, and distinct-product
   requirements. A qualifying product-scoped discount still applies only to
   its configured product.
   Admins can create manual orders from `/stockflow/orders/create` through
   customer, cart items, billing, shipping, payment, and review steps. New
   customers entered during this workflow are stored as guest customer profiles.
   The payment step lists enabled gateways as radio choices and displays the
   selected gateway's configured payment fields before the final review and
   submission. Orders can be viewed, edited while pending and unpaid, and
   deleted only while pending and without an unresolved gateway attempt. Editing
   recalculates the order from current product prices and adjusts inventory
   reservations transactionally.
   Admins can send signed payment-request links that expire after seven days.
   Customers can complete payment through configured gateways on the public
   checkout page. Configure `STOCKFLOW_PAYMENT_LINK_SECRET` with a strong,
   unique server-side secret; if omitted, the payment-link signer uses
   `CSRF_TOKEN_SECRET`. A payment attempt with an uncertain gateway response
   remains pending and must be reconciled before another attempt is accepted.
   Payment records are listed at `/stockflow/payments`. Gateway records are
   read-only except for the explicit reconciliation action, which requires an
   administrator to verify the captured transaction in the provider dashboard.
   Pending manual/offline payment records can be edited or deleted; confirming
   receipt records the full balance and decrements reserved stock.

The Stockflow database schema is defined in `mysql/ecommerce-schema.sql`.

## Implementation notes

- Form definitions are separated into `src/Form/StockflowFormFactory.php` and
  rendered using `simp/multiform`; product creation uses the package's
  session-backed icon-grid multi-step form, including category selection or
  creation and an image upload on the final step. Product images are limited to
  JPEG, PNG, WebP, or GIF files up to 5 MB and stored in Pindrop's public
  filesystem. Page templates only receive rendered form output, and Stockflow
  validates submitted values server-side before writing them.
- Monetary amounts are represented and calculated with `simp/price`.
  Purchase-order line totals use `Price::multiply()`, and order adjustments
  stored in `orders.order_adjustments` are applied with `simp/adjustment`.
- `Price` initializes an exchange-rate convertor. It uses its local rate cache
  and may request a fresh rate list when that cache is absent or out of date.
  Stockflow does not perform currency conversion.
- The supplied schema stores inventory per variant, without locations.
  Stockflow therefore does not present location-specific stock counts.
- Product and variant editing uses Multiform-rendered forms. Products cannot
  be deleted if any variant has inventory movements, cart items, purchase-order
  items, or order items; variants cannot be deleted while referenced by those
  records, and a product must retain at least one variant. This prevents
  deletion from silently removing stock or transaction history.
- Purchase-order receiving supports partial receipts; each receive action
  validates the remaining quantity and updates inventory through the movement
  ledger.
- Purchase-order notifications use the configured mail manager and include
  the order lines, quantities, unit costs, subtotal, and receipt quantity when
  applicable. Suppliers without an email address are logged and surfaced as
  a message in the admin UI.
- Shipping methods support flat-rate, price-based/order-total range, and
  weight-based calculations, plus an optional free-shipping subtotal
  threshold. Weight-based calculation accepts total grams, and adds a
  per-kilogram amount to its base fee. Configuration is stored in site
  settings; `ShippingMethodService::calculate()` returns the shipping charge
  as a `Price` instance for use by a checkout integration.
- Payment providers are declared in `payments.yml`, including their gateway
class, supported currencies, and admin configuration fields. The manual
order payment step labels Authorize.Net as **Credit card** and tokenizes
card details with Accept.js in the browser; configure the API Login ID,
Transaction Key, Accept.js Client Key, and environment before using it.
Authorize.Net uses its sandbox/production transaction API and opaque payment data;
  Airtel Money uses the Airtel Africa collection API for Malawi (MW/MWK).
  The Airtel merchant code `9H7TE2NI` is included as the initial configuration
  value. Provider API credentials are encrypted with Sodium before storage.
  Configure `PAYMENT_CONFIG_ENCRYPTION_KEY` in the server environment before
  saving credentials; generate a strong random value, keep it outside source
  control, and retain a protected backup because existing credentials cannot
  be decrypted after the key is changed.
- `PaymentMethodRegistry::charge()` is the callable adapter entry point for
  order integrations. Authorize.Net expects `order_number`,
  `opaque_data_descriptor`, and `opaque_data_value` in payment data. Airtel
  Money expects `phone` and a unique `transaction_id`. The method returns a
  provider status, provider transaction ID, message, and raw provider response.
  Do not log raw provider responses. Provider callback/webhook routes and
  automatic settlement notifications are not included; admins must reconcile
  captured transactions against the gateway dashboard.
- The customer payment-request page is a secure gateway checkout for existing
  pending orders. It does not expose order data without a valid signed,
  expiring link. A pending gateway attempt blocks another charge until resolved.
  The purchasing workflow currently creates one line item per purchase order
  and supports partial or complete receiving for that line.
- Manual orders use catalog prices, reserve available inventory while the
  payment is pending, and decrement on-hand stock with a sale movement when
  payment is confirmed. A failed manual-order charge releases its reservation.
  Payment-link attempts retain reservations after a decline so the customer can
  retry; uncertain or pending attempts require reconciliation.
- Customer addresses, shipment fulfillment, coupons, reviews, and team
  invitations are not exposed as workflows in this initial module.
