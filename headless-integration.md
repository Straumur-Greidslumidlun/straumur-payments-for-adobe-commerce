# Straumur Payment Integrator Guide

This guide explains how to integrate Straumur Payment into headless Magento 2 storefronts, focusing on the GraphQL embedded checkout flow, Straumur web component usage, and payment reconciliation.

## Audience & Scope
- Frontend engineers implementing Straumur checkout in PWAs or custom storefronts.
- Backend/Magento developers wiring configuration, webhooks, and session management.
- Covers headless embedded checkout flow; see `README.md` for hosted redirect details.

## Prerequisites
- Magento 2.4.7+ with the Straumur module installed and enabled.
- Straumur sandbox or production API credentials (`API Key`, `Terminal ID`, endpoint URLs).
- Magento Admin access to configure the payment method per store view.
- Frontend build capable of executing GraphQL requests against Magento and loading npm packages.

## Magento Configuration
1. In **Stores → Configuration → Sales → Payment Methods → Straumur Payment**:
   - Set API URLs, API Key, Terminal ID, and Hosted Checkout Theme ID as provided by Straumur.
   - Enable **Enable Headless (Quote Session) Mode** to allow GraphQL session creation.
   - Optionally configure manual capture, session timeout, culture, line-item transmission, and debug mode.
2. Expose HTTPS routes in your headless frontend that match the `threeDsReturnUrl` used during session creation. Straumur sends shoppers there when 3-D Secure challenges are required.
3. Configure firewall rules and SSL certificates so Straumur webhooks can reach `/straumur/webhook/index`.

## Headless Embedded Checkout Workflow

### High-Level Flow
1. Shopper builds a cart in the headless storefront.
2. Frontend obtains Magento cart ID (masked for guests) and requests a Straumur embedded session via GraphQL.
3. Magento reserves an order reference, creates the embedded session with Straumur, and returns `sessionId`, `checkoutReference`, and `sessionToken`.
4. Frontend mounts the Straumur web component using the session data.
5. Component renders card fields, handles Straumur’s hosted flow, and automatically manages any 3-D Secure redirects using the provided return URL.
6. Straumur notifies Magento via webhook; optionally, the frontend polls session status using the secure token.
7. Magento finalizes the order (authorization, capture, refunds) based on webhook events.

### Flow Overview
```
Shopper                → Headless Frontend : Choose Straumur Payment
Headless Frontend      → Magento GraphQL   : createStraumurEmbeddedSession
Magento                → Straumur API      : POST embeddedcheckout/session
Straumur API           → Magento           : sessionId, checkoutReference
Magento GraphQL        → Headless Frontend : sessionId, sessionToken
Headless Frontend      → Straumur Component: Initialize web component (sessionId)
Straumur Component     → Shopper           : Collect card details & handle 3-D Secure
Straumur Component     → Frontend          : onPaymentCompleted / onPaymentFailed callbacks
Straumur API           → Magento Webhook   : Authorization / capture payload
Headless Frontend (opt)→ Magento GraphQL   : straumurEmbeddedSessionStatus(sessionToken)
Magento                → Magento Sales     : Update order via webhook processors
```

### GraphQL: Create Embedded Session
Endpoint: Magento GraphQL (`/graphql`), mutation `createStraumurEmbeddedSession`.

#### Mutation
```graphql
mutation CreateStraumurSession(
  $cartId: String!
  $origin: String!
  $threeDsReturnUrl: String!
  $sendItems: Boolean
) {
  createStraumurEmbeddedSession(
    input: {
      cartId: $cartId
      origin: $origin
      threeDsReturnUrl: $threeDsReturnUrl
      sendItems: $sendItems
      manualCapture: false           # optional overrides
      culture: "en"
    }
  ) {
    sessionId
    checkoutReference
    sessionToken
    responseIdentifier
    responseDateTime
    expiresAt
  }
}
```

#### Response Example
```json
{
  "data": {
    "createStraumurEmbeddedSession": {
      "sessionId": "2f37737d-3cd2-4f41-97e9-c4d152c6e4bc",
      "checkoutReference": "100000045",
      "sessionToken": "FHxy1c1akG91owSj2MZyB9xDoB3wvkXQ",
      "responseIdentifier": "d5f2c09f-8e43-4a2a-9b59-7af7706dfcae",
      "responseDateTime": "2025-02-06T09:32:41Z",
      "expiresAt": "2025-02-06T10:32:41Z"
    }
  }
}
```

#### Field Notes
- `origin`: The full domain where the embedded component runs. Validation requires HTTPS.
- `threeDsReturnUrl`: HTTPS route within your frontend that Straumur uses to complete 3-D Secure flows.
- `sessionToken`: Opaque identifier managed by Magento’s `SessionManager`. Keep it for status polling or secure return flows.
- `expiresAt`: ISO timestamp when Straumur considers the session invalid (maximum 24 hours).

