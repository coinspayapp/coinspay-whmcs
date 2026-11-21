# CoinsPay for WHMCS — installation

The module lets clients pay WHMCS invoices in cryptocurrency through CoinsPay. The client presses
"Pay Now" on the invoice, picks a coin and a network on the CoinsPay payment page, and the
payment appears on the invoice by itself. WHMCS then does what it always does: emails, renewals,
provisioning.

You need WHMCS 7.0 or newer and a CoinsPay account with a shop. The API key and the webhook are
set up in the CoinsPay dashboard.

## Installation

1. Copy the contents of the `upload/` folder of the archive into the WHMCS root (where
   `configuration.php` is). This adds:
   - `modules/gateways/coinspay.php` and the folder `modules/gateways/coinspay/`;
   - `modules/gateways/callback/coinspay.php` — the address of the webhook, of the client's
     return and of the "Pay Now" button;
   - `includes/hooks/coinspay.php` — reacts to cancelled and changed invoices, polls on cron.
2. In the WHMCS admin area open the payment gateways: "Setup → Payments → Payment Gateways", in
   WHMCS 8 and newer "Configuration → System Settings → Payment Gateways". Find CoinsPay under
   "All Payment Gateways" and activate it.

## Settings

1. In the CoinsPay dashboard open the shop:
   - under "API keys" issue a key with "Invoices only" access and paste it into "API key". The
     module needs no more, and such a key cannot withdraw money;
   - leave "API address" empty: the module then uses `https://coinspay.app`;
   - under "Webhook" enter `https://<your WHMCS>/modules/gateways/callback/coinspay.php`, save,
     and copy the `whsec_…` secret into "Webhook secret".
2. Save the gateway settings and open "Check the connection" in the description of the secret
   field. The page shows the shop, the key mode, which WHMCS currencies CoinsPay accepts and
   whether the test event came. Press "Send test event" in the CoinsPay dashboard and reload it.
3. If CoinsPay does not accept the currency of your clients, set "Convert To For Processing" to
   a currency it accepts, USD for instance. The client pays the amount in that currency, and the
   invoice gets its balance in the client's currency.
4. Make a test payment before taking real ones: see "First payment: a test one" below.

Other settings:

| Field | Default | What it does |
|---|---|---|
| Button text | empty | text of the payment button, for example "Pay with crypto"; empty: the WHMCS "Pay Now" in the client language |
| Invoice lifetime | 60 | minutes the client has to pay, from 15 to 1440 |
| Return automatically | yes | after the payment the CoinsPay page takes the client back to the invoice |
| Stablecoins only | no | the client pays in USDT, USDC and other stablecoins only |
| Client pays the CoinsPay fee / network fee | no | the fees are added to the client's amount |
| Test key for admins only | yes | with a test key only a signed-in admin sees the button |
| Add test payments | no | whether test payments of an admin are added, see below |
| Debug log | no | every step of the module goes to the gateway log |

## Test mode

With a `cpk_test_` key invoices are paid with a button on the payment page, no coins move. Sign
in to the admin area and to the client area in one browser: only an admin sees "Pay Now".

By default a test payment is not added to the invoice: the module only writes it to the gateway
log. An added payment is no different from a real one for WHMCS: revenue, emails to the client,
provisioning. Turn "Add test payments" on only on a test copy of WHMCS, or for an invoice made
for the test below.

## First payment: a test one

Every WHMCS is set up its own way: its address, web server rules, cron, currencies, other
modules. Pay one invoice in test mode first. It takes five minutes and goes the whole way a real
payment goes, with no money moving.

1. In the CoinsPay dashboard create a key with "Invoices only" access and tick "Test key": it
   starts with `cpk_test_`. Paste it into "API key", turn on "Add test payments" and "Debug log",
   and save.
2. In the WHMCS admin area create a test client, or take your own client account. On its profile
   press "Create Invoice" and add one custom line item for any small amount, with CoinsPay as the
   payment method. A custom line item has no product, so nothing gets provisioned.
3. On the client's profile press "Login as Client", open the invoice and press "Pay Now". On the
   CoinsPay payment page, marked "Test invoice", pick a coin and press "Pay".
4. Back on the invoice, it should turn Paid within a minute, and the gateway log
   ("Billing → Gateway Log") should show the CoinsPay entries.
5. Clean up: delete the test payment on the Transactions tab of the invoice, or the whole
   invoice, turn off "Add test payments" and "Debug log", and replace the test key with a live
   one (without "Test key").

If the invoice does not turn Paid, send the gateway log entries and the result of "Check the
connection" to CoinsPay support: https://t.me/coinspayapp or support@coinspay.app.

## If webhooks do not arrive

The module asks CoinsPay about open invoices on every run of the WHMCS cron, and when the client
opens the invoice, at most once a minute. The WHMCS cron is usually set up already: WHMCS needs
it for invoices and emails.

## Good to know

- The balance of the invoice at the time of "Pay Now" is what gets added. If the invoice total
  changes later, or the invoice is cancelled, paid another way or moved to another payment
  method, the module cancels the open CoinsPay invoice. The next "Pay Now" makes a new one for
  the new amount.
- A payment for an invoice that can no longer be closed (cancelled, paid another way, the client
  currency changed) is not added. The WHMCS activity log gets a "CoinsPay: …" entry and the admin
  page of the invoice shows a warning. Add the payment by hand or refund the client from the
  CoinsPay dashboard.
- The client paid more: the balance of the invoice is added, and the excess gets an activity log
  entry and a warning on the admin page of the invoice. Refund it from the CoinsPay dashboard or
  keep it.
- The client paid less and the CoinsPay invoice expired: the payment is not added, with the same
  warning as above.
- Removal: deactivate the gateway and delete the files of the module, `includes/hooks/coinspay.php`
  included. The tables `mod_coinspay_invoices` and `mod_coinspay_kv` stay in the database; drop
  them by hand if you like.
