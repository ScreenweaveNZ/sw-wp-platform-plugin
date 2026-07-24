# ScreenWeave WordPress Platform

ScreenWeave managed WordPress mu-plugin for production defaults, health checks, staging/holding behaviour, SMTP configuration, media/offload health metadata, and security hardening.

This plugin is intended to be installed by `sw-wp-site-template` through `platform/plugins.manifest`.

## Install manifest entry

```text
github|mu-plugin|screenweave-platform|ScreenweaveNZ/sw-wp-platform-plugin|refs/tags/v1.3.0|screenweave-platform.php
```

Use pinned tags or SHAs for production deployments.

## Health endpoint

The public health endpoint is:

```text
/wp-json/screenweave/v1/health
```

It reports non-secret operational metadata used by `sw-wp-ops`, including database reachability, object-cache status, Redis client, SMTP configuration, cron/file-mod settings, staging/noindex status, and whether media is local or S3-compatible via Advanced Media Offloader.
