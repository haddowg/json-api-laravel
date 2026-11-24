<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider\Eloquent;

use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;

/**
 * A 16-byte UUID key read as its canonical lowercase text in SQL, so a text column holding
 * UUIDs, such as a polymorphic id, compares with it in one statement.
 *
 * @internal
 */
final class BinaryUuidText
{
    /**
     * The SQL reading the wrapped binary column `$column` as canonical text on the
     * connection's database, or null on one with no way to write it.
     */
    public static function sql(Connection $connection, string $column): ?string
    {
        $driver = $connection->getDriverName();
        if ($driver === 'mysql' && $connection instanceof MySqlConnection && !$connection->isMaria() && \version_compare(self::version($connection), '8.0.0', '>=')) {
            return \sprintf('BIN_TO_UUID(%s)', $column);
        }

        return match ($driver) {
            'mysql', 'mariadb' => \sprintf('LOWER(CONCAT(%s))', \implode(", '-', ", self::parts(\sprintf('HEX(%s)', $column)))),
            'sqlite' => \sprintf('LOWER(%s)', \implode(" || '-' || ", self::parts(\sprintf('HEX(%s)', $column)))),
            'pgsql' => \implode(" || '-' || ", self::parts(\sprintf("ENCODE(%s, 'hex')", $column))),
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    private static function parts(string $hex): array
    {
        $parts = [];
        foreach ([[1, 8], [9, 4], [13, 4], [17, 4], [21, 12]] as [$from, $length]) {
            $parts[] = \sprintf('SUBSTR(%s, %d, %d)', $hex, $from, $length);
        }

        return $parts;
    }

    private static function version(MySqlConnection $connection): string
    {
        $version = $connection->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);

        return \is_string($version) ? $version : '0';
    }
}
