<?php
declare(strict_types=1);

namespace Straumur\Payment\Test\Unit\Model\Webhook;

use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Straumur\Payment\Model\Webhook\OrderResolver;
use Straumur\Payment\Test\Unit\RecordingLogger;

class OrderResolverTest extends TestCase
{
    /**
     * @var OrderInterface[] What the order repository returns for the increment_id filter
     */
    private $ordersInDatabase = [];

    /**
     * @var array[] Filters passed to the search criteria builder
     */
    private $filters = [];

    /**
     * @var int
     */
    private $lookups = 0;

    /**
     * @var RecordingLogger
     */
    private $logger;

    /**
     * @var OrderResolver
     */
    private $resolver;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();

        $builder = self::createStub(SearchCriteriaBuilder::class);
        $builder->method('addFilter')->willReturnCallback(function (...$args) use (&$builder) {
            $this->filters[] = $args;
            return $builder;
        });
        $builder->method('create')->willReturn(self::createStub(SearchCriteria::class));

        $repository = self::createStub(OrderRepositoryInterface::class);
        $repository->method('getList')->willReturnCallback(function () {
            $this->lookups++;
            $result = self::createStub(OrderSearchResultInterface::class);
            $result->method('getItems')->willReturn($this->ordersInDatabase);
            return $result;
        });

