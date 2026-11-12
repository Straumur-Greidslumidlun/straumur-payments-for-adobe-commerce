# Changelog

All notable changes to the Straumur Payment module for Adobe Commerce / Magento 2.

## 1.1.0

Straumur sends a merchant's webhook every payment on the contracts it is subscribed to, including terminal (card-present) payments, payment links and other integrations, and is moving to treat any non-2xx answer as the endpoint failing. The webhook endpoint now answers accordingly.

- Changed: every webhook with a valid signature for the configured webhook secret gets `200`. Events that are not for an order of this shop get `{"message":"Ignored: not an order of this shop.","ignored":true}` and touch no order; unsupported event types are acknowledged the same way; processing errors are logged and acknowledged with `{"message":"Received; processing failed and was logged.","processed":false}`.
- Changed: a missing or wrong signature, or no webhook secret configured, gets `403` (was `400`); a body that is not a JSON object gets `400`; an IP outside the allow-list gets `403`.
- Fixed: the signature is verified first and is the only required field. Terminal payments and payment links, whose merchant and checkout references are null, were rejected with `400` by a required-field check.
- Fixed: an event is only applied to an order whose increment ID is exactly the merchant reference and that was paid with Straumur. Orders paid with another method, references that only match because of database case or trailing-space rules, and increment IDs shared by several stores are no longer touched. When several stores share an increment ID, an Authorization is matched by its checkout reference and a Capture, Refund or Adjustment by the original payment reference. An Authorization whose checkout reference is missing or differs from the order's session is still applied and logged as a warning.
- Fixed: a declined authorization no longer cancels an order that is already authorized or paid.
- Fixed: headless checkout. When the shopper pays before the storefront places the order, the Authorization webhook finds no order and is acknowledged without being applied; the order is now authorized when it is placed, from Straumur's session status.
- Fixed: the tokenization webhook used a Vault constant that does not exist, so saving a card always failed. `magento/module-vault` is now a declared dependency.
- Fixed: the signature is computed from PascalCase payloads too, and odd-length webhook secrets are padded like Straumur does.
- Changed: webhook logs no longer contain the raw payload, headers or signature. Ignored and rejected webhooks log only allow-listed fields (event type, success, amount, currency, reference shape); events applied to an order also log the order number, Straumur payment reference and failure reason.
- Fixed: embedded (headless) checkout sessions no longer send line items, which the Straumur API rejects (#3).
- Changed: `Api\Webhook\RouterInterface::route()` and `Api\Webhook\ProcessorInterface` take the resolved order instead of headers.
- Removed: `Helper\LogSanitizer`, no longer used.
- Added: unit tests (`composer install-test-deps && composer test`) and a CI job that runs them.

## 1.0.1

- Fixed: a missing brace in the webhook validator made the module impossible to compile, so webhooks could not be processed.

## 1.0.0

- Initial release: hosted redirect checkout, headless (embedded) checkout via GraphQL, webhooks for order status, capture, refunds and adjustments.
