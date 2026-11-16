<?php
declare(strict_types=1);

namespace Straumur\Payment\Model\Webhook;

/**
 * Reads Straumur payment webhook fields.
 *
 * Straumur sends camelCase keys; PascalCase is accepted too. Every reader of the payload goes through
 * this class so the signature check, the order lookup and the processors all see the same values.
 */
class WebhookPayload
{
    /**
     * Value of a top-level field, camelCase first, then PascalCase. Null when the field is absent.
     *
     * @param array $payload
     * @param string $field camelCase field name
     * @return mixed
     */
    public static function field(array $payload, string $field)
    {
        if (array_key_exists($field, $payload)) {
            return $payload[$field];
        }

        return $payload[ucfirst($field)] ?? null;
    }

    /**
     * Value of a field as a string; null and missing become an empty string.
     *
     * @param array $payload
     * @param string $field
     * @return string
     */
    public static function string(array $payload, string $field): string
    {
        $value = self::field($payload, $field);

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null || is_array($value)) {
            return '';
        }

        return (string) $value;
    }

    /**
     * Event type from additionalData.eventType, as sent by Straumur (for example "Authorization").
     *
     * @param array $payload
     * @return string
     */
    public static function eventType(array $payload): string
    {
        $additionalData = self::field($payload, 'additionalData');

        if (!is_array($additionalData)) {
            return '';
        }

        $eventType = self::field($additionalData, 'eventType');

        return is_string($eventType) ? $eventType : '';
    }

    /**
     * Shape of the merchant reference, for logs: never the value itself.
     *
     * @param array $payload
     * @return string empty, digits, digit_prefixed or other
     */
    public static function referenceShape(array $payload): string
    {
        $reference = self::string($payload, 'merchantReference');

        if ($reference === '') {
            return 'empty';
        }

        if (ctype_digit($reference)) {
            return 'digits';
        }

        return ctype_digit($reference[0]) ? 'digit_prefixed' : 'other';
    }

    /**
     * Allow-listed fields for logging. The payload can carry card details and shopper information
     * (payment links), so nothing outside this list is logged.
     *
     * @param array $payload
     * @return array
     */
    public static function logContext(array $payload): array
    {
        return [
            'event_type' => self::eventType($payload),
            'success' => self::string($payload, 'success'),
            'amount' => self::string($payload, 'amount'),
            'currency' => self::string($payload, 'currency'),
            'merchant_reference_shape' => self::referenceShape($payload),
            'has_checkout_reference' => self::string($payload, 'checkoutReference') !== '',
        ];
    }
}
