<?php
declare(strict_types=1);

namespace Straumur\Payment\Api;

interface StraumurApiInterface
{
    /**
     * Create a hosted checkout session
     *
     * @param array $sessionData
     * @return array
     * @throws \Exception
     */
    public function createSession(array $sessionData): array;

    /**
     * Create an embedded checkout session for headless clients
     *
     * @param array $sessionData
     * @return array
     * @throws \Exception
     */
    public function createEmbeddedSession(array $sessionData): array;

    /**
     * Capture a payment
     *
     * @param array $captureData
     * @return array
     * @throws \Exception
     */
    public function capture(array $captureData): array;

    /**
     * Get payment status
     *
     * @param string $checkoutReference
     * @return array
     * @throws \Exception
     */
    public function getStatus(string $checkoutReference): array;

    /**
     * Get embedded checkout status
     *
     * @param string $checkoutReference
     * @param int|null $storeId
     * @return array
     * @throws \Exception
     */
    public function getEmbeddedStatus(string $checkoutReference, ?int $storeId = null): array;

    /**
     * Refund a payment
     *
     * @param array $refundData
     * @return array
     * @throws \Exception
     */
    public function refund(array $refundData): array;

    /**
     * Reverse a payment (full refund)
     *
     * @param array $reverseData
     * @return array
     * @throws \Exception
     */
    public function reverse(array $reverseData): array;

}
