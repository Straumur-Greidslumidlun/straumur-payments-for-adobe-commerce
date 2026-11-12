<?php
declare(strict_types=1);

namespace Straumur\Payment\Test\Unit\Model\Webhook\Command;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\TestCase;
use Straumur\Payment\Gateway\Config\Config;
use Straumur\Payment\Helper\Data as StraumurHelper;
use Straumur\Payment\Model\Service\PaymentStateManager;
use Straumur\Payment\Model\Service\TransactionCalculator;
use Straumur\Payment\Model\Webhook\Command\AuthorizationProcessor;
use Straumur\Payment\Test\Unit\RecordingLogger;

class AuthorizationProcessorTest extends TestCase
{
    /**
     * @var OrderRepositoryInterface Stub by default; a mock in tests that set expectations
     */
    private $orderRepository;

    /**
     * @var TransactionCalculator Stub by default; a mock in tests that set expectations
     */
    private $transactionCalculator;

    /**
     * @var PaymentStateManager Stub by default; a mock in tests that set expectations
     */
    private $paymentStateManager;

    protected function setUp(): void
    {
        $this->orderRepository = self::createStub(OrderRepositoryInterface::class);
        $this->transactionCalculator = self::createStub(TransactionCalculator::class);
        $this->paymentStateManager = self::createStub(PaymentStateManager::class);
    }

    /**
     * The processor, with $this->orderRepository as it is when called.
     *
     * @return AuthorizationProcessor
     */
    private function processor(): AuthorizationProcessor
    {
        return new AuthorizationProcessor(
            self::createStub(Config::class),
            $this->orderRepository,
            new RecordingLogger(),
            self::createStub(OrderSender::class),
            self::createStub(StraumurHelper::class),
            $this->transactionCalculator,
            $this->paymentStateManager
        );
    }

