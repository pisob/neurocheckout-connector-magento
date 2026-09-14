# NeuroCheckout Connector for Magento

This is the official open-source Magento connector for NeuroCheckout. It sends
authenticated store events to NeuroCheckout Cloud and supports signed,
read-only product and cart snapshots for the encrypted local vault in
NeuroCheckout Community.

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
