# Straumur Payment for Magento 2

Straumur Payment delivers hosted redirect and headless embedded checkout experiences for Magento 2 stores. The extension integrates Straumur’s payment services, manages secure session creation, and reconciles orders through webhook callbacks.

## Features
- Hosted checkout redirect with configurable session timeout, culture, and manual capture support.
- Headless embedded checkout via GraphQL, including secure session tokens for PWAs and custom storefronts.
- Webhook processing with HMAC validation, IP whitelisting, and automatic order state transitions.
- Detailed logging and Magento cache-backed session management for resilience across retries.

## Requirements
- Magento Open Source or Adobe Commerce 2.4.7+ (tested against 2.4.8).
- Straumur API credentials, terminal identifier, and hosted checkout theme.
- PHP configuration that meets Magento’s platform requirements.

## Installation
1. Require the module within your Magento project (Composer or submodule checkout).
2. Ensure the code resides in `app/code/Straumur/Payment` and run:
   ```bash
   bin/magento module:enable Straumur_Payment
   bin/magento setup:upgrade
   bin/magento setup:di:compile
   bin/magento cache:flush
   ```
3. Enable the payment method and configure credentials before testing in production.

> **Tip:** When developing with Warden, execute Magento CLI commands inside the PHP container (`warden shell -c "<command>"`).

## Configuration
Navigate to **Stores → Configuration → Sales → Payment Methods → Straumur Payment**.

### API & Environment
- **Environment** (`environment`): Sandbox or Production endpoints.
- **Sandbox / Production API URL** (`sandbox_api_url`, `production_api_url`): Base URLs ending with `/api/v1/`.
- **API Key** (`api_key`) & **Terminal ID** (`terminal_id`): Provided by Straumur; terminal must be 8 alphanumeric characters.
- **Hosted Checkout Theme ID** (`hc_theme_id`): Optional theme override for the hosted page.

### Payment Behaviour
- **Enable Manual Capture** (`manual_capture`): Toggle between authorize-only and auto-capture.
- **Session Timeout** (`session_timeout`): Minutes before a redirect or embedded session expires (5–1440, default 60).
- **Language / Culture** (`culture`): Hosted checkout locale.
- **Send Order Items** (`send_items`): Control whether individual line items are transmitted to Straumur.
- **New Order Status** (`order_status`): Pending status applied before Straumur confirms payment.

### Webhook & Security
- **Webhook Secret** (`webhook_secret`): Hexadecimal secret for HMAC validation.
- **Webhook Allowed IPs** (`webhook_allowed_ips`): Optional allowlist (individual IPs or CIDR ranges).
- **Debug Mode** (`debug`): Enable verbose logging (`var/log/straumur_payment.log`).

### Headless Mode
- **Enable Headless (Quote Session) Mode** (`use_quote_session`): Gate for headless integrations; required for GraphQL embedded sessions.
- Configure headless storefront return URLs to accept `sessionToken` callbacks for both success and cancel flows.

## Redirect Checkout Flow
1. Customer selects Straumur Payment during checkout.
2. `InitializeCommand` prepares the order without capturing payment and delegates to `AuthorizeCommand` to create a Straumur session.
3. The storefront requests the redirect URL via `Controller/Checkout/GetRedirectUrl.php`, which returns the Straumur hosted checkout link.
4. Shopper completes payment on Straumur’s hosted page. Straumur redirects back to Magento via `Controller/Payment/ReturnAction.php` on success or `Controller/Payment/Cancel/Index.php` on cancellation.
5. Magento finalizes the order once Straumur sends webhooks confirming authorization, capture, or refunds. The extension logs all session data for traceability.

### Redirect Considerations
- Ensure the configured session timeout accommodates longer checkouts.
- Order confirmation emails are deferred until Straumur confirms payment to avoid false positives.
- Duplicate webhooks are handled idempotently; examine `var/log/straumur_payment.log` when diagnosing issues.

## Headless (Embedded) Checkout Flow
Headless mode lets PWAs and custom frontends embed Straumur’s web component without routing the shopper back to Magento.

