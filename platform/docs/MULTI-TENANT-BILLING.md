# Company workspaces and Paynow billing

TEVERA uses shared database tenancy with explicit company ownership and role checks. A company administrator manages their company. Operators and customer users only see assigned vehicles. Super administrators manage all companies. Traccar remains a private server accessed by the backend; tenants must not receive the service account credentials or direct access to its administration interface.

## Setup and operation

1. As super administrator, open Companies. Create a company and its first administrator in one transaction, or create the administrator later under Users & access.
2. Open the company to set vehicle allowance, trial end and subscription enforcement. Existing companies remain managed workspaces without forced billing. The limit counts inactive vehicles too. Company suspension revokes sessions and remember tokens and blocks app access; it does not stop incoming GPS reports.
3. Open Subscription & billing → Manage plans. The optional `SubscriptionPlanSeeder` creates three hidden example plans (Starter USD 10/5 vehicles, Fleet USD 25/20, Business USD 50/50). These prices are placeholders, not approved pricing. Edit currency, prices and allowances before publishing.
4. Configure private environment variables `PAYNOW_INTEGRATION_ID`, `PAYNOW_INTEGRATION_KEY`, `PAYNOW_CURRENCY` and `PAYNOW_PUBLIC_URL`. Currency must match the merchant integration's settlement/payment currency; the standard initiation API does not select it via a currency field. The public URL must be the externally reachable HTTPS base URL for this Laravel app, with no trailing route, query or fragment. Set `PAYNOW_ENABLED=true` only after configuration and a controlled payment test.
5. Deploy with the public directory as the web root. Allow public POST requests to `/paynow/result`. Only this route is CSRF-exempt; it authenticates the raw message using Paynow's hash and independently polls the gateway. Localhost cannot receive Paynow callbacks.
6. A company administrator selects a plan, continues to Paynow, and completes payment there. The callback or Check payment status verifies it. Renewals are customer-initiated payments for 30 days, not automatic debits. Early renewals extend the existing paid period. Switching plans is available after the current paid period ends. Checkout must cover existing vehicle count.

## Payment safeguards and recovery

Amounts, company IDs and plan entitlements are server-side snapshots. Browser return parameters never activate access. Signature, reference and amount must match. Gateway URLs are limited to HTTPS on www.paynow.co.zw and polling to /interface/checkpayment. Redirects are not followed. Duplicate callbacks do not extend subscriptions twice. Company and payment row locks serialize entitlement changes; use MySQL/MariaDB or PostgreSQL plus a shared cache lock backend for multi-instance deployment.

An initiation timeout is ambiguous: a payment may already exist remotely. TEVERA keeps an unknown payment and avoids automatic retries. Signed callbacks can recover its poll URL. After merchant investigation, use `php artisan billing:reconcile PAYMENT_UUID --poll-url="PAYNOW_POLL_URL"` to independently verify the known gateway transaction. Do not delete payment records to bypass uncertainty. There is no automatic refund API or card token storage. Refund/dispute callbacks on applied payments place company access on a merchant review hold. A super administrator can clear the hold in company settings after investigation; it is audited. Payment history retains the gateway status.

Expired company users retain access to billing and logout; only company administrators can pay or open receipts. Tracking JSON returns HTTP 402 on expiry. Audit logs are scoped to the company, with global access for platform administrators. Audit records identify operations and actors but do not currently store before/after field values.

## Validation and release boundary

Feature tests cover onboarding, suspension, tenant authorization, quotas, payment signature validation against the official Paynow vector, duplicate notifications, forged callbacks, incorrect references/amounts, expiry and tenant receipt isolation. Tests fake gateway requests and never charge the merchant integration. No live Paynow transaction has been completed in this local setup.

Before public launch, configure and test the public HTTPS domain, confirm merchant currency, approve real plan prices, perform a controlled end-to-end payment and refund test, and operate persistent app/Traccar services with monitoring and tested backups. Self-service registration, emailed invitations, automatic recurring card charges, taxation-compliant invoices, proration, and multiple-company membership per user are not implemented by this phase.

Protocol references: https://developers.paynow.co.zw/docs/paynow/initiate_transaction/ ; https://developers.paynow.co.zw/docs/paynow/generating_hash/ ; https://developers.paynow.co.zw/docs/paynow/status_update/
