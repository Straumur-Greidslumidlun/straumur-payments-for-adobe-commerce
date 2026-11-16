<?php
declare(strict_types=1);

namespace Straumur\Payment\Test\Unit\Model\Webhook\Command;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Payment\Transaction;
use PHPUnit\Framework\Attributes\DataProvider;
use Straumur\Payment\Gateway\Config\Config;
use Straumur\Payment\Model\Service\TransactionCalculator;
use Straumur\Payment\Model\Webhook\Command\VoidProcessor;

class VoidProcessorTest extends ProcessorTestCase
{
    /**
     * @var TransactionCalculator
     */
    private $transactionCalculator;

    /**
     * @var Payment
     */
    private $payment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transactionCalculator = self::createStub(TransactionCalculator::class);
        $this->payment = self::createStub(Payment::class);
    }

    /**
     * @return VoidProcessor
     */
    private function processor(): VoidProcessor
    {
        return new VoidProcessor(
            self::createStub(Config::class),
            $this->orderRepository,
            $this->logger,
            $this->transactionCalculator
        );
    }

    /**
     * @param string $state
     * @return Order
     */
    private function order(string $state): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($this->payment);
        $order->method('getState')->willReturn($state);
        $order->method('getIncrementId')->willReturn('000000123');
        $order->method('canCancel')->willReturn(true);

        return $order;
    }

    public function testApprovedVoidRecordsTheVoidAndCancelsTheOrder(): void
    {
        $this->payment = $this->createMock(Payment::class);
        $order = $this->order(Order::STATE_NEW);
        $this->payment->expects($this->once())->method('setTransactionId')->with('PF-OP-void');
        $this->payment->expects($this->once())->method('addTransaction')->with(Transaction::TYPE_VOID);
        $order->expects($this->once())->method('cancel');
        $this->expectOrderSaved($order);

        $result = $this->processor()->process(self::payload('Refund'), $order);

        $this->assertSame('success', $result['status']);
        $this->assertSame('Void authorization', $result['reason']);
    }

    /**
     * @return array
     */
    public static function statesThatCannotBeVoided(): array
    {
        return [[Order::STATE_PROCESSING], [Order::STATE_COMPLETE], [Order::STATE_CANCELED]];
    }

    #[DataProvider('statesThatCannotBeVoided')]
    public function testApprovedVoidForOrderThatCannotBeVoidedFails(string $state): void
    {
        $this->payment = $this->createMock(Payment::class);
        $order = $this->order($state);
        $this->payment->expects($this->never())->method('addTransaction');
        $order->expects($this->never())->method('cancel');

        $this->expectException(\InvalidArgumentException::class);

        $this->processor()->process(self::payload('Refund'), $order);
    }

    public function testDeclinedVoidLeavesTheOrderAsItIs(): void
    {
        $this->payment = $this->createMock(Payment::class);
        $order = $this->order(Order::STATE_NEW);
        $this->payment->expects($this->never())->method('addTransaction');
        $order->expects($this->never())->method('cancel');
        $order->expects($this->once())->method('addCommentToStatusHistory');
        $this->expectOrderSaved($order);

        $result = $this->processor()->process(self::payload('Refund', 'false'), $order);

        $this->assertSame('failed', $result['status']);
    }

    public function testUnsignedPascalCaseSuccessDoesNotVoidTheOrder(): void
    {
        $this->payment = $this->createMock(Payment::class);
        $order = $this->order(Order::STATE_NEW);
        $this->payment->expects($this->never())->method('addTransaction');
        $order->expects($this->never())->method('cancel');

        $this->processor()->process(self::withUnsignedSuccess(self::payload('Refund', 'false')), $order);
    }

    public function testHandlesRefundEventsOnlyWhenNothingWasCaptured(): void
    {
        $order = self::createStub(Order::class);
        $this->transactionCalculator->method('hasCapturedAmount')->willReturn(false);

        $this->assertTrue($this->processor()->canProcess(self::payload('Refund'), $order));
        $this->assertFalse($this->processor()->canProcess(self::payload('Capture'), $order));

        $this->transactionCalculator = self::createStub(TransactionCalculator::class);
        $this->transactionCalculator->method('hasCapturedAmount')->willReturn(true);

        $this->assertFalse($this->processor()->canProcess(self::payload('Refund'), $order));
    }
}
