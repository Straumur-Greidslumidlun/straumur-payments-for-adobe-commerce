<?php
declare(strict_types=1);

namespace Straumur\Payment\Test\Unit\Model\Webhook;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Straumur\Payment\Model\Webhook\WebhookPayload;

class WebhookPayloadTest extends TestCase
{
    /**
     * @return array
     */
    public static function references(): array
    {
        return [
            'null' => [null, 'empty'],
            'empty' => ['', 'empty'],
            'increment id' => ['000000123', 'digits'],
            'uuid with leading digits' => ['742eac9d-5b1e-4c3a', 'digit_prefixed'],
            'prefixed' => ['INV-100', 'other'],
        ];
    }

    /**
     * @param string|null $reference
     * @param string $shape
     */
    #[DataProvider('references')]
    public function testReferenceShape(?string $reference, string $shape): void
    {
        $this->assertSame($shape, WebhookPayload::referenceShape(['merchantReference' => $reference]));
    }

    public function testLogContextContainsOnlyAllowListedFields(): void
    {
        $context = WebhookPayload::logContext([
            'merchantReference' => '000000123',
            'checkoutReference' => 'c1a2b3',
            'payfacReference' => 'PF-1',
            'amount' => '12990',
            'currency' => 'ISK',
            'success' => 'true',
            'hmacSignature' => 'sig',
            'additionalData' => [
                'eventType' => 'Capture',
                'cardSummary' => '1234',
                'shopperInformation' => ['fullName' => 'X'],
            ],
        ]);

        $this->assertSame([
            'event_type' => 'Capture',
            'success' => 'true',
            'amount' => '12990',
            'currency' => 'ISK',
            'merchant_reference_shape' => 'digits',
            'has_checkout_reference' => true,
        ], $context);
    }

    public function testFieldPrefersCamelCaseAndFallsBackToPascalCase(): void
    {
        $this->assertSame('a', WebhookPayload::field(['amount' => 'a', 'Amount' => 'b'], 'amount'));
        $this->assertSame('b', WebhookPayload::field(['Amount' => 'b'], 'amount'));
        $this->assertNull(WebhookPayload::field([], 'amount'));
        $this->assertSame('Refund', WebhookPayload::eventType(['AdditionalData' => ['EventType' => 'Refund']]));
    }

    public function testStringConvertsBooleansAndNull(): void
    {
        $this->assertSame('true', WebhookPayload::string(['success' => true], 'success'));
        $this->assertSame('false', WebhookPayload::string(['success' => false], 'success'));
        $this->assertSame('', WebhookPayload::string(['reason' => null], 'reason'));
        $this->assertSame('5000', WebhookPayload::string(['amount' => 5000], 'amount'));
    }
}
