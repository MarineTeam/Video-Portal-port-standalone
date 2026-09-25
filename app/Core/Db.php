<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;

/**
 * A thin PDO wrapper: prepared statements everywhere, a table prefix, and a
 * query log for the debug bar.
 *
 * Table names are written as {{name}} in SQL and expanded to the prefixed,
 * backquoted identifier. The prefix is validated once, at construction, and
 * nothing else ever builds an identifier: request input only ever reaches the
 * database as a bound parameter.
 */
final class Db
{
    public const PREFIX_PATTERN = '/^[a-z0-9_]{1,16}$/';

    private int $depth = 0;

    /** @var list<array{sql: string, ms: float}>|null */
    private ?array $log = null;

    public function __construct(private readonly PDO $pdo, private readonly string $prefix)
    {
        if (!preg_match(self::PREFIX_PATTERN, $prefix)) {
            throw new \InvalidArgumentException('Table prefix must match [a-z0-9_]{1,16}.');
        }
    }

    /**
     * @param array{host?: string, port?: int|string, name: string, user: string, password?: string, prefix?: string, socket?: string} $config
     */
    public static function connect(array $config): self
    {
        $dsn = isset($config['socket']) && $config['socket'] !== ''
            ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $config['socket'], $config['name'])
            : sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $config['host'] ?? 'localhost',
                (int) ($config['port'] ?? 3306),
                $config['name'],
            );
        $pdo = new PDO($dsn, $config['user'], $config['password'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        // Every DATETIME in this schema is UTC; the session zone makes
        // CURRENT_TIMESTAMP agree with what PHP writes.
        $pdo->exec("SET time_zone = '+00:00', sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION'");
        return new self($pdo, $config['prefix'] ?? 'mt_');
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    /** The prefixed, backquoted name of a table. */
    public function table(string $name): string
    {
        if (!preg_match('/^[a-z0-9_]+$/', $name)) {
            throw new \InvalidArgumentException("Bad table name: $name");
        }
        return '`' . $this->prefix . $name . '`';
    }

    public function expand(string $sql): string
    {
        return (string) preg_replace_callback('/\{\{([a-z0-9_]+)\}\}/', fn ($m) => $this->table($m[1]), $sql);
    }

    public function enableLog(): void
    {
        $this->log ??= [];
    }

    /** @return list<array{sql: string, ms: float}> */
    public function queryLog(): array
    {
        return $this->log ?? [];
    }

    /** @param array<int|string, mixed> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $sql = $this->expand($sql);
        $started = $this->log !== null ? hrtime(true) : 0;
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ":$key");
            $stmt->bindValue($name, self::toDb($value), match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_INT,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            });
        }
        $stmt->execute();
        if ($this->log !== null) {
            $this->log[] = ['sql' => $sql, 'ms' => (hrtime(true) - $started) / 1e6];
        }
        return $stmt;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<int|string, mixed> $params */
    public function value(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return list<mixed>
     */
    public function column(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Inserts one row. An `id` is generated when the row has none.
     *
     * @param array<string, mixed> $row
     * @return string the row's id
     */
    public function insert(string $table, array $row): string
    {
        if (!array_key_exists('id', $row)) {
            $row = ['id' => Id::new()] + $row;
        }
        $cols = array_keys($row);
        foreach ($cols as $col) {
            self::assertColumn($col);
        }
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->table($table),
            implode(', ', array_map(fn ($c) => "`$c`", $cols)),
            implode(', ', array_map(fn ($c) => ":$c", $cols)),
        );
        $this->run($sql, $row);
        return (string) $row['id'];
    }

    /**
     * @param array<string, mixed> $set
     * @param array<string, mixed> $where equality conditions, ANDed
     * @return int affected rows
     */
    public function update(string $table, array $set, array $where): int
    {
        if ($set === [] || $where === []) {
            throw new \InvalidArgumentException('update() needs both a SET and a WHERE.');
        }
        $params = [];
        $assign = [];
        foreach ($set as $col => $value) {
            self::assertColumn($col);
            $assign[] = "`$col` = :s_$col";
            $params["s_$col"] = $value;
        }
        [$cond, $whereParams] = $this->where($where);
        $sql = sprintf('UPDATE %s SET %s WHERE %s', $this->table($table), implode(', ', $assign), $cond);
        return $this->run($sql, $params + $whereParams)->rowCount();
    }

    /** @param array<string, mixed> $where */
    public function delete(string $table, array $where): int
    {
        if ($where === []) {
            throw new \InvalidArgumentException('delete() needs a WHERE.');
        }
        [$cond, $params] = $this->where($where);
        return $this->run(sprintf('DELETE FROM %s WHERE %s', $this->table($table), $cond), $params)->rowCount();
    }

    /**
     * @param array<string, mixed> $where
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function where(array $where): array
    {
        $parts = [];
        $params = [];
        foreach ($where as $col => $value) {
            self::assertColumn($col);
            if ($value === null) {
                $parts[] = "`$col` IS NULL";
            } else {
                $parts[] = "`$col` = :w_$col";
                $params["w_$col"] = $value;
            }
        }
        return [implode(' AND ', $parts), $params];
    }

    /**
     * Runs $fn inside a transaction. Nested calls join the outer one, so a
     * module can wrap its own writes without knowing whether its caller did.
     *
     * @template T
     * @param callable(self): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        if ($this->depth > 0) {
            $this->depth++;
            try {
                return $fn($this);
            } finally {
                $this->depth--;
            }
        }
        $this->pdo->beginTransaction();
        $this->depth = 1;
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } finally {
            $this->depth = 0;
        }
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    /** Escapes % and _ so a user's text is matched literally inside LIKE. */
    public static function likeEscape(string $text): string
    {
        return strtr($text, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
    }

    /** SQLSTATE 23000 with MySQL error 1062: a unique index said no. */
    public static function isDuplicate(\Throwable $e): bool
    {
        return $e instanceof PDOException && ($e->errorInfo[1] ?? null) === 1062;
    }

    /** MySQL 1451/1452: a foreign key said no. */
    public static function isForeignKey(\Throwable $e): bool
    {
        return $e instanceof PDOException && in_array($e->errorInfo[1] ?? null, [1451, 1452], true);
    }

    public static function now(): string
    {
        return self::datetime(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }

    public static function datetime(\DateTimeInterface $at): string
    {
        return \DateTimeImmutable::createFromInterface($at)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.v');
    }

    public static function toDateTime(?string $value): ?\DateTimeImmutable
    {
        return $value === null ? null : new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    private static function toDb(mixed $value): mixed
    {
        return match (true) {
            is_bool($value) => $value ? 1 : 0,
            $value instanceof \DateTimeInterface => self::datetime($value),
            is_array($value) => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            default => $value,
        };
    }

    private static function assertColumn(string $col): void
    {
        if (!preg_match('/^[a-z0-9_]+$/', $col)) {
            throw new \InvalidArgumentException("Bad column name: $col");
        }
    }
}
