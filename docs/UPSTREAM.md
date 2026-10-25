# Upstream and patch history

Upstream: https://github.com/bs-community/blessing-skin-server
Patch base: https://github.com/DreamCraft-Tech/blessing-skin-server-patch
          (Blessing Skin Server 6.0.10_patch, Laravel 10). The commit `52f6fef` is only a
          "remove unavailable sponsor info" commit inside that patch repo; it is unrelated to 6.0.2.

Upstream history is retained. Subsequent signed commits were reconstructed on 2026-10-24 from the delivered PHP 8.5 and versioned overlay packages. Their commit dates record reconstruction time; version dates are preserved in CHANGELOG-PATCH.md. Runtime dependencies and compiled frontend bundles are built from source.

Version commits: PHP 8.5 base, 6.0.3_patch, 6.0.4_patch, 6.0.5_patch, 6.0.6_patch. Each release changes files in place; this initial publication contains no version tags. A repository setup commit follows the release snapshots with reproducible build tooling and current tests.
