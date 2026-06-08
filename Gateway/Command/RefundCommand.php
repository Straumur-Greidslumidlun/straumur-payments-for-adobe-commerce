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

class RefundCommand implements CommandInterface
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
     * @param BuilderInterface $requestBuilder
     * @param TransferFactoryInterface $transferFactory
     * @param ClientInterface $client
     * @param HandlerInterface $responseHandler
     * @param LoggerInterface $logger
     * @param ValidatorInterface|null $validator
     */
    public function __construct(
        BuilderInterface $requestBuilder,
        TransferFactoryInterface $transferFactory,
        ClientInterface $client,
        HandlerInterface $responseHandler,
        LoggerInterface $logger,
        ?ValidatorInterface $validator = null
    ) {
        $this->requestBuilder = $requestBuilder;
        $this->transferFactory = $transferFactory;
        $this->client = $client;
        $this->responseHandler = $responseHandler;
        $this->logger = $logger;
        $this->validator = $validator;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $commandSubject): array
    {
        $this->logger->info('Executing refund command', ['subject' => $commandSubject]);

        try {
            $paymentDO = SubjectReader::readPayment($commandSubject);
            $payment = $paymentDO->getPayment();
            
            if (!$payment->getParentTransactionId() && !$payment->getLastTransId()) {
                throw new LocalizedException(__('Cannot refund payment without capture or authorization'));
            }

            $request = $this->requestBuilder->build($commandSubject);
            $transferObject = $this->transferFactory->create($request);
            $response = $this->client->placeRequest($transferObject);

            if ($this->validator) {
                $validationSubject = array_merge($commandSubject, ['response' => $response]);
                $result = $this->validator->validate($validationSubject);
                
                if (!$result->isValid()) {
                    $errorMessages = implode(', ', $result->getFailsDescription());
                    throw new LocalizedException(__('Refund validation failed: %1', $errorMessages));
                }
            }

            $this->responseHandler->handle($commandSubject, $response);
            
            $this->logger->info('Refund command completed successfully');
            
            return $response;
            
        } catch (\Exception $e) {
            $this->logger->error('Refund command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw new LocalizedException(__('Refund failed: %1', $e->getMessage()), $e);
        }
    }
}
