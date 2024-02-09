<?php

namespace FpDbTest;

use Exception;
use mysqli;

class Database implements DatabaseInterface
{
    private mysqli $mysqli;
    private const SKIP_TOKEN = '/*SKIP*/'; 

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    public function buildQuery(string $query, array $args = []): string
    {
        $offset = 0;
        while (preg_match('/(\?d|\?f|\?a|\?\#|\{|\})/', $query, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            $match = $matches[0][0];
            $pos = $matches[0][1];
            $offset = $pos + strlen($match);

            if ($match === '{' || $match === '}') {
                if ($match === '{') {
                    $endPos = strpos($query, '}', $offset);
                    if ($endPos === false) {
                        throw new Exception("Unmatched { in query");
                    }
                    $block = substr($query, $pos + 1, $endPos - $pos - 1);
                    if (strpos($block, self::SKIP_TOKEN) !== false) {
                        $query = substr_replace($query, '', $pos, $endPos - $pos + 1);
                        $offset = $pos;
                    }
                }
                continue;
            }

            if (!isset($args[0])) {
                throw new Exception("Not enough parameters for query");
            }

            $value = array_shift($args);
            switch ($match) {
                case '?d':
                    $replacement = intval($value);
                    break;
                case '?f':
                    $replacement = floatval($value);
                    break;
                case '?a':
                    if (!is_array($value)) {
                        throw new Exception("?a specifier expects an array");
                    }
                    $replacement = implode(',', array_map([$this, 'escapeValue'], $value));
                    break;
                case '?#':
                    $replacement = $this->escapeIdentifier($value);
                    break;
                default:
                    throw new Exception("Unsupported specifier: $match");
            }
            $query = substr_replace($query, $replacement, $pos, strlen($match));
            $offset = $pos + strlen($replacement);
        }

        return $query;
    }

    public function skip()
    {
        return self::SKIP_TOKEN;
    }

    private function escapeValue($value)
    {
        if (is_null($value)) {
            return 'NULL';
        } elseif (is_bool($value)) {
            return $value ? '1' : '0';
        } elseif (is_int($value) || is_float($value)) {
            return $value;
        } else {
            return "'" . $this->mysqli->real_escape_string($value) . "'";
        }
    }

    private function escapeIdentifier($value)
    {
        if (is_array($value)) {
            return implode(', ', array_map([$this, 'escapeIdentifier'], $value));
        }
        return '`' . str_replace('`', '``', $value) . '`';
    }
}
