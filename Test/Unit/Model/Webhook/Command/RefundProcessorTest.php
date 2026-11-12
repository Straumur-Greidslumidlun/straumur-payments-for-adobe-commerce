<?php
declare(strict_types=1);

namespace Straumur\Payment\Test\Unit\Model\Webhook\Command;

use Magento\Sales\Model\Order;
use Straumur\Payment\Gateway\Config\Config;
use Straumur\Payment\Model\Service\TransactionCalculator;
use Straumur\Payment\Model\Webhook\Command\RefundProcessor;

class RefundProcessorTest extends ProcessorTestCase
{
    /**
     * @var TransactionCalculator
     */
    private $transactionCalculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transactionCalculator = self::createStub(TransactionCalculator::class);
    }

    /**
     * @return RefundProcessor
     */
    private function processor(): RefundProcessor
    {
        return new RefundProcessor(
            self::createStub(Config::class),
            $this->orderRepository,
            $this->logger,
            $this->transactionCalculator,
            self::helper()
        );
    }

    /**
     * @return Order
     */
    private function order(): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('getOrderCurrencyCode')->willReturn('ISK');

        return $order;
    }

    public function testApprovedRefundIsConfirmedOnTheOrder(): void
    {
        $order = $this->order();
        $order->expects($this->once())->method('addCommentToStatusHistory')->with($this->callback(
            function ($comment): bool {
                return (string) $comment
                    === 'Refund request confirmed by Straumur. Amount: 5000, Transaction ID: PF-OP';
            }
        ));
        $this->expectOrderSaved($order);

        $result = $this->processor()->process(self::payload('Refund'), $order);

        $this->assertSame('success', $result['status']);
        $this->assertSame(5000.0, $result['amount']);
        $this->assertSame('Customer request', $result['reason']);
    }

    public function testDeclinedRefundIsRecordedWithItsReason(): void
    {
        $order = $this->order();
        $order->expects($this->once())->method('addCommentToStatusHistory')->with($this->callback(
            function ($comment): bool {
                return (string) $comment
                    === 'Refund failed via webhook. Amount: 5000, Reason: Card expired, Transaction ID: PF-OP';
            }
        ));
        $this->expectOrderSaved($order);

        $payload = self::payload('Refund', 'false');
        $payload['reason'] = 'Card expired';
        $result = $this->processor()->process($payload, $order);

        $this->assertSame('failed', $result['status']);
    }

    public function testUnsignedPascalCaseSuccessDoesNotConfirmADeclinedRefund(): void
    {
        $order = $this->order();
        $order->expects($this->once())->method('addCommentToStatusHistory')->with($this->callback(
            function ($comment): bool {
                return strpos((string) $comment, 'Refund failed') === 0;
            }
        ));

        $this->processor()->process(self::withUnsignedSuccess(self::payload('Refund', 'false')), $order);
    }

    public function testHandlesRefundEventsOnlyWhenSomethingWasCaptured(): void
    {
        $order = self::createStub(Order::class);
        $this->transactionCalculator->method('hasCapturedAmount')->willReturn(true);

        $this->assertTrue($this->processor()->canProcess(self::payload('Refund'), $order));
        $this->assertFalse($this->processor()->canProcess(self::payload('Capture'), $order));

        $this->transactionCalculator = self::createStub(TransactionCalculator::class);
        $this->transactionCalculator->method('hasCapturedAmount')->willReturn(false);

        $this->assertFalse($this->processor()->canProcess(self::payload('Refund'), $order));
    }
}
