<?php
declare(strict_types=1);

namespace Straumur\Payment\Gateway\Command;

use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Payment\Gateway\Response\HandlerInterface;
use Magento\Payment\Gateway\Http\TransferFactoryInterface;
use Magento\Payment\Gateway\Http\ClientInterface;
use Magento\Payment\Gateway\Validator\ValidatorInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

class AuthorizeCommand implements CommandInterface
{
    /**
     * @var BuilderInterface
     */
    private $requestBuilder;

    /**
     * @var TransferFactoryInterface
     */
    private $transferFactory;

    /**
     * @var ClientInterface
     */
    private $client;

    /**
     * @var HandlerInterface
     */
    private $responseHandler;

    /**
     * @var ValidatorInterface|null
     */
    private $validator;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var LoggerInterface
     */
    private $systemLogger;

    /**
     * @param BuilderInterface $requestBuilder
     * @param TransferFactoryInterface $transferFactory
     * @param ClientInterface $client
     * @param HandlerInterface $responseHandler
     * @param LoggerInterface $logger
     * @param LoggerInterface $systemLogger
     * @param ValidatorInterface|null $validator
     */
    public function __construct(
        BuilderInterface $requestBuilder,
        TransferFactoryInterface $transferFactory,
        ClientInterface $client,
        HandlerInterface $responseHandler,
        LoggerInterface $logger,
        LoggerInterface $systemLogger,
        ?ValidatorInterface $validator = null
    ) {
        $this->requestBuilder = $requestBuilder;
        $this->transferFactory = $transferFactory;
        $this->client = $client;
        $this->responseHandler = $responseHandler;
        $this->logger = $logger;
        $this->systemLogger = $systemLogger;
        $this->validator = $validator;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $commandSubject): array
    {
        $this->logger->info('Executing authorize command', ['subject' => $commandSubject]);

        try {
            // Log the starting point with order details
            $paymentDO = \Magento\Payment\Gateway\Helper\SubjectReader::readPayment($commandSubject);
            $order = $paymentDO->getOrder();
            $payment = $paymentDO->getPayment();
            
            $this->logger->info('[FLOW_TRACKER] AuthorizeCommand starting', [
                'order_id' => $order->getOrderIncrementId(),
                'order_state' => method_exists($order, 'getState') ? $order->getState() : 'unknown',
                'payment_method' => $payment->getMethod(),
                'command_subject' => array_keys($commandSubject)
            ]);
            
            $this->logger->info('Building request for authorization');
            
            // Build the request
            try {
                $request = $this->requestBuilder->build($commandSubject);
                $this->logger->info('Request built successfully', [
                    'has_request' => !empty($request),
                    'request_keys' => array_keys($request),
                    'request' => $request
                ]);
            } catch (\Exception $e) {
                $this->logger->error('Request builder failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                throw $e;
            }
            
            // Create transfer object
            $this->logger->info('Creating transfer object');
            $transferObject = $this->transferFactory->create($request);
            $this->logger->info('Transfer object created', [
                'uri' => $transferObject->getUri(),
                'method' => $transferObject->getMethod(),
                'headers' => $transferObject->getHeaders()
            ]);
            
            // Execute HTTP request
            $this->logger->info('Sending request to Straumur API');
            $response = $this->client->placeRequest($transferObject);
            $this->logger->info('Response received from Straumur', ['response' => $response]);
            
            // Validate response if validator is provided
            if ($this->validator) {
                $validationSubject = array_merge($commandSubject, ['response' => $response]);
                $result = $this->validator->validate($validationSubject);
                
                if (!$result->isValid()) {
                    $errorMessages = implode(', ', $result->getFailsDescription());
                    throw new LocalizedException(__('Response validation failed: %1', $errorMessages));
                }
            }
            
            // Handle the response
            $this->responseHandler->handle($commandSubject, $response);
            
            $this->logger->info('[FLOW_TRACKER] AuthorizeCommand completed', [
                'order_id' => $order->getOrderIncrementId(),
                'response_keys' => array_keys($response)
            ]);
            
            $this->logger->info('Authorize command completed successfully');
            
            return $response;
            
        } catch (\Exception $e) {
            $this->logger->error('Authorize command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'class' => get_class($e)
            ]);
            
            // Also log to system.log for visibility
            $this->systemLogger->error('Straumur Authorization Failed: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
            
            throw new LocalizedException(__('Authorization failed: %1', $e->getMessage()), $e);
        }
    }
}
