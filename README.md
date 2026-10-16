# NeuroCheckout Connector for Magento

## Product details in Community emails

### Native recommendation consent (candidate, not released)

Local snapshots expose the native newsletter subscription only for an identified
customer with matching current email, website and exact store view. Only a
confirmed subscription grants permission; pending, inactive and unsubscribed
states do not. Missing/deactivated newsletter support, duplicate subscriptions,
foreign scopes and changed emails remain unknown. No customers are subscribed
automatically, and this exporter never changes a newsletter record. The optional
newsletter lookup cannot break normal cart synchronization when unavailable.
Other store-view/global-customer consent is deliberately not inherited.

Cloud must recheck this state plus unsubscribe/suppression and all sending rules
before a recommendation email. This source change does not activate campaigns.
Disposable native SQL tests cover these boundaries and retain checkout/snapshot
regressions; real recommendation delivery remains a separate acceptance test.

### Website stock evidence (candidate, not released)

Snapshots now use Magento's native MSI website stock resolver and both salability
and requested-quantity checks for one unit. This includes reservations and native
backorder policy; a legacy/global quantity or indexed in-stock flag alone does
not suffice. Evidence is bound to the exact store view, website and stock.
The current Magento request store must also match before, between and after the
native calls, since stock configuration can depend on that context.
Only enabled, individually visible simple, virtual and downloadable products are
checked. Composite/custom product types remain unknown until their option-aware
selection and pricing are supported. This is inventory evidence, not a guarantee
that every customer can check out with the item.

Optional or disabled MSI, native failures, foreign/duplicate/incomplete responses
and expired reads leave inventory unknown without breaking normal cart records.
Checks use at most 128 products per batch and a shared 1.5-second cooperative
budget. A late native response is discarded; a synchronous service cannot be
preempted. The existing five-second capture limit is unchanged. Freshness stays
in the signed source envelope; timestamps are not added to product payloads, so
unchanged stock does not cause perpetual resynchronization.

This does **not** yet export a recipient/tax-aware final display price or activate
recommendation campaigns. Raw EAV prices must not be advertised as final prices.
Automated coverage includes native-service contract tests and disposable SQL
factory/snapshot tests in CI. Local native read-only checks do not replace the
remaining real recommendation-email and checkout acceptance tests.

### Recipient-specific prices (candidate reader, not connected to delivery)

`CustomerProductPrices` reads Magento's native one-unit final price for the
identified customer, selected website/store view and explicitly requested
currency. It uses that customer's group, native group/tier/special/catalog-rule
pricing, native currency conversion and tax calculation with their own default
address. It does not impersonate a customer session or create a checkout quote.
The product model is reloaded and cloned so another customer's cached price is
not reused. Tax-inclusive, tax-exclusive and dual display are distinct; dual
display has no unlabeled single-price fallback. Prices exclude cart coupons,
shipping and optional customizations; they are not checkout totals or guarantees.

Only enabled, individually visible simple/virtual products without required
options are supported. Missing customer/address/tax/currency context, price
restrictions, fixed product taxes and unsupported B2B pricing fail closed.
Enabling Magento's minimum-advertised-price feature alone does not exclude every
product: the native configured minimum is compared to the recipient's final
price. Composite/downloadable/option-aware pricing still needs separate support.
Up to 16 exact product IDs can be read within a shared three-second cooperative
budget; late responses or current-store changes invalidate the whole result.
Synchronous native calls cannot be preempted. Individual product failures remain
explicitly unavailable, not a fabricated price.

The candidate exposes this read through the existing authenticated
`POST /neurocheckout/community/pull` source route (`customer_prices` operation),
with a matching Community client. The request binds the shop, expected native
store/website, customer ID/email, currency and product IDs. Magento's current
store is authoritative; JSON cannot select another store. Existing HMAC, HTTPS,
durable nonce and rate guards remain. The response binds the exact request hash,
has a 15-second freshness window and a 16 KiB limit. Errors are unavailable, not
empty successful reads. Source pagination remains compatible.

The result is transient, customer-bound data: do not log it, persist it as a
global product price or reuse it for another recipient. Addresses are never
returned. This is **not connected to a recommendation sender**: recipient-bound
selection and final pre-send rechecks remain required. Installing this candidate
alone does not activate independent recommendation emails.

Verification: 287 isolated recipient-price assertions, the full connector
validator, and read-only checks on the local native Magento installation.
Three products matched native final-price/tax services with repeatable results;
two mandatory-option products and foreign-email/unsupported-currency requests
were refused. Native checks ran in a read-only SQL transaction without changing
installed source, customer preferences, orders or sending email. These checks
do not certify third-party pricing extensions or a complete recommendation flow.
The transport additionally passes 44 synthetic protocol assertions. A read-only
native PHP/Community bridge verified two signed three-product reads, replay
refusal and controller construction with real Magento services. It did not
exercise a deployed HTTPS route or change the installed module.

### Native account order history (candidate, not released)

The signed source route also supports `customer_history` for an exact native
store/website/customer ID and current account email. `CustomerOrderHistory` reads
native sales tables directly, including orders whose original quote was removed.
Other store views, other accounts and guest orders sharing an email are excluded.
The account must be active and confirmed. A dedicated repeatable-read READ ONLY
transaction keeps orders, lines, account and optional newsletter state consistent.
It never modifies customers, orders, subscriptions or quotes.

Native state/status, currency, paid/refunded amounts and line parent relations
are returned as facts. Unknown paid amounts remain null; creation of an order is
not proof of payment. No address, customer name or payment details are returned.
Missing/disabled/ambiguous newsletter data remains unknown, never consent.
Limits are 128 orders, 512 lines, 64,000 bytes of history and a three-second
cooperative read budget. Required tables must support consistent transactions.
Missing tables, drift, malformed data and overflow fail without partial success.
HMAC, durable nonce/rate checks, request hash and 15-second freshness protect the
response; existing source pagination is unchanged.

