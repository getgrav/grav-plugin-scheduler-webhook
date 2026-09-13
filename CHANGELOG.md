# v1.1.4
## 09/13/2026

1. [](#improved)
    * The setup instructions no longer refer to a Modern Scheduler switch that no longer exists, and now say a webhook token is required rather than optional. Thanks @sridharkalaibala [#7](https://github.com/getgrav/grav-plugin-scheduler-webhook/pull/7)

# v1.1.3
## 07/09/2026

1. [](#bugfix)
    * [security] A scheduler webhook that is enabled without a token set no longer runs jobs for anonymous callers; the endpoint now refuses every request until a token is configured, and compares tokens in constant time ([GHSA-xwv3-2mv2-w33x](https://github.com/getgrav/grav/security/advisories/GHSA-xwv3-2mv2-w33x)).

# v1.1.2
## 07/06/2026

1. [](#bugfix)
   * Fixed webhook token authentication returning a 401 on servers that strip the `Authorization` header, by recommending and reliably supporting the `X-Webhook-Token` header instead.

# v1.1.1
## 07/06/2026

1. [](#bugfix)
   * Removed a redundant check that blocked webhook and health endpoints unless a `scheduler.modern.enabled` setting was on, a toggle that no longer exists in Grav 2.

# v1.1.0
## 05/06/2026

1. [](#new)
   * Added Grav 2.0 compatibility 

# v1.0.1
## 04/30/2026

1. [](#bugfix)
    * Fixed PHP 8.1+ deprecation notice — explicit string casts where `null` was being passed to string-typed function arguments.

# v1.0.0
## 08/25/2025

1. [](#new)
    * Initial release of Scheduler Webhook plugin
    * Webhook endpoint for triggering scheduler
    * Health check endpoint for monitoring
    * Bearer token authentication
    * Support for triggering specific jobs
    * Optional CORS support
    * Comprehensive documentation and examples