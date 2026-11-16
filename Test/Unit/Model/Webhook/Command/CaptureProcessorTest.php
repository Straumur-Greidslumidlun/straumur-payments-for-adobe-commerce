<?php
declare(strict_types=1);

namespace Straumur\Payment\Test\Unit\Model\Webhook\Command;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Config as OrderConfig;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Payment\Transaction;
use Straumur\Payment\Gateway\Config\Config;
use Straumur\Payment\Model\Service\TransactionCalculator;
use Straumur\Payment\Model\Service\TransactionValidator;
use Straumur\Payment\Model\Webhook\Command\CaptureProcessor;

class CaptureProcessorTest extends ProcessorTestCase
{
    /**
     * @var TransactionCalculator
     */
    private $transactionCalculator;

    /**
     * @var TransactionValidator
     */
    private $transactionValidator;

    /**
     * @var Payment
     */
    private $payment;

    /**
     * @var Order
     */
    private $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transactionCalculator = self::createStub(TransactionCalculator::class);
        $this->transactionValidator = self::createStub(TransactionValidator::class);
        $this->payment = $this->createMock(Payment::class);
        $this->order = $this->createMock(Order::class);
        $this->order->method('getPayment')->willReturn($this->payment);
        $this->order->method('getIncrementId')->willReturn('000000123');
        $this->order->method('getOrderCurrencyCode')->willReturn('ISK');
        $orderConfig = self::createStub(OrderConfig::class);
        $orderConfig->method('getStateDefaultStatus')->willReturn('processing');
        $this->order->method('getConfig')->willReturn($orderConfig);
    }

    /**
     * @return CaptureProcessor
     */
    private function processor(): CaptureProcessor
    {
        return new CaptureProcessor(
            self::createStub(Config::class),
            $this->orderRepository,
            $this->logger,
            self::createStub(InvoiceSender::class),
            $this->transactionCalculator,
            $this->transactionValidator,
            self::helper()
        );
    }

    public function testApprovedCaptureIsRecordedInvoicedAndMovesOrderToProcessing(): void
    {
        $this->transactionCalculator = $this->createMock(TransactionCalculator::class);
        $this->transactionCalculator->method('getTransactionHistory')->willReturn([]);
        $this->transactionCalculator->expects($this->once())->method('addTransactionToHistory')->with(
            $this->payment,
            'captures',
            $this->callback(function (array $entry): bool {
                return $entry['reference'] === 'PF-OP' && $entry['amount'] === 500000.0
                    && $entry['transaction_id'] === 'PF-OP-capture';
            })
        );
        $this->transactionValidator = $this->createMock(TransactionValidator::class);
        $this->transactionValidator->expects($this->once())->method('validateCapture')->with($this->order, 5000.0);
        $this->payment->expects($this->once())->method('addTransaction')->with(Transaction::TYPE_CAPTURE);
        $invoice = $this->createMock(Invoice::class);
        $invoice->expects($this->once())->method('register');
        $this->order->method('canInvoice')->willReturn(true);
        $this->order->expects($this->once())->method('prepareInvoice')->willReturn($invoice);
        $this->order->expects($this->once())->method('setState')->with(Order::STATE_PROCESSING);
        $this->expectOrderSaved($this->order);

        $result = $this->processor()->process(self::payload('Capture'), $this->order);

        $this->assertSame('success', $result['status']);
        $this->assertSame(500000.0, $result['amount']);
    }

    public function testDuplicateCaptureIsNotRecordedTwice(): void
    {
        $this->transactionCalculator = $this->createMock(TransactionCalculator::class);
        $this->transactionCalculator->method('getTransactionHistory')
            ->willReturn([['reference' => 'PF-OP', 'date' => '2026-11-01 10:00:00']]);
        $this->transactionCalculator->expects($this->never())->method('addTransactionToHistory');
        $this->payment->expects($this->never())->method('addTransaction');
        $this->order->expects($this->never())->method('prepareInvoice');

        $result = $this->processor()->process(self::payload('Capture'), $this->order);

        $this->assertSame('Duplicate capture webhook ignored', $result['note']);
    }

    public function testCaptureThatFailsValidationIsNotRecorded(): void
    {
        $this->transactionCalculator = $this->createMock(TransactionCalculator::class);
        $this->transactionCalculator->method('getTransactionHistory')->willReturn([]);
        $this->transactionCalculator->expects($this->never())->method('addTransactionToHistory');
        $this->transactionValidator->method('validateCapture')
            ->willThrowException(new \InvalidArgumentException('more than authorized'));
        $this->payment->expects($this->never())->method('addTransaction');
        $this->order->expects($this->never())->method('prepareInvoice');

        $this->expectException(\InvalidArgumentException::class);

        $this->processor()->process(self::payload('Capture'), $this->order);
    }

    public function testDeclinedCaptureOnlyAddsAComment(): void
    {
        $this->transactionCalculator = $this->createMock(TransactionCalculator::class);
        $this->transactionCalculator->expects($this->never())->method('addTransactionToHistory');
        $this->payment->expects($this->never())->method('addTransaction');
        $this->order->expects($this->never())->method('setState');
        $this->order->expects($this->once())->method('addCommentToStatusHistory');
        $this->expectOrderSaved($this->order);

        $result = $this->processor()->process(self::payload('Capture', 'false'), $this->order);

        $this->assertSame('failed', $result['status']);
    }

    public function testUnsignedPascalCaseSuccessDoesNotTurnADeclineIntoACapture(): void
    {
        $this->payment->expects($this->never())->method('addTransaction');
        $this->order->expects($this->never())->method('setState');

        $result = $this->processor()->process(
            self::withUnsignedSuccess(self::payload('Capture', 'false')),
            $this->order
        );

        $this->assertSame('failed', $result['status']);
    }
}
