<?php

declare(strict_types=1);

namespace Intrabase\Support;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * PSR-3 compliant Logger
 *
 * Writes log messages to a daily rotating log file.
 * Sensitive data (passwords, tokens) is automatically redacted.
 *
 * @package Intrabase\Support
 */
class Logger extends AbstractLogger
{
    private string $logDir;
    private string $minLevel;

    private const LEVELS = [
        LogLevel::DEBUG     => 0,
        LogLevel::INFO      => 1,
        LogLevel::NOTICE    => 2,
        LogLevel::WARNING   => 3,
        LogLevel::ERROR     => 4,
        LogLevel::CRITICAL  => 5,
        LogLevel::ALERT     => 6,
        LogLevel::EMERGENCY => 7,
    ];

    /** Keys whose values will be redacted from context */
    private const SENSITIVE_KEYS = ['password', 'passwd', 'pass', 'secret', 'token', 'key', 'auth'];

    public function __construct(
        string $logDir  = '/var/log/intrabase',
        string $minLevel = LogLevel::DEBUG
    ) {
        $this->logDir   = rtrim($logDir, '/');
        $this->minLevel = $minLevel;
    }

    /**
     * @param  mixed               $level
     * @param  string|\Stringable  $message
     * @param  array<string, mixed> $context
     */
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $level = (string) $level;

        if ((self::LEVELS[$level] ?? 0) < (self::LEVELS[$this->minLevel] ?? 0)) {
            return;
        }

        $line = $this->format($level, (string) $message, $context);

        $this->write($line);
    }

    // =========================================================================

    /**
     * @param array<string, mixed> $context
     */
    private function format(string $level, string $message, array $context): string
    {
        $timestamp = date('Y-m-d H:i:s');
        $levelStr  = strtoupper(str_pad($level, 9));
        $message   = $this->interpolate($message, $context);
        $context   = $this->redactSensitive($context);
        $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';

        return "[{$timestamp}] [{$levelStr}] {$message}{$contextStr}" . PHP_EOL;
    }

    /**
     * Interpolate {placeholder} values from context into message.
     *
     * @param array<string, mixed> $context
     */
    private function interpolate(string $message, array $context): string
    {
        $replace = [];
        foreach ($context as $key => $val) {
            if (is_scalar($val) || (is_object($val) && method_exists($val, '__toString'))) {
                $replace['{' . $key . '}'] = (string) $val;
            }
        }
        return strtr($message, $replace);
    }

    /**
     * Replace sensitive values with [REDACTED].
     *
     * @param  array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function redactSensitive(array $context): array
    {
        foreach ($context as $key => $value) {
            foreach (self::SENSITIVE_KEYS as $sensitive) {
                if (stripos((string) $key, $sensitive) !== false) {
                    $context[$key] = '[REDACTED]';
                    break;
                }
            }
        }
        return $context;
    }

    private function write(string $line): void
    {
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0750, true);
        }

        $file = $this->logDir . '/intrabase-' . date('Y-m-d') . '.log';

        // Suppress errors to avoid log failures crashing the application
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }
}
