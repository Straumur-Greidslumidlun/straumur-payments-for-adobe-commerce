<?php
declare(strict_types=1);

namespace Straumur\Payment\Test\Unit\Controller\Webhook;

use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Straumur\Payment\Api\Webhook\RouterInterface;
use Straumur\Payment\Controller\Webhook\Index;
use Straumur\Payment\Helper\Data as StraumurHelper;
use Straumur\Payment\Model\Webhook\Command\PaymentValidator;
use Straumur\Payment\Model\Webhook\OrderResolver;
use Straumur\Payment\Test\Unit\Model\Security\HmacValidatorTest;
use Straumur\Payment\Test\Unit\RecordingLogger;

/**
 * The endpoint with the real signature check and order resolution; only Magento's persistence and HTTP
 * objects and the processor router are mocked.
 */
class IndexTest extends TestCase
{
    /**
     * @var string
     */
    private $secret = HmacValidatorTest::SECRET;

    /**
     * @var string[]
     */
    private $allowedIps = [];

    /**
     * @var string
     */
    private $clientIp = '203.0.113.10';

    /**
     * @var OrderInterface[] What the order repository finds
     */
    private $ordersInDatabase = [];

    /**
     * @var RouterInterface Stub by default; a mock in tests that set expectations
     */
    private $router;

    /**
     * @var RecordingLogger
     */
    private $logger;

    /**
     * @var int|null
     */
    private $statusCode;

    /**
     * @var array|null
     */
    private $body;

    protected function setUp(): void
    {
        $this->router = self::createStub(RouterInterface::class);
        $this->logger = new RecordingLogger();
    }

