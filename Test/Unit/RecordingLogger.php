<?php
declare(strict_types=1);

namespace Straumur\Payment\Test\Unit;

use Psr\Log\AbstractLogger;

/**
 * Logger that keeps every record, for asserting what was (and was not) logged.
 */
class RecordingLogger extends AbstractLogger
{
    /**
     * @var array[] Each record: ['level' => string, 'message' => string, 'context' => array]
     */
    public $records = [];

    /**
     * @inheritDoc
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }

    /**
     * Messages logged at the given level.
     *
     * @param string $level
     * @return string[]
     */
    public function messages(string $level): array
    {
        $messages = [];
        foreach ($this->records as $record) {
            if ($record['level'] === $level) {
                $messages[] = $record['message'];
            }
        }

        return $messages;
    }

    /**
     * Everything that was logged, messages and context, as one string.
     *
     * @return string
     */
    public function dump(): string
    {
        return (string) json_encode($this->records);
    }
}
