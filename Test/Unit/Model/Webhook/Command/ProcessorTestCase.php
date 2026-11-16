<?php
declare(strict_types=1);

namespace Straumur\Payment\Test\Unit\Model\Webhook\Command;

use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Straumur\Payment\Helper\Data as StraumurHelper;
use Straumur\Payment\Test\Unit\RecordingLogger;

/**
 * Shared fixtures for the webhook processor tests.
 */
abstract class ProcessorTestCase extends TestCase
{
    /**
     * @var OrderRepositoryInterface Stub by default; a mock in tests that set expectations
     */
    protected $orderRepository;

    /**
     * @var RecordingLogger
     */
    protected $logger;

    protected function setUp(): void
    {
        $this->orderRepository = self::createStub(OrderRepositoryInterface::class);
        $this->logger = new RecordingLogger();
    }

    /**
     * Expect the order to be saved exactly once.
     *
     * @param object $order
     * @return void
     */
    protected function expectOrderSaved(object $order): void
    {
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->orderRepository->expects($this->once())->method('save')->with($order);
    }

    /**
     * Helper that converts ISK minor units the way Straumur sends them (two trailing zeros).
     *
     * @return StraumurHelper
     */
    protected static function helper(): StraumurHelper
    {
        $helper = self::createStub(StraumurHelper::class);
        $helper->method('convertFromMinorUnits')->willReturnCallback(function ($minor) {
            return (float) $minor / 100;
        });

        return $helper;
    }

    /**
     * A signed Straumur payload for a follow-up event, as the backend sends it.
     *
     * @param string $eventType
     * @param string $success "true" or "false"
     * @return array
     */
    protected static function payload(string $eventType, string $success = 'true'): array
    {
        return [
            'merchantReference' => '000000123',
            'checkoutReference' => 'chk-op',
            'payfacReference' => 'PF-OP',
            'amount' => '500000',
            'currency' => 'ISK',
            'reason' => null,
            'success' => $success,
            'additionalData' => ['eventType' => $eventType, 'originalPayfacReference' => 'PF-AUTH'],
        ];
    }

    /**
     * The payload with an unsigned PascalCase "Success" that contradicts the signed "success".
     *
     * @param array $payload
     * @return array
     */
    protected static function withUnsignedSuccess(array $payload): array
    {
        $payload['Success'] = $payload['success'] === 'true' ? 'false' : 'true';

        return $payload;
    }
}