### Frontend: Mount the Straumur Web Component
Install once per project:
```bash
npm install straumur-web-component --save
```

Example integration:
```js
import { StraumurCheckout } from 'straumur-web-component';

const paymentConfiguration = {
  environment: 'test',                         // switch to 'live' in production
  sessionId: session.sessionId,                // from GraphQL response
  locale: 'en',                                // optional override
  placeholders: { cardNumber: 'Card number' }, // optional UI tweaks
  onPaymentCompleted: () => {
    // show success UI, disable submit button, optionally poll status
  },
  onPaymentFailed: () => {
    // surface error messaging, allow retry, or switch payment method
  }
};

const checkout = new StraumurCheckout(paymentConfiguration);
checkout.mount('#straumur-component-container');
```

Integration tips:
- Mount the component once per checkout and avoid re-rendering the container (`refs` in React/Vue).
- The component manages card inputs, brand detection, validation, Straumur API calls, and 3-D Secure challenges.
- Use the event handlers to update UI state; do not assume order completion until Magento processes the webhook.

### Handling 3-D Secure
- Provide a dedicated route for `threeDsReturnUrl`. The component will redirect shoppers there when Straumur requires challenge completion.
- The route should remount the component (if needed) with the same `sessionId` so the flow can resume. Persist session data in frontend storage (state, context, or local storage) to survive navigation.

### Payment Status Before Webhook
While waiting for Magento’s webhook, you may optionally poll status via GraphQL.

#### Status Query
```graphql
query StraumurSessionStatus($token: String!) {
  straumurEmbeddedSessionStatus(sessionToken: $token) {
    checkoutReference
    status
    payfacReference
    responseDateTime
    responseIdentifier
    rawPayload
  }
}
```

#### Response Fields
- `status`: Current session state (`"New"`, `"Completed"`, or `"Expired"`)
- `payfacReference`: Payment reference from Straumur (only populated when `status` is `"Completed"`)
- `responseDateTime`: ISO 8601 timestamp of the status check
- `responseIdentifier`: Unique identifier for this status response
- `rawPayload`: Complete JSON response from Straumur API for debugging

Use cases:
- Provide real-time UI updates while the shopper remains on the success screen.
- Confirm Straumur's status if webhook delivery is delayed.

Do not mutate Magento order state from the frontend; webhooks remain the source of truth.

### Webhook Reconciliation
- Straumur sends HMAC-signed JSON payloads to `/straumur/webhook/index`.
- Magento validates signatures using the configured secret and processes authorization, capture, cancel, and refund events.
- Orders transition from `pending_payment` to `processing` or `complete` when the corresponding webhook succeeds.
- Ensure the webhook endpoint is publicly reachable and that retries are idempotent (handled by `Model/Webhook/Router`).

### Error Handling & Troubleshooting
- **Session creation failures**: GraphQL returns an error if the cart is empty, headless mode is disabled, or Straumur API is unreachable. Display actionable messaging and let shoppers retry.
- **Session expired**: If the shopper waits beyond `expiresAt`, create a new embedded session from Magento and re-render the component.
- **Component payment failure**: Use `onPaymentFailed` to reset the UI. Consider calling the status query for additional context and log `checkoutReference`.
- **Webhook delays**: Present a “Payment processing…” state and periodically poll status, but refrain from duplicating webhook logic client-side.
- **IP allowlist issues**: Verify the `webhook_allowed_ips` configuration or temporarily disable the allowlist for testing.

### Reference Checklist
- [ ] Magento module enabled and cache flushed.
- [ ] Headless mode activated for the relevant store view.
- [ ] API credentials and endpoint URLs configured.
- [ ] Webhook secret matches Straumur dashboard configuration.
- [ ] Frontend stores `sessionId`, `checkoutReference`, and `sessionToken`.
- [ ] Straumur web component mounted with stable DOM container and event handlers wired.
- [ ] Optional status polling implemented using `sessionToken`.
- [ ] Operational monitoring in place for `var/log/straumur_payment.log` and webhook delivery.

## Additional Resources
- Magento code references:
  - `Model/Resolver/CreateEmbeddedSession.php`
  - `Model/Service/QuoteSessionCreator.php`
  - `Model/Resolver/GetEmbeddedSessionStatus.php`
  - `Model/Service/SessionManager.php`
- Straumur documentation: https://docs.straumur.is/payment-gateway/components/straumur-components/web-component
- Contact Straumur support for production onboarding or API credential questions.