        $this->resolver = new OrderResolver($repository, $builder, $this->logger);
    }

    /**
     * Order mock with the given increment ID, payment method and stored references.
     *
     * @param string $incrementId
     * @param string|null $method null for an order without a payment
     * @param string|null $storedCheckoutReference
     * @param string|null $storedAuthorizationReference
     * @return OrderInterface
     */
    private function order(
        string $incrementId,
        ?string $method = 'straumur_payment',
        ?string $storedCheckoutReference = null,
        ?string $storedAuthorizationReference = null
    ): OrderInterface {
        $payment = null;
        if ($method !== null) {
            $payment = self::createStub(OrderPaymentInterface::class);
            $payment->method('getMethod')->willReturn($method);
            $payment->method('getAdditionalInformation')->willReturn(array_filter([
                OrderResolver::STORED_CHECKOUT_REFERENCE => $storedCheckoutReference,
                OrderResolver::STORED_AUTHORIZATION_REFERENCE => $storedAuthorizationReference,
            ], 'is_string'));
        }

        $order = self::createStub(OrderInterface::class);
        $order->method('getIncrementId')->willReturn($incrementId);
        $order->method('getPayment')->willReturn($payment);

        return $order;
    }

    /**
     * Webhook payload with the given references.
     *
     * @param mixed $merchantReference
     * @param mixed $checkoutReference
     * @return array
     */
    private function payload($merchantReference, $checkoutReference = 'chk-1'): array
    {
        return [
            'merchantReference' => $merchantReference,
            'checkoutReference' => $checkoutReference,
            'payfacReference' => 'PF-1',
            'success' => 'true',
            'additionalData' => ['eventType' => 'Authorization'],
        ];
    }

    public function testReturnsTheStraumurOrderWithThatExactIncrementId(): void
    {
        $order = $this->order('000000123');
        $this->ordersInDatabase = [$order];

        $this->assertSame($order, $this->resolver->resolve($this->payload('000000123')));
        $this->assertSame([['increment_id', '000000123', 'eq']], $this->filters);
    }

    public function testReadsPascalCaseReference(): void
    {
        $order = $this->order('000000123');
        $this->ordersInDatabase = [$order];

        $this->assertSame($order, $this->resolver->resolve(['MerchantReference' => '000000123']));
    }

    /**
     * @return array
     */
    public static function referencesThatAreNeverLookedUp(): array
    {
        return [
            'null (terminal payment)' => [null],
            'missing' => ['__missing__'],
            'empty string' => [''],
            'number instead of string' => [742],
            'array' => [['742']],
        ];
    }

    /**
     * @param mixed $reference
     */
    #[DataProvider('referencesThatAreNeverLookedUp')]
    public function testUnusableReferenceIsNotAnOrderOfThisShop($reference): void
    {
        $payload = $this->payload($reference);
        if ($reference === '__missing__') {
            unset($payload['merchantReference']);
        }

        $this->assertNull($this->resolver->resolve($payload));
        $this->assertSame(0, $this->lookups);
    }

    public function testUnknownReferenceIsNotAnOrderOfThisShop(): void
    {
        $this->ordersInDatabase = [];

        $this->assertNull($this->resolver->resolve($this->payload('742eac9d-5b1e-4c3a-9f7e-1d2c3b4a5e6f')));
        $this->assertSame([['increment_id', '742eac9d-5b1e-4c3a-9f7e-1d2c3b4a5e6f', 'eq']], $this->filters);
    }

    /**
     * @return array
     */
    public static function databaseLookalikes(): array
    {
        return [
            'different case' => ['INV-100', 'inv-100'],
            'trailing space' => ['000000123', '000000123 '],
            'leading digits only' => ['742', '742eac9d'],
        ];
    }

    #[DataProvider('databaseLookalikes')]
    public function testCollationLookalikeIsNotTheOrder(string $orderIncrementId, string $reference): void
    {
        // MySQL comparisons can ignore case and trailing spaces; the resolver must not.
        $this->ordersInDatabase = [$this->order($orderIncrementId)];

        $this->assertNull($this->resolver->resolve($this->payload($reference)));
    }

    public function testOrderPaidWithAnotherMethodIsNotTouched(): void
    {
        // For example a terminal sale or payment link that uses the Magento order number as its reference.
        $this->ordersInDatabase = [$this->order('000000123', 'checkmo')];

        $this->assertNull($this->resolver->resolve($this->payload('000000123')));
    }

    public function testOrderWithoutPaymentIsNotTouched(): void
    {
        $this->ordersInDatabase = [$this->order('000000123', null)];

        $this->assertNull($this->resolver->resolve($this->payload('000000123')));
    }

    public function testSameIncrementIdInTwoStoresIsResolvedByCheckoutReference(): void
    {
        $storeA = $this->order('000000123', 'straumur_payment', 'chk-a');
        $storeB = $this->order('000000123', 'straumur_payment', 'chk-b');
        $this->ordersInDatabase = [$storeA, $storeB];

        $this->assertSame($storeB, $this->resolver->resolve($this->payload('000000123', 'chk-b')));
    }

    public function testSameIncrementIdInTwoStoresWithoutMatchingCheckoutReferenceIsLeftAlone(): void
    {
        $this->ordersInDatabase = [
            $this->order('000000123', 'straumur_payment', 'chk-a'),
            $this->order('000000123', 'straumur_payment', 'chk-b'),
        ];

        $this->assertNull($this->resolver->resolve($this->payload('000000123', null)));
        $this->assertCount(1, $this->logger->messages('warning'));
    }

    public function testOtherStoresOrderPaidWithAnotherMethodDoesNotMakeItAmbiguous(): void
    {
        $straumurOrder = $this->order('000000123', 'straumur_payment', 'chk-a');
        $this->ordersInDatabase = [$this->order('000000123', 'checkmo'), $straumurOrder];

        $this->assertSame($straumurOrder, $this->resolver->resolve($this->payload('000000123', null)));
    }

    public function testCheckoutReferenceMismatchIsLoggedButStillProcessed(): void
    {
        // Soft check: a new session for the same cart overwrites the stored reference, so a payment made
        // in an earlier session carries a different one and must still be applied.
        $order = $this->order('000000123', 'straumur_payment', 'chk-new');
        $this->ordersInDatabase = [$order];

        $this->assertSame($order, $this->resolver->resolve($this->payload('000000123', 'chk-old')));
        $this->assertCount(1, $this->logger->messages('warning'));
    }

    public function testNoWarningWhenTheOrderStoredNoCheckoutReference(): void
    {
        $this->ordersInDatabase = [$this->order('000000123', 'straumur_payment', null)];
        $this->resolver->resolve($this->payload('000000123', 'chk-1'));

        $this->assertSame([], $this->logger->messages('warning'));
    }

    public function testAuthorizationWithoutCheckoutReferenceIsLoggedButStillProcessed(): void
    {
        // A terminal sale or payment link whose reference is a Straumur order's number: the soft check applies
        // it, and this warning is how it shows up.
        $order = $this->order('000000123', 'straumur_payment', 'chk-1');
        $this->ordersInDatabase = [$order];

        $this->assertSame($order, $this->resolver->resolve($this->payload('000000123', null)));
        $this->assertCount(1, $this->logger->messages('warning'));
    }

    /**
     * Payload of a follow-up event (Capture, Refund, Adjustment): its checkoutReference is that operation's own.
     *
     * @param string $eventType
     * @param string|null $originalPayfacReference
     * @return array
     */
    private function followUpPayload(string $eventType, ?string $originalPayfacReference): array
    {
        $payload = $this->payload('000000123', 'chk-of-the-' . strtolower($eventType));
        $payload['payfacReference'] = 'PF-' . $eventType;
        $payload['additionalData'] = ['eventType' => $eventType];
        if ($originalPayfacReference !== null) {
            $payload['additionalData']['originalPayfacReference'] = $originalPayfacReference;
        }

        return $payload;
    }

    /**
     * @return array
     */
    public static function followUpEvents(): array
    {
        return [['Capture'], ['Refund'], ['Adjustment']];
    }

    #[DataProvider('followUpEvents')]
    public function testFollowUpEventIsNotWarnedAboutItsOwnCheckoutReference(string $eventType): void
    {
        $order = $this->order('000000123', 'straumur_payment', 'chk-a', 'PF-auth-a');
        $this->ordersInDatabase = [$order];

        $this->assertSame($order, $this->resolver->resolve($this->followUpPayload($eventType, 'PF-auth-a')));
        $this->assertSame([], $this->logger->messages('warning'));
    }

    #[DataProvider('followUpEvents')]
    public function testFollowUpEventInTwoStoresIsResolvedByOriginalPayfacReference(string $eventType): void
    {
        $storeA = $this->order('000000123', 'straumur_payment', 'chk-a', 'PF-auth-a');
        $storeB = $this->order('000000123', 'straumur_payment', 'chk-b', 'PF-auth-b');
        $this->ordersInDatabase = [$storeA, $storeB];

        $this->assertSame($storeB, $this->resolver->resolve($this->followUpPayload($eventType, 'PF-auth-b')));
    }

    public function testFollowUpEventInTwoStoresWithoutOriginalPayfacReferenceIsLeftAlone(): void
    {
        $this->ordersInDatabase = [
            $this->order('000000123', 'straumur_payment', 'chk-a', 'PF-auth-a'),
            $this->order('000000123', 'straumur_payment', 'chk-b', 'PF-auth-b'),
        ];

        $this->assertNull($this->resolver->resolve($this->followUpPayload('Capture', null)));
        $this->assertCount(1, $this->logger->messages('warning'));
    }

    public function testFollowUpEventInTwoStoresIsNotMatchedByCheckoutReference(): void
    {
        // Straumur sends the capture's own checkoutReference; even when it equals a stored session reference,
        // it does not identify the order for a follow-up event.
        $this->ordersInDatabase = [
            $this->order('000000123', 'straumur_payment', 'chk-of-the-capture', 'PF-auth-a'),
            $this->order('000000123', 'straumur_payment', 'chk-b', 'PF-auth-b'),
        ];

        $this->assertNull($this->resolver->resolve($this->followUpPayload('Capture', 'PF-unknown')));
    }
}
