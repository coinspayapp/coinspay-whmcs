# CoinsPay for WHMCS

Let your clients pay WHMCS invoices in cryptocurrency. The client pays on the CoinsPay payment page, the payment is added to the invoice automatically, and the money goes on to your own wallets.

## Requirements

WHMCS 7.0 or newer with PHP 5.6 to 8.5, and a [CoinsPay](https://coinspay.app) account with a shop.

## Description

The client presses "Pay Now" on an invoice, picks a coin and a network on the CoinsPay payment page and pays. When the payment is confirmed, the module adds it to the invoice, and WHMCS carries on as usual with emails, renewals and provisioning.

The amount is the invoice balance at the moment the client presses "Pay Now". CoinsPay converts it at the current rate and holds the coin amount for 30 minutes.

### Coins and networks

More than 30 coins and tokens on TRON, Ethereum, BNB Smart Chain, Solana, Bitcoin and Litecoin, among them USDT, USDC, BTC, ETH, BNB, TRX, LTC and SOL. The full list is on [coinspay.app/coins](https://coinspay.app/coins). You can limit the payment page to stablecoins.

### What the module takes care of

- Each payment is added to the invoice exactly once, even when the webhook, the client's return and the cron arrive at the same moment.
- If the invoice total changes, or the invoice is cancelled, paid another way or moved to another gateway, the module cancels the open CoinsPay invoice. The next "Pay Now" creates one for the new amount.
- A payment that can no longer close its invoice is not added. The WHMCS activity log gets a "CoinsPay: …" entry, and the admin page of the invoice shows a warning.
- An overpayment adds the invoice balance and leaves a warning about the excess.
- Webhooks are signed and checked. The module also asks CoinsPay about open invoices on every WHMCS cron run and when a client opens an invoice.
- English and Russian.

### Why CoinsPay

Payments go straight to your wallets. Each network takes up to five payout addresses with percentage shares, for example 70% to you and 30% to a partner, and nothing is kept on the platform.

The fee is as low as 0.3% of each payout: the rate goes down as the monthly turnover of all your shops grows. There is no monthly or setup fee. For the first 10 days after your first payment, payouts up to $500 carry no fee. You can let the client pay the CoinsPay fee and the network fee on top of the invoice. Details are on the [pricing page](https://coinspay.app/pricing).

## Installation

1. Get the archive `coinspay-whmcs-<version>.zip` from the releases of this repository, or build it yourself (see [Development](#development)).
2. Copy the contents of its `upload/` folder into the WHMCS root, where `configuration.php` is. This adds:

   ```
   modules/gateways/coinspay.php
   modules/gateways/coinspay/
   modules/gateways/callback/coinspay.php
   includes/hooks/coinspay.php
   ```

3. In the WHMCS admin area open the payment gateways: Setup → Payments → Payment Gateways, or Configuration → System Settings → Payment Gateways in WHMCS 8 and newer. Find CoinsPay under "All Payment Gateways" and activate it.

The installation guide is also available as a separate document in [English](docs/install-en.md) and [Russian](docs/install-ru.md).

## Configuration

1. In the CoinsPay dashboard open your shop:
   - under "API keys" create a key with "Invoices only" access and paste it into "API key". The module needs nothing more, and such a key cannot withdraw money;
   - leave "API address" empty: the module then uses `https://coinspay.app`;
   - under "Webhook" enter `https://<your WHMCS>/modules/gateways/callback/coinspay.php`, save, and copy the `whsec_…` secret into "Webhook secret".
2. Save the gateway settings and open "Check the connection" in the description of the secret field. The page shows the shop, the key mode, which of your WHMCS currencies CoinsPay accepts and whether the test event came. Press "Send test event" in the CoinsPay dashboard and reload the page.
3. If CoinsPay does not accept your clients' currency, set "Convert To For Processing" to one it accepts, USD for instance. The client pays in that currency, and the invoice gets its balance in the client's currency.
4. Make a test payment before you take real ones, as described in [First payment: a test one](#first-payment-a-test-one).

Other settings:

| Field | Default | What it does |
|---|---|---|
| Button text | Empty | Text of the payment button, for example "Pay with crypto". Empty: the WHMCS "Pay Now" in the client language |
| Invoice lifetime | 60 | Minutes the client has to pay, from 15 to 1440 |
| Return automatically | Yes | After payment the CoinsPay page takes the client back to the invoice |
| Stablecoins only | No | The client can pay in USDT, USDC and other stablecoins only |
| Client pays the CoinsPay fee / network fee | No | The fees are added to the client's amount |
| Test key for admins only | Yes | With a test key, only a signed-in admin sees the button |
| Add test payments | No | Whether an admin's test payments are added to the invoice |
| Debug log | No | Every step of the module goes to the gateway log |

## Test mode

With a test key (`cpk_test_…`) invoices are paid with a button on the payment page and no coins move. Sign in to the admin area and the client area in the same browser: only an admin sees "Pay Now".

By default a test payment is only written to the gateway log. An added test payment is a real payment for WHMCS, with revenue, client emails and provisioning, so turn on "Add test payments" only on a test copy of WHMCS, or for an invoice made for the test below.

### First payment: a test one

Every WHMCS is set up its own way: its URL, web server rules, cron, currencies, other modules. Pay one invoice in test mode first. It takes five minutes and goes through the whole path a real payment takes, with no money moving.

1. In the CoinsPay dashboard create a key with "Invoices only" access and tick "Test key": it starts with `cpk_test_`. Paste it into "API key", turn on "Add test payments" and "Debug log", and save.
2. In the WHMCS admin area create a test client, or take your own client account. On its profile press "Create Invoice" and add one custom line item for any small amount, with CoinsPay as the payment method. A custom line item has no product, so nothing gets provisioned.
3. On the client's profile press "Login as Client", open the invoice and press "Pay Now". On the CoinsPay payment page, marked "Test invoice", pick a coin and press "Pay".
4. Back on the invoice, it should turn Paid within a minute, and the gateway log (Billing → Gateway Log) should show the CoinsPay entries.
5. Clean up: delete the test payment on the Transactions tab of the invoice, or the whole invoice, turn off "Add test payments" and "Debug log", and replace the test key with a live one (without "Test key").

If the invoice does not turn Paid, send the gateway log entries and the result of "Check the connection" to [CoinsPay support](https://t.me/coinspayapp) or support@coinspay.app.

## Removal

Deactivate the gateway and delete the module files, `includes/hooks/coinspay.php` included. The tables `mod_coinspay_invoices` and `mod_coinspay_kv` stay in the database; drop them by hand if you like.

## Development

```
modules/gateways/coinspay.php            gateway metadata, settings and the "Pay Now" button
modules/gateways/callback/coinspay.php   the only public entry point: webhook, pay, return, check
modules/gateways/coinspay/               the library and the en and ru texts
includes/hooks/coinspay.php              WHMCS hooks: invoice changes, cron polling, admin warning
```

All requests come through `callback/coinspay.php`, the one module path WHMCS documents as reachable from the web, so strict nginx or WAF rules do not block the button or the client's return. The database is used through `Capsule::connection()->getPdo()` with prepared statements, which works the same on WHMCS 7.0 to 9.x.

A payment is added with `addInvoicePayment` under a row claim in the module's own table and a check of `tblaccounts`, so two concurrent requests cannot add it twice. The module does not use `checkCbTransID`, because it ends the script.

Checks and the build:

```bash
P=$(pwd)
docker run --rm -v "$P:/app" -w /app composer:2 install
docker run --rm -v "$P:/app" -w /app php:7.4-cli vendor/bin/phpunit
docker run --rm -v "$P:/app" -w /app php:8.5-cli vendor/bin/phpunit
docker run --rm -v "$P:/app" -w /app php:7.4-cli vendor/bin/phpcs -q
python bin/build.py
```

`phpcs` checks PHP 5.6 compatibility. `bin/build.py` writes `dist/coinspay-whmcs-<version>.zip`.

## Changelog

### 0.1.0

- First release.

## License

GPL-2.0-or-later. The full text is in [LICENSE](LICENSE).
