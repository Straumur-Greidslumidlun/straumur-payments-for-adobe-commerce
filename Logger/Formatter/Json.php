<?php
declare(strict_types=1);

namespace Straumur\Payment\Logger\Formatter;

use Monolog\Formatter\JsonFormatter;

class Json extends JsonFormatter
{
    /**
     * @param int $batchMode
     * @param bool $appendNewline
     * @param bool $ignoreEmptyContextAndExtra
     * @param bool $includeStacktraces
     */
    public function __construct(
        int $batchMode = self::BATCH_MODE_JSON,
        bool $appendNewline = true,
        bool $ignoreEmptyContextAndExtra = false,
        bool $includeStacktraces = false
    ) {
        parent::__construct($batchMode, $appendNewline, $ignoreEmptyContextAndExtra, $includeStacktraces);
    }

    /**
     * {@inheritdoc}
     */
    public function format($record): string
    {
        $normalized = $this->normalize($record);

        // Ensure clean structure with timestamp, level, message, and context
        $output = [
            'timestamp' => $normalized['datetime'] ?? date('c'),
            'level' => $normalized['level_name'] ?? 'INFO',
            'message' => $normalized['message'] ?? '',
        ];

        // Add context if present
        if (isset($normalized['context']) && !empty($normalized['context'])) {
            $output['context'] = $normalized['context'];
        }

        // Add extra if present
        if (isset($normalized['extra']) && !empty($normalized['extra'])) {
            $output['extra'] = $normalized['extra'];
        }

        // Format as single-line JSON
        $json = json_encode($output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new \RuntimeException('Failed to encode log record to JSON');
        }

        if ($this->appendNewline) {
            $json .= "\n";
        }

        return $json;
    }
}