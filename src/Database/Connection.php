<?php

declare(strict_types=1);

namespace AtarCms\Database;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use PDO;
use PDOStatement;
use Stringable;
use Throwable;

final class Connection
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @param array{host: string, port: int, database: string, username: string, password: string} $config
     */
    public static function fromConfig(array $config): self
    {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $config['database']),
            $config['username'],
            $config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ],
        );
        $pdo->exec(
            "SET time_zone = '+00:00', "
            . "sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'",
        );

        return new self($pdo);
    }

    public static function quoteIdentifier(string $name): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid SQL identifier "%s"', $name));
        }

        return '`' . $name . '`';
    }

    /**
     * @param array<int|string, mixed> $bindings
     *
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        /** @var list<array<string, mixed>> */
        return $this->run($sql, $bindings)->fetchAll();
    }

    /**
     * @param array<int|string, mixed> $bindings
     *
     * @return array<string, mixed>|null
     */
    public function selectOne(string $sql, array $bindings = []): ?array
    {
        /** @var array<string, mixed>|false $row */
        $row = $this->run($sql, $bindings)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<int|string, mixed> $bindings
     */
    public function scalar(string $sql, array $bindings = []): mixed
    {
        $value = $this->run($sql, $bindings)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * @param array<int|string, mixed> $bindings
     */
    public function execute(string $sql, array $bindings = []): int
    {
        return $this->run($sql, $bindings)->rowCount();
    }

    /**
     * @param array<string, mixed> $row
     */
    public function insert(string $table, array $row): int
    {
        if ($row === []) {
            throw new InvalidArgumentException('Cannot insert an empty row');
        }
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            self::quoteIdentifier($table),
            implode(', ', array_map(self::quoteIdentifier(...), array_keys($row))),
            implode(', ', array_fill(0, count($row), '?')),
        );
        $this->run($sql, array_values($row));

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @template T
     *
     * @param Closure(self): T $callback
     *
     * @return T
     */
    public function transaction(Closure $callback): mixed
    {
        if ($this->pdo->inTransaction()) {
            throw new LogicException('Nested transactions are not supported');
        }
        $this->pdo->beginTransaction();
        try {
            $result = $callback($this);
            $this->pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * @param array<int|string, mixed> $bindings
     */
    private function run(string $sql, array $bindings): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        foreach ($bindings as $key => $value) {
            [$bound, $type] = $this->toParameter($value);
            $statement->bindValue(is_int($key) ? $key + 1 : ':' . ltrim($key, ':'), $bound, $type);
        }
        $statement->execute();

        return $statement;
    }

    /**
     * @return array{0: int|string|null, 1: int}
     */
    private function toParameter(mixed $value): array
    {
        return match (true) {
            $value === null => [null, PDO::PARAM_NULL],
            is_bool($value) => [(int) $value, PDO::PARAM_INT],
            is_int($value) => [$value, PDO::PARAM_INT],
            is_float($value), is_string($value) => [(string) $value, PDO::PARAM_STR],
            $value instanceof DateTimeInterface => [
                DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                PDO::PARAM_STR,
            ],
            $value instanceof Stringable => [(string) $value, PDO::PARAM_STR],
            default => throw new InvalidArgumentException(sprintf('Unsupported binding type %s', get_debug_type($value))),
        };
    }
}