The SQL/protocol suite includes 61 history assertions, concurrent payment updates,
write refusal, foreign scope, duplicate/invalid lines, missing newsletter support
and capacity failures. A read-only candidate bridge matched three local account
orders against Magento's native order repository in two consistent signed reads,
and refused replay. No installed module, consent or order was changed, and no
email was sent. This is not live HTTPS or end-to-end recommendation acceptance.

Product snapshots include image and product URLs resolved for the selected store
view. Images use Magento's configured media base (including a media CDN) and the
actual product image path. Product IDs are never used as image identifiers.
No image is invented for a product without an assigned image. Update in place,
run Magento's normal upgrade/compilation steps, and let Community synchronize
the new snapshot before testing a new email. Previously sent emails are unchanged.

This is the official open-source Magento connector for NeuroCheckout. It sends
authenticated store events to NeuroCheckout Cloud and supports signed,
read-only product and cart snapshots for the encrypted local vault in
NeuroCheckout Community.

The snapshot exporter reads product attributes in bounded batches and
supports up to 8,192 combined product/cart records per snapshot. It fails explicitly
above this limit rather than silently omitting records. The first synchronization
is paginated in eight-record responses; a large catalogue or slow tunnel can take
time to finish. Store-scoped data and database consistency checks remain enforced.

## Availability

The current installable connector is **v1.0.8**:

- [Download the installable ZIP](https://github.com/pisob/neurocheckout-connector-magento/releases/download/v1.0.8/neurocheckout-connector-magento-1.0.8.zip).
- [Official release and verification files](https://github.com/pisob/neurocheckout-connector-magento/releases/tag/v1.0.8).

Do not use **Code → Download ZIP** as an installation package. Official packages
include version information, checksums and signatures needed to verify their
origin and integrity.

## Install an official package

1. Back up the Magento files and database.
2. Download the connector package and all verification files from the same
   official release.
3. Verify the documented signing-key fingerprint, detached signature and
   SHA-256 checksum.
4. Install the package using the method documented in that release.
5. Run Magento's required module upgrade and cache refresh commands.
6. Open the connector configuration and enter the API endpoint,
   store-specific connector key and external store ID displayed in your
   NeuroCheckout account.
7. Save the configuration and run the API connection test.
8. Keep NeuroCheckout Community online when using encrypted local product and
   cart storage.

Use the **NeuroCheckout API endpoint** supplied by your account, not your shop
URL or its ngrok tunnel. The connection test validates an authenticated API
response; an HTTP 200 HTML page is not a successful API connection.

### Background synchronization

API validation authorizes synchronization but does not install a scheduler.
The module includes a traffic-triggered fallback in automatic mode on PHP-FPM:
after a Magento web response has finished, it processes at most one record per
queue for the current store. It requires a successful, current API test and valid
configuration, respects the configured interval (minimum 60 seconds), and shares
the normal executor lock. Failures stay subject to the existing queue retry rules.
No public cron URL, browser timer or ngrok loopback is required by this fallback.

This fallback is included in both new installations and module updates; no
computer-specific service is needed. It is **not a no-traffic scheduler**:
full-page-cache hits, requests not reaching Magento, and non-FPM servers do not
trigger it. Keep the standard Magento cron for reliable delivery without visits.

In automatic mode, ensure Magento's system cron is installed and running as the
Magento filesystem owner (`php bin/magento cron:install`). In manual server-cron
mode, schedule the connector command shown in the Execution tab:
`php bin/magento neurocheckout:connector:cron-run --store-id=YOUR_STORE_ID`.
Use the interval shown there and the correct Magento working directory. Choose
one scheduling mode, and do not use `--force` for routine execution.

After validation, check that **Last execution** advances and pending cart and
Journey events drain. The General tab warns when no recent connector run has
been detected; allow the initial scheduling grace period before diagnosing a
new installation. Updating the module does not configure operating-system cron.

Never publish connector keys, customer records, cart contents or configuration
exports in an issue or pull request. Back up the store before uninstalling or
upgrading the module.

## Updates

The daily Magento cron sends the installed connector version through the
existing authenticated Cloud connection. When an update is available, the
connector monitoring panel links to the exact official GitHub release. The
Cloud cannot download or install code on the Magento server.

Back up the store and update the existing Composer package in place. Then run
Magento's module upgrade, dependency compilation and cache-cleaning commands.
Do not disable or remove the module first: the in-place process preserves its
configuration and database tables.

## Development

The Magento module source is located in `app/code/NeuroCheckout/Connector/`.

Recovery validation includes isolated controller scenarios for single-use token
claims, expiry and customer/cart validation. A claim must succeed before any
customer session or quote mutation. These tests do not replace a full checkout
and Cloud attribution test on a supported Magento installation.

```bash
python3 tools/validate.py
```

Automated checks use synthetic data and do not replace platform-level tests for
installation, upgrades, checkout events, key rotation and uninstallation.

## Contributions and releases

Submit changes through pull requests. Protected branches require automated
validation and maintainer review. External contributions cannot publish official
releases or access NeuroCheckout credentials.

Official releases are created from reviewed commits and include a signed tag,
SHA-256 checksums and a detached signature. See
[CONTRIBUTING.md](CONTRIBUTING.md), [RELEASING.md](RELEASING.md) and
[SECURITY.md](SECURITY.md).

## License and trademark

The connector source is licensed under Apache License 2.0. The NeuroCheckout
name and logos remain protected. Modified distributions must not claim to be
official NeuroCheckout releases.
