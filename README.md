# NeuroCheckout Connector for Magento

## Customer identity in Community

Native quote snapshots preserve both `customer_id` and `customer_is_guest`.
Cloud requires a positive customer ID and an explicit non-guest flag before
classifying a cart as registered. Missing or ambiguous values remain guests;
an email address alone never overrides the merchant's guest-recovery policy.
Regression coverage includes registered quotes and guests with customer IDs.

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

Installable packages are published on the repository's
[Releases page](https://github.com/pisob/neurocheckout-connector-magento/releases).
If no release is listed, the repository contains development sources only and
should not be installed by store operators.

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
