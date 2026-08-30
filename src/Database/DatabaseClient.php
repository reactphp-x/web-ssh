<?php

declare(strict_types=1);

namespace App\Database;

use React\Promise\PromiseInterface;
use RuntimeException;
use SQLite3;
use SQLite3Result;
use SQLite3Stmt;
use Throwable;
use function React\Promise\reject;
use function React\Promise\resolve;

final class DatabaseClient
{
    public function __construct(private readonly SQLite3 $database)
    {
    }

    /**
     * @param list<string|int|float|bool|null> $params
     */
    public function query(string $sql, array $params = []): PromiseInterface
    {
        try {
            return resolve($this->execute($sql, $params));
        } catch (Throwable $exception) {
            return reject($exception);
        }
    }

    /**
     * @param list<string|int|float|bool|null> $params
     */
    private function execute(string $sql, array $params = []): QueryResult
    {
        if ($params === []) {
            $result = $this->database->query($sql);
            if ($result === false) {
                throw new RuntimeException($this->database->lastErrorMsg());
            }

            if ($result instanceof SQLite3Result) {
                if ($result->numColumns() > 0) {
                    return $this->mapSelectResult($result);
                }

                return $this->finalizeWriteResult($result);
            }

            return $this->mapWriteResult();
        }

        $statement = $this->database->prepare($sql);
        if (!$statement instanceof SQLite3Stmt) {
            throw new RuntimeException($this->database->lastErrorMsg());
        }

        foreach ($params as $index => $value) {
            $statement->bindValue($index + 1, $value);
        }

        $result = $statement->execute();
        if ($result === false) {
            throw new RuntimeException($this->database->lastErrorMsg());
        }

        if ($result instanceof SQLite3Result) {
            if ($result->numColumns() > 0) {
                return $this->mapSelectResult($result);
            }

            return $this->finalizeWriteResult($result);
        }

        return $this->mapWriteResult();
    }

    private function mapWriteResult(): QueryResult
    {
        return new QueryResult(
            resultRows: [],
            insertId: (int) $this->database->lastInsertRowID(),
            affectedRows: (int) $this->database->changes(),
        );
    }

    private function finalizeWriteResult(SQLite3Result $result): QueryResult
    {
        $result->finalize();

        return $this->mapWriteResult();
    }

    private function mapSelectResult(SQLite3Result $result): QueryResult
    {
        $rows = [];

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        $result->finalize();

        return new QueryResult(
            resultRows: $rows,
            insertId: 0,
            affectedRows: count($rows),
        );
    }
}
