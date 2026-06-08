<?php
declare(strict_types=1);

namespace Straumur\Payment\Model\Service;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Math\Random;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * Secure session management for Straumur payment sessions
 * 
 * Manages secure session tokens that map to checkout references with proper expiry
 */
class SessionManager
{
    private const CACHE_KEY_PREFIX = 'straumur_session_';
    private const TOKEN_LENGTH = 32;
    private const DEFAULT_EXPIRY_HOURS = 1;
    private const MAX_EXPIRY_HOURS = 24;

    /**
     * @var CacheInterface
     */
    private $cache;

    /**
     * @var EncryptorInterface
     */
    private $encryptor;

    /**
     * @var Random
     */
    private $random;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param CacheInterface $cache
     * @param EncryptorInterface $encryptor
     * @param Random $random
     * @param LoggerInterface $logger
     */
    public function __construct(
        CacheInterface $cache,
        EncryptorInterface $encryptor,
        Random $random,
        LoggerInterface $logger
    ) {
        $this->cache = $cache;
        $this->encryptor = $encryptor;
        $this->random = $random;
        $this->logger = $logger;
    }

    /**
     * Create a secure session token for the checkout reference
     * 
     * @param string $checkoutReference The Straumur checkout reference
     * @param string|null $expiresAt ISO 8601 expiry time from Straumur session
     * @return string Secure session token
     * @throws LocalizedException
     */
    public function createSession(string $checkoutReference, ?string $expiresAt = null): string
    {
        try {
            // Generate secure random token
            $sessionToken = $this->random->getRandomString(self::TOKEN_LENGTH);
            
            // Calculate expiry timestamp
            $expiryTimestamp = $this->calculateExpiryTimestamp($expiresAt);
            
            // Store session data
            $sessionData = [
                'checkout_reference' => $checkoutReference,
                'created_at' => time(),
                'expires_at' => $expiryTimestamp
            ];
            
            // Store in cache with TTL
            $cacheKey = self::CACHE_KEY_PREFIX . $sessionToken;
            $cacheTtl = $expiryTimestamp - time();
            
            $this->cache->save(
                json_encode($sessionData),
                $cacheKey,
                [],
                $cacheTtl
            );
            
            $this->logger->info('Secure session created', [
                'session_token' => $sessionToken,
                'checkout_reference' => $checkoutReference,
                'expires_at' => date('Y-m-d H:i:s', $expiryTimestamp),
                'ttl_seconds' => $cacheTtl
            ]);
            
            return $sessionToken;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to create secure session', [
                'checkout_reference' => $checkoutReference,
                'error' => $e->getMessage()
            ]);
            throw new LocalizedException(__('Failed to create secure session'));
        }
    }

    /**
     * Validate session token and return checkout reference if valid
     * 
     * @param string $sessionToken
     * @return array|null Session data or null if invalid/expired
     */
    public function validateSession(string $sessionToken): ?array
    {
        try {
            $cacheKey = self::CACHE_KEY_PREFIX . $sessionToken;
            $sessionDataJson = $this->cache->load($cacheKey);
            
            if (empty($sessionDataJson)) {
                $this->logger->warning('Session token not found or expired', [
                    'session_token' => $sessionToken
                ]);
                return null;
            }
            
            $sessionData = json_decode($sessionDataJson, true);
            
            if (!is_array($sessionData) || !isset($sessionData['checkout_reference'])) {
                $this->logger->error('Invalid session data format', [
                    'session_token' => $sessionToken,
                    'session_data' => $sessionDataJson
                ]);
                return null;
            }
            
            // Check if expired
            if (time() > $sessionData['expires_at']) {
                $this->logger->warning('Session expired', [
                    'session_token' => $sessionToken,
                    'expired_at' => date('Y-m-d H:i:s', $sessionData['expires_at'])
                ]);
                $this->cache->remove($cacheKey);
                return null;
            }
            
            $this->logger->info('Session validated successfully', [
                'session_token' => $sessionToken,
                'checkout_reference' => $sessionData['checkout_reference']
            ]);
            
            return $sessionData;
            
        } catch (\Exception $e) {
            $this->logger->error('Session validation failed', [
                'session_token' => $sessionToken,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Consume session token (mark as used, prevent reuse)
     * 
     * @param string $sessionToken
     * @return bool Success
     */
    public function consumeSession(string $sessionToken): bool
    {
        try {
            $sessionData = $this->validateSession($sessionToken);
            
            if (!$sessionData) {
                return false;
            }
            
            // Remove from cache to prevent reuse
            $cacheKey = self::CACHE_KEY_PREFIX . $sessionToken;
            $this->cache->remove($cacheKey);
            
            $this->logger->info('Session consumed', [
                'session_token' => $sessionToken,
                'checkout_reference' => $sessionData['checkout_reference']
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to consume session', [
                'session_token' => $sessionToken,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Calculate expiry timestamp from Straumur expiresAt or default
     * 
     * @param string|null $expiresAt ISO 8601 timestamp from Straumur
     * @return int Unix timestamp
     * @throws LocalizedException
     */
    private function calculateExpiryTimestamp(?string $expiresAt = null): int
    {
        if ($expiresAt) {
            $expiryTime = strtotime($expiresAt);
            if ($expiryTime === false) {
                throw new LocalizedException(__('Invalid expiresAt format: %1', $expiresAt));
            }
            
            // Validate maximum 24 hours from now
            $maxExpiry = time() + (self::MAX_EXPIRY_HOURS * 3600);
            if ($expiryTime > $maxExpiry) {
                throw new LocalizedException(__('Session expiry exceeds maximum of %1 hours', self::MAX_EXPIRY_HOURS));
            }
            
            return $expiryTime;
        }
        
        // Default to 1 hour
        return time() + (self::DEFAULT_EXPIRY_HOURS * 3600);
    }

    /**
     * Clean up expired sessions (for cron if needed)
     * 
     * @return int Number of sessions cleaned
     */
    public function cleanExpiredSessions(): int
    {
        // Cache auto-expires, but we could implement manual cleanup if needed
        $this->logger->info('Session cleanup requested (using cache auto-expiry)');
        return 0;
    }
}