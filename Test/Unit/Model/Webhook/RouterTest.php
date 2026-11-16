<?php
declare(strict_types=1);

namespace Straumur\Payment\Test\Unit\Model\Webhook;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Straumur\Payment\Api\Webhook\ProcessorInterface;
use Straumur\Payment\Gateway\Config\Config;
use Straumur\Payment\Helper\Data as StraumurHelper;
use Straumur\Payment\Model\Service\TransactionCalculator;
use Straumur\Payment\Model\Webhook\Command\RefundProcessor;
use Straumur\Payment\Model\Webhook\Command\VoidProcessor;
use Straumur\Payment\Model\Webhook\Router;

class RouterTest extends TestCase
{
    /**
     * The real void and refund processors; $captured is what the transaction calculator reports.
     *
     * @param bool $captured
     * @return array{void: VoidProcessor, refund: RefundProcessor}
     */
    private function voidAndRefund(bool $captured): array
    {
        $calculator = self::createStub(TransactionCalculator::class);
        $calculator->method('hasCapturedAmount')->willReturn($captured);
        $config = self::createStub(Config::class);
        $repository = self::createStub(OrderRepositoryInterface::class);

        return [
            'void' => new VoidProcessor($config, $repository, new NullLogger(), $calculator),
            'refund' => new RefundProcessor(
                $config,
                $repository,
                new NullLogger(),
                $calculator,
                self::createStub(StraumurHelper::class)
            ),
        ];
    }

    /**
     * @param string $eventType
     * @return array
     */
    private static function payload(string $eventType): array
    {
        return [
            'merchantReference' => '000000123',
            'success' => 'true',
            'additionalData' => ['eventType' => $eventType],
        ];
    }

    public function testUnsupportedEventTypeIsIgnored(): void
    {
        $order = self::createStub(OrderInterface::class);

        $this->assertSame(
            ['status' => 'ignored', 'reason' => 'unsupported_event'],
            (new Router(new NullLogger(), $this->voidAndRefund(false)))->route(self::payload('OfferClosed'), $order)
        );
    }

    public function testRefundOfUncapturedOrderGoesToVoid(): void
    {
        $order = self::createStub(OrderInterface::class);
        $processors = $this->voidAndRefund(false);

        $this->assertTrue($processors['void']->canProcess(self::payload('Refund'), $order));
        $this->assertFalse($processors['refund']->canProcess(self::payload('Refund'), $order));
    }

    public function testRefundOfCapturedOrderGoesToRefund(): void
    {
        $order = self::createStub(OrderInterface::class);
        $processors = $this->voidAndRefund(true);

        $this->assertFalse($processors['void']->canProcess(self::payload('Refund'), $order));
        $this->assertTrue($processors['refund']->canProcess(self::payload('Refund'), $order));
    }

    public function testRoutesToTheFirstProcessorThatAccepts(): void
    {
        $order = self::createStub(OrderInterface::class);
        $capture = $this->createMock(ProcessorInterface::class);
        $capture->method('canProcess')->willReturn(true);
        $capture->expects($this->once())->method('process')->with(self::payload('Capture'), $order)
            ->willReturn(['status' => 'success']);

        $router = new Router(new NullLogger(), $this->voidAndRefund(false) + ['capture' => $capture]);
        $result = $router->route(self::payload('Capture'), $order);

        $this->assertSame(['status' => 'success'], $result);
    }
}