    /**
     * Order mock in the given state; $authorized sets the module's "payment authorized" flag.
     *
     * @param string $state
     * @param bool $authorized
     * @param bool $withExpectations true for a mock the test sets expectations on, false for a stub
     * @return Order
     */
    private function order(string $state, bool $authorized, bool $withExpectations = true): Order
    {
        $payment = self::createStub(Payment::class);
        $payment->method('getAdditionalInformation')->willReturnCallback(
            function ($key = null) use ($authorized) {
                return $key === PaymentStateManager::FLAG_PAYMENT_AUTHORIZED ? $authorized : null;
            }
        );

        $order = $withExpectations ? $this->createMock(Order::class) : self::createStub(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getState')->willReturn($state);
        $order->method('canCancel')->willReturn(true);
        $order->method('getIncrementId')->willReturn('000000123');

        return $order;
    }

    /**
     * @return array
     */
    private static function declined(): array
    {
        return [
            'merchantReference' => '000000123',
            'payfacReference' => 'PF-9',
            'amount' => '12990',
            'currency' => 'ISK',
            'success' => 'false',
            'additionalData' => ['eventType' => 'Authorization'],
        ];
    }

    public function testDeclinedPaymentCancelsOrderAwaitingPayment(): void
    {
        $order = $this->order(Order::STATE_PENDING_PAYMENT, false);
        $order->expects($this->once())->method('cancel');
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->orderRepository->expects($this->once())->method('save')->with($order);

        $result = $this->processor()->process(self::declined(), $order);

        $this->assertSame('failed', $result['status']);
    }

    public function testDeclinedPaymentDoesNotCancelPaidOrder(): void
    {
        $order = $this->order(Order::STATE_PROCESSING, false);
        $order->expects($this->never())->method('cancel');
        $order->expects($this->never())->method('setState');
        $order->expects($this->once())->method('addCommentToStatusHistory');
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->orderRepository->expects($this->once())->method('save')->with($order);

        $this->processor()->process(self::declined(), $order);
    }

    public function testDeclinedPaymentDoesNotCancelAuthorizedOrder(): void
    {
        $order = $this->order(Order::STATE_PENDING_PAYMENT, true);
        $order->expects($this->never())->method('cancel');
        $order->expects($this->never())->method('setState');

        $this->processor()->process(self::declined(), $order);
    }

    public function testUnsignedPascalCaseFieldDoesNotOverrideSignedValue(): void
    {
        // The signature covers "success" (null is signed as ""); an extra "Success" is not signed and
        // must not turn the event into a successful authorization.
        $payload = self::declined();
        $payload['success'] = null;
        $payload['Success'] = 'true';
        $order = $this->order(Order::STATE_PENDING_PAYMENT, false);
        $order->expects($this->once())->method('cancel');

        $result = $this->processor()->process($payload, $order);

        $this->assertSame('failed', $result['status']);
    }

    /**
     * @return array
     */
    private static function approved(): array
    {
        $payload = self::declined();
        $payload['success'] = 'true';
        $payload['additionalData']['authCode'] = 'A1';

        return $payload;
    }

    public function testApprovedPaymentIsRecordedAndMarksTheOrderAuthorized(): void
    {
        $order = $this->order(Order::STATE_NEW, false, false);
        $this->transactionCalculator = $this->createMock(TransactionCalculator::class);
        $this->transactionCalculator->expects($this->once())->method('addTransactionToHistory')->with(
            $this->anything(),
            'authorizations',
            $this->callback(function (array $entry): bool {
                return $entry['reference'] === 'PF-9' && $entry['amount'] === 12990.0 && $entry['success'] === true;
            })
        );
        $this->paymentStateManager = $this->createMock(PaymentStateManager::class);
        $this->paymentStateManager->expects($this->once())->method('markPaymentAuthorized')
            ->with($order, 'PF-9', 12990.0, ['eventType' => 'Authorization', 'authCode' => 'A1'])
            ->willReturn(true);

        $result = $this->processor()->process(self::approved(), $order);

        $this->assertSame('success', $result['status']);
        $this->assertSame('PF-9', $result['transaction_id']);
        $this->assertSame(12990.0, $result['amount']);
    }

    public function testDuplicateApprovedWebhookIsNotRecordedTwice(): void
    {
        $order = $this->order(Order::STATE_PROCESSING, true, false);
        $this->transactionCalculator = $this->createMock(TransactionCalculator::class);
        $this->transactionCalculator->expects($this->never())->method('addTransactionToHistory');
        $this->paymentStateManager = $this->createMock(PaymentStateManager::class);
        $this->paymentStateManager->expects($this->never())->method('markPaymentAuthorized');

        $result = $this->processor()->process(self::approved(), $order);

        $this->assertSame('success', $result['status']);
        $this->assertSame('Duplicate authorization webhook ignored', $result['note']);
    }

    /**
     * @return array
     */
    public static function statesThatCannotBeAuthorized(): array
    {
        return [[Order::STATE_CANCELED], [Order::STATE_COMPLETE], [Order::STATE_CLOSED], [Order::STATE_HOLDED]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('statesThatCannotBeAuthorized')]
    public function testApprovedPaymentForOrderThatCannotBeAuthorizedFails(string $state): void
    {
        // The controller logs this and answers 200 {"processed":false}; nothing is recorded on the order.
        $order = $this->order($state, false, false);
        $this->paymentStateManager = $this->createMock(PaymentStateManager::class);
        $this->paymentStateManager->expects($this->never())->method('markPaymentAuthorized');

        $this->expectException(\InvalidArgumentException::class);

        $this->processor()->process(self::approved(), $order);
    }

    public function testCanProcessOnlyAuthorizationEvents(): void
    {
        $order = $this->order(Order::STATE_PENDING_PAYMENT, false, false);
        $capture = self::declined();
        $capture['additionalData']['eventType'] = 'Capture';
        $noEventType = self::declined();
        unset($noEventType['additionalData']);

        $this->assertTrue($this->processor()->canProcess(self::declined(), $order));
        $this->assertFalse($this->processor()->canProcess($capture, $order));
        $this->assertFalse($this->processor()->canProcess($noEventType, $order));
    }
}
