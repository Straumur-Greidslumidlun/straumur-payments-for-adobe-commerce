<?php
declare(strict_types=1);

namespace Straumur\Payment\Test\Unit\Model\Webhook\Command;

use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\ResourceModel\Order\Invoice\Collection as InvoiceCollection;
use Straumur\Payment\Gateway\Config\Config;
use Straumur\Payment\Model\Service\TransactionCalculator;
use Straumur\Payment\Model\Service\TransactionValidator;
use Straumur\Payment\Model\Webhook\Command\AdjustmentProcessor;

class AdjustmentProcessorTest extends ProcessorTestCase
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
     * @var CreditmemoFactory
     */
    private $creditmemoFactory;

    /**
     * @var CreditmemoRepositoryInterface
     */
    private $creditmemoRepository;

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
        $this->creditmemoFactory = self::createStub(CreditmemoFactory::class);
        $this->creditmemoRepository = self::createStub(CreditmemoRepositoryInterface::class);
        $this->payment = self::createStub(Payment::class);
        $this->order = $this->createMock(Order::class);
        $this->order->method('getPayment')->willReturn($this->payment);
        $this->order->method('getOrderCurrencyCode')->willReturn('ISK');
        $invoices = self::createStub(InvoiceCollection::class);
        $invoices->method('getFirstItem')->willReturn(self::createStub(Invoice::class));
        $this->order->method('getInvoiceCollection')->willReturn($invoices);
    }

    /**
     * @return AdjustmentProcessor
     */
    private function processor(): AdjustmentProcessor
    {
        return new AdjustmentProcessor(
            self::createStub(Config::class),
            $this->orderRepository,
            $this->logger,
            $this->creditmemoFactory,
            $this->creditmemoRepository,
            $this->transactionCalculator,
            $this->transactionValidator,
            self::helper()
        );
    }

    public function testApprovedAdjustmentIsValidatedRecordedAndCreditMemoCreated(): void
    {
        $this->transactionValidator = $this->createMock(TransactionValidator::class);
        $this->transactionValidator->expects($this->once())->method('validateAdjustment')->with($this->order, 5000.0);
        $this->transactionCalculator = $this->createMock(TransactionCalculator::class);
        $this->transactionCalculator->expects($this->once())->method('addTransactionToHistory')->with(
            $this->payment,
            'adjustments',
            $this->callback(function (array $entry): bool {
                // An empty reason from Straumur gets the default.
                return $entry['reference'] === 'PF-OP' && $entry['amount'] === 500000.0
                    && $entry['reason'] === 'Adjustment';
            })
        );
        $this->order->method('canCreditmemo')->willReturn(true);
        $creditmemo = self::createStub(Creditmemo::class);
        $this->creditmemoFactory = $this->createMock(CreditmemoFactory::class);
        $this->creditmemoFactory->expects($this->once())->method('createByOrder')
            ->with($this->order, ['adjustment_positive' => 5000.0])
            ->willReturn($creditmemo);
        $this->creditmemoRepository = $this->createMock(CreditmemoRepositoryInterface::class);
        $this->creditmemoRepository->expects($this->once())->method('save')->with($creditmemo);
        // "Payment adjustment processed" and "Credit memo created".
        $this->order->expects($this->exactly(2))->method('addCommentToStatusHistory');
        $this->expectOrderSaved($this->order);

        $result = $this->processor()->process(self::payload('Adjustment'), $this->order);

        $this->assertSame('success', $result['status']);
        $this->assertSame('Adjustment', $result['reason']);
    }

    public function testAdjustmentThatFailsValidationIsNotRecorded(): void
    {
        $this->transactionValidator->method('validateAdjustment')
            ->willThrowException(new \InvalidArgumentException('exceeds captured amount'));
        $this->transactionCalculator = $this->createMock(TransactionCalculator::class);
        $this->transactionCalculator->expects($this->never())->method('addTransactionToHistory');
        $this->order->expects($this->never())->method('addCommentToStatusHistory');

        $this->expectException(\InvalidArgumentException::class);

        $this->processor()->process(self::payload('Adjustment'), $this->order);
    }

    public function testDeclinedAdjustmentOnlyAddsAComment(): void
    {
        $this->transactionCalculator = $this->createMock(TransactionCalculator::class);
        $this->transactionCalculator->expects($this->never())->method('addTransactionToHistory');
        $this->creditmemoFactory = $this->createMock(CreditmemoFactory::class);
        $this->creditmemoFactory->expects($this->never())->method('createByOrder');
        $this->order->expects($this->once())->method('addCommentToStatusHistory');
        $this->expectOrderSaved($this->order);

        $payload = self::payload('Adjustment', 'false');
        $payload['reason'] = 'Insufficient funds';
        $result = $this->processor()->process($payload, $this->order);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('Insufficient funds', $result['reason']);
    }

    public function testUnsignedPascalCaseSuccessDoesNotTurnADeclineIntoAnAdjustment(): void
    {
        $this->transactionCalculator = $this->createMock(TransactionCalculator::class);
        $this->transactionCalculator->expects($this->never())->method('addTransactionToHistory');
        $this->order->expects($this->once())->method('addCommentToStatusHistory');

        $result = $this->processor()->process(
            self::withUnsignedSuccess(self::payload('Adjustment', 'false')),
            $this->order
        );

        $this->assertSame('failed', $result['status']);
    }
}
