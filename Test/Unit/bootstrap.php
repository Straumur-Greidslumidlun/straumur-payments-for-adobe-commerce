<?php
/**
 * Unit test bootstrap: Composer autoloading plus the global __() translation function, which a Magento
 * installation defines in app/functions.php rather than in magento/framework.
 */
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

if (!function_exists('__')) {
    /**
     * Same definition as Magento's app/functions.php.
     *
     * @param mixed ...$argc
     * @return \Magento\Framework\Phrase
     */
    function __(...$argc)
    {
        $text = array_shift($argc);
        if (!empty($argc) && is_array($argc[0])) {
            $argc = $argc[0];
        }

        return new \Magento\Framework\Phrase($text, $argc);
    }
}