    /**
     * POST the given raw body to the endpoint and record the response.
     *
     * @param string $rawBody
     * @return void
     */
    private function post(string $rawBody): void
    {
        $request = self::createStub(Http::class);
        $request->method('getContent')->willReturn($rawBody);

        $result = self::createStub(Json::class);
        $result->method('setHttpResponseCode')->willReturnCallback(function (int $code) use ($result) {
            $this->statusCode = $code;
            return $result;
        });
        $result->method('setData')->willReturnCallback(function (array $data) use ($result) {
            $this->body = $data;
            return $result;
        });
        $jsonFactory = self::createStub(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($result);

        $remoteAddress = self::createStub(RemoteAddress::class);
        $remoteAddress->method('getRemoteAddress')->willReturn($this->clientIp);

        $helper = self::createStub(StraumurHelper::class);
        $helper->method('getWebhookSecret')->willReturn($this->secret);
        $helper->method('getWebhookAllowedIps')->willReturn($this->allowedIps);

        $controller = new Index(
            $request,
            $jsonFactory,
            $this->router,
            $this->logger,
            $remoteAddress,
            $helper,
            new PaymentValidator(HmacValidatorTest::createValidator(), $helper, new NullLogger()),
            $this->orderResolver()
        );

        $controller->execute();
    }

    /**
     * OrderResolver over a mocked order repository that returns $ordersInDatabase.
     *
     * @return OrderResolver
     */
    private function orderResolver(): OrderResolver
    {
        $builder = self::createStub(SearchCriteriaBuilder::class);
        $builder->method('addFilter')->willReturnSelf();
        $builder->method('create')->willReturn(self::createStub(SearchCriteria::class));

        $repository = self::createStub(OrderRepositoryInterface::class);
        $repository->method('getList')->willReturnCallback(function () {
            $result = self::createStub(OrderSearchResultInterface::class);
            $result->method('getItems')->willReturn($this->ordersInDatabase);
            return $result;
        });

        return new OrderResolver($repository, $builder, new NullLogger());
    }

    /**
     * A Straumur order with increment ID 000000123, the reference in HmacValidatorTest::orderPayload().
     *
     * @return OrderInterface
     */
    private function shopOrder(): OrderInterface
    {
        $payment = self::createStub(OrderPaymentInterface::class);
        $payment->method('getMethod')->willReturn('straumur_payment');
        $payment->method('getAdditionalInformation')->willReturn([]);

        $order = self::createStub(OrderInterface::class);
        $order->method('getIncrementId')->willReturn('000000123');
        $order->method('getPayment')->willReturn($payment);

        return $order;
    }

    /**
     * @param array $payload
     * @return string
     */
    private static function json(array $payload): string
    {
        return (string) json_encode($payload);
    }

    public function testSignedEventForShopOrderIsProcessed(): void
    {
        $order = $this->shopOrder();
        $this->ordersInDatabase = [$order];
        $this->router = $this->createMock(RouterInterface::class);
        $this->router->expects($this->once())
            ->method('route')
            ->with(HmacValidatorTest::orderPayload(), $order)
            ->willReturn(['status' => 'success']);

        $this->post(self::json(HmacValidatorTest::orderPayload()));

        $this->assertSame(200, $this->statusCode);
        $this->assertSame(['message' => Index::MESSAGE_PROCESSED], $this->body);
    }

    public function testSignedTerminalPaymentIsAcknowledgedAsIgnored(): void
    {
        $this->router = $this->createMock(RouterInterface::class);
        $this->router->expects($this->never())->method('route');

        $this->post(self::json(HmacValidatorTest::terminalPayload()));

        $this->assertSame(200, $this->statusCode);
        $this->assertSame(['message' => 'Ignored: not an order of this shop.', 'ignored' => true], $this->body);
    }

    public function testSignedEventForUnknownOrderIsAcknowledgedAsIgnored(): void
    {
        $this->ordersInDatabase = [];
        $this->router = $this->createMock(RouterInterface::class);
        $this->router->expects($this->never())->method('route');

        $this->post(self::json(HmacValidatorTest::orderPayload()));

        $this->assertSame(200, $this->statusCode);
        $this->assertSame(['message' => Index::MESSAGE_IGNORED_FOREIGN, 'ignored' => true], $this->body);
    }

    public function testIgnoredEventIsLoggedWithAllowListedFieldsOnly(): void
    {
        $payload = HmacValidatorTest::orderPayload();
        $payload['additionalData']['shopperInformation'] = [
            'fullName' => 'Jon Jonsson',
            'emailAddress' => 'jon@example.com',
        ];
        $payload['additionalData']['cardSummary'] = '1234';
        // The signature stays valid: additionalData is not part of it.

        $this->post(self::json($payload));

        $this->assertSame(200, $this->statusCode);
        $logged = $this->logger->dump();
        $mustNotBeLogged = [
            'Jon Jonsson',
            'jon@example.com',
            '000000123',
            'PF-1',
            'c1a2b3',
            '1234',
            HmacValidatorTest::ORDER_SIGNATURE,
        ];
        foreach ($mustNotBeLogged as $value) {
            $this->assertStringNotContainsString($value, $logged);
        }
    }

    public function testUnsupportedEventTypeForShopOrderIsAcknowledgedAsIgnored(): void
    {
        $this->ordersInDatabase = [$this->shopOrder()];
        $this->router->method('route')->willReturn(['status' => 'ignored', 'reason' => 'unsupported_event']);

        $this->post(self::json(HmacValidatorTest::orderPayload()));

        $this->assertSame(200, $this->statusCode);
        $this->assertSame(['message' => Index::MESSAGE_IGNORED_EVENT, 'ignored' => true], $this->body);
    }

    public function testProcessingExceptionIsLoggedAndStillAcknowledged(): void
    {
        $this->ordersInDatabase = [$this->shopOrder()];
        $this->router->method('route')
            ->willThrowException(new \RuntimeException('Order 000000123 cannot be authorized'));

        $this->post(self::json(HmacValidatorTest::orderPayload()));

        $this->assertSame(200, $this->statusCode);
        $this->assertSame(['message' => Index::MESSAGE_FAILED, 'processed' => false], $this->body);
        $this->assertCount(1, $this->logger->messages('error'));
    }

    public function testPhpErrorIsLoggedAndStillAcknowledged(): void
    {
        $this->ordersInDatabase = [$this->shopOrder()];
        $this->router->method('route')->willThrowException(new \TypeError('bad argument'));

        $this->post(self::json(HmacValidatorTest::orderPayload()));

        $this->assertSame(200, $this->statusCode);
        $this->assertSame(['message' => Index::MESSAGE_FAILED, 'processed' => false], $this->body);
    }

    public function testUnsignedEventIsRejectedBeforeLookingAtTheOrder(): void
    {
        $this->ordersInDatabase = [$this->shopOrder()];
        $this->router = $this->createMock(RouterInterface::class);
        $this->router->expects($this->never())->method('route');
        $payload = HmacValidatorTest::orderPayload();
        unset($payload['hmacSignature']);

        $this->post(self::json($payload));

        $this->assertSame(403, $this->statusCode);
    }

    public function testWrongSignatureIsRejected(): void
    {
        $this->ordersInDatabase = [$this->shopOrder()];
        $this->router = $this->createMock(RouterInterface::class);
        $this->router->expects($this->never())->method('route');
        $payload = HmacValidatorTest::orderPayload();
        $payload['amount'] = '1';

        $this->post(self::json($payload));

        $this->assertSame(403, $this->statusCode);
    }

    public function testMissingSecretRejectsEverything(): void
    {
        $this->secret = '';
        $this->router = $this->createMock(RouterInterface::class);
        $this->router->expects($this->never())->method('route');

        $this->post(self::json(HmacValidatorTest::terminalPayload()));

        $this->assertSame(403, $this->statusCode);
    }

    public function testInvalidJsonIsBadRequest(): void
    {
        $this->post('{"merchantReference": ');

        $this->assertSame(400, $this->statusCode);
    }

    /**
     * @return array
     */
    public static function jsonThatIsNotAnObject(): array
    {
        return [
            'string' => ['"742"'],
            'number' => ['742'],
            'empty array' => ['[]'],
            'array of scalars' => ['[1]'],
            'array of objects' => ['[{"merchantReference":"000000123"}]'],
        ];
    }

    #[DataProvider('jsonThatIsNotAnObject')]
    public function testJsonThatIsNotAnObjectIsBadRequest(string $body): void
    {
        $this->router = $this->createMock(RouterInterface::class);
        $this->router->expects($this->never())->method('route');

        $this->post($body);

        $this->assertSame(400, $this->statusCode);
    }

    public function testEmptyJsonObjectIsCheckedForSignature(): void
    {
        $this->post(' {}');

        $this->assertSame(403, $this->statusCode);
    }

    public function testEmptyBodyIsBadRequest(): void
    {
        $this->post('');

        $this->assertSame(400, $this->statusCode);
    }

    public function testIpOutsideAllowListIsForbidden(): void
    {
        $this->allowedIps = ['198.51.100.0/24'];
        $this->router = $this->createMock(RouterInterface::class);
        $this->router->expects($this->never())->method('route');

        $this->post(self::json(HmacValidatorTest::terminalPayload()));

        $this->assertSame(403, $this->statusCode);
    }

    public function testIpInsideAllowListIsAccepted(): void
    {
        $this->allowedIps = ['198.51.100.0/24'];
        $this->clientIp = '198.51.100.7';

        $this->post(self::json(HmacValidatorTest::terminalPayload()));

        $this->assertSame(200, $this->statusCode);
    }
}