1. Enable **Headless (Quote Session) Mode** in the Magento Admin for the relevant scope.
2. From the headless frontend, obtain the cart/quote ID and call the Magento GraphQL mutation:
   ```graphql
   mutation CreateStraumurSession($cartId: String!) {
     createStraumurEmbeddedSession(
       input: {
         cartId: $cartId
         origin: "https://checkout.example.com"
         threeDsReturnUrl: "https://checkout.example.com/straumur/3ds-return"
         sendItems: true
       }
     ) {
       sessionId
       checkoutReference
       sessionToken
       expiresAt
     }
   }
   ```
3. `QuoteSessionCreator` validates the quote, reserves an order reference, formats totals (optionally including line items), and calls Straumur’s `embeddedcheckout/session` endpoint.
4. Mount Straumur’s web component using the returned `sessionId`. Keep both the `checkoutReference` and `sessionToken` so you can reconcile the order later or query status.
5. The component drives the payment, including any 3-D Secure challenges via the `threeDsReturnUrl` route you expose in your headless app. Stay on the headless storefront; do not redirect back to Magento.
6. Inform the shopper of the outcome once Straumur confirms payment. Magento receives the authoritative result via webhook, and you can optionally poll status before that webhook arrives. Secure return URLs (`straumur/payment/return` and `straumur/payment/cancel`) remain available if you prefer a Magento-hosted success page, but headless storefronts typically stay within their own UI.

### Embedding the Straumur Web Component
- Install the official package and mount it once per checkout page:
  ```bash
  npm install straumur-web-component --save
  ```
  ```js
  import { StraumurCheckout } from 'straumur-web-component';

  const paymentConfiguration = {
    environment: 'test',              // swap to 'live' in production
    sessionId: '<sessionId from GraphQL>',
    locale: 'en',                     // optional override; defaults to quote culture
    onPaymentCompleted: () => {
      // Show success UI, optionally poll straumurEmbeddedSessionStatus while awaiting webhook
    },
    onPaymentFailed: () => {
      // Unblock retry flow or present alternative payment options
    },
    placeholders: {
      cardNumber: 'Card number'       // optional overrides; see NPM typings for full list
    },
    localizations: {
      payButton: 'Pay now'
    }
  };

  const checkout = new StraumurCheckout(paymentConfiguration);
  checkout.mount('#straumur-component-container');
  ```
- The component renders PCI-compliant card fields, handles Straumur-hosted payment flows, and manages any 3-D Secure challenges using the `threeDsReturnUrl` provided during session creation.
- `onPaymentCompleted` and `onPaymentFailed` fire after Straumur finalizes the embedded flow. Use them to drive your UX, but continue to rely on Magento webhooks for the definitive order state.
- Keep the DOM container stable—React/Vue integrators should mount via refs to avoid re-rendering the element while a shopper is entering card details.

### Optional Status Polling
Call `straumurEmbeddedSessionStatus(sessionToken: String!)` to obtain Straumur’s latest status payload while waiting for the webhook. Use the result to update frontend messaging, but rely on the webhook processors to transition Magento orders.

## Webhooks & Order States
- Endpoint: `/straumur/webhook/index` (`Controller/Webhook/Index.php`).
- Straumur signs payloads with the configured HMAC secret; invalid signatures are rejected.
- `Model/Webhook/Router` dispatches events to specific processors (authorization, capture, refund). Order comments record all state changes.
- Keep the endpoint reachable from Straumur’s infrastructure and align firewall rules with the IP allowlist.

## Troubleshooting
- **Checkout URL not found**: Verify API credentials, session timeout, and that Straumur responded with a session link.
- **Session token expired**: Confirm timeout configuration and generate a fresh embedded session if the shopper waits beyond Straumur’s TTL (maximum 24 hours).
- **HMAC validation failed**: Check webhook secret formatting and log output for the signed payload.
- **Order stuck in `pending_payment`**: Confirm webhooks reach Magento and that the Straumur dashboard shows successful callbacks.
- Enable debug logging for additional context: `bin/magento config:set payment/straumur_payment/debug 1`.

## Support & Resources
- Straumur web component reference: https://docs.straumur.is/payment-gateway/components/straumur-components/web-component
- Logs: `var/log/straumur_payment.log` (conditional on debug mode).
- Commands for cache management and module maintenance:
  ```bash
  bin/magento cache:flush
  rm -rf generated/code/*
  ```
For Straumur API onboarding or production enablement, contact your Straumur representative.
