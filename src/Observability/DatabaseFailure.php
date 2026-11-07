<?php

declare(strict_types=1);

namespace OPGateway\Observability;

use PDOException;
use Throwable;

/**
 * Safe database diagnostic classification.
 *
 * Never persist PDO exception messages, SQL statements, credentials,
 * hostnames, raw driver errors, or uncontrolled exception class names.
 * MySQL error numbers are mapped into a finite, reviewed vocabulary.
 */
final class DatabaseFailure
{
    /**
     * @return array{reason:string,mysql_errno:int|null,sqlstate_class:string|null}
     */
    public static function classify(Throwable $error): array
    {
        if (!$error instanceof PDOException) {
            return ['reason' => 'non_pdo_failure', 'mysql_errno' => null, 'sqlstate_class' => null];
        }

        $info = $error->errorInfo;
        $number = isset($info[1]) && (is_int($info[1]) || ctype_digit((string) $info[1]))
            ? (int) $info[1] : null;
        $safeNumber = $number !== null && in_array($number, [
            1040, 1044, 1045, 1049, 1054, 1062, 1142, 1143, 1146, 1203,
            1227, 2002, 2003, 2005, 2006, 2013, 2054,
        ], true) ? $number : null;

        $state = isset($info[0]) && is_string($info[0])
            ? $info[0] : (is_string($error->getCode()) ? $error->getCode() : '');
        $stateClass = strlen($state) === 5 ? substr($state, 0, 2) : '';
        $safeClass = in_array($stateClass, [
            '08', '22', '23', '28', '42', 'HY',
        ], true) ? $stateClass : null;

        $reason = match ($safeNumber) {
            1045 => 'authentication_rejected',
            1044, 1142, 1143, 1227 => 'access_denied',
            1049 => 'unknown_database',
            1146 => 'missing_table',
            1054 => 'missing_column',
            1062 => 'duplicate_key',
            1040, 1203 => 'connection_limit',
            2002, 2003, 2005, 2006, 2013 => 'connection_failure',
            2054 => 'authentication_plugin',
            default => match ($safeClass) {
                '08' => 'connection_failure',
                '28' => 'authentication_rejected',
                '42' => 'sql_or_permission_failure',
                default => 'unclassified',
            },
        };

        return [
            'reason' => $reason,
            'mysql_errno' => $safeNumber,
            'sqlstate_class' => $safeClass,
        ];
    }
}
