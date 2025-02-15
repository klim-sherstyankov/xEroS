<?php declare(strict_types=1);

namespace Blockchain;

use Exception;
use PDO;
use RuntimeException;

/**
 * Class Queue
 * @package Blockchain
 */
class Queue
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * @param int $id
     * @return array|null
     */
    public function get(int $id): ?array
    {
        $query = 'SELECT `id`,`date_created`,`command`,`data`,`trys` FROM queue WHERE `id` = :id LIMIT 1';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind($stmt, 'id', $id, DatabaseHelpers::INT);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * @param string $command
     * @param int $limit
     * @return array
     */
    public function getItems(string $command, int $limit = 100): array
    {
        $query = 'SELECT `id`,`date_created`,`command`,`data`,`trys` FROM queue WHERE command=:command and ' .
            'trys < 5 LIMIT :limit;';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind(
            $stmt, 'command',
            $command,
            DatabaseHelpers::TEXT,
            32
        );
        $stmt = DatabaseHelpers::filterBind(
            $stmt,
            'limit',
            $limit,
            DatabaseHelpers::INT
        );
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param int $id
     * @return bool
     */
    public function incrementFails(int $id): bool
    {
        $result = false;
        try {
            $this->db->beginTransaction();
            $query = 'UPDATE queue SET trys=trys+1 WHERE id=:id;';
            $stmt = $this->db->prepare($query);
            $stmt = DatabaseHelpers::filterBind($stmt, 'id', $id, DatabaseHelpers::INT);
            $stmt->execute();
            $this->db->commit();
            $result = true;
        } catch (Exception) {
            $this->db->rollback();
        }

        return $result;
    }

    /**
     * @param int $id
     * @return bool
     */
    public function clearFails(int $id): bool
    {
        $result = false;
        try {
            $this->db->beginTransaction();
            $query = 'UPDATE queue SET trys=0 WHERE id=:id;';
            $stmt = $this->db->prepare($query);
            $stmt = DatabaseHelpers::filterBind($stmt, 'id', $id, DatabaseHelpers::INT);
            $stmt->execute();
            $this->db->commit();
            $result = true;
        } catch (Exception) {
            $this->db->rollback();
        }

        return $result;
    }

    /**
     * @param string $command
     * @param string $data
     * @return int
     */
    public function add(string $command, string $data): int
    {
        try {
            $this->db->beginTransaction();
            // prepare the statement and execute
            $query = 'INSERT INTO queue (`command`,`date_created`,`data`,`trys`) VALUES ' .
                '(:command,:date_created,:data,:trys)';
            $stmt = $this->db->prepare($query);
            $stmt = DatabaseHelpers::filterBind(
                stmt: $stmt,
                fieldName: 'command',
                value: $command,
                pdoType: DatabaseHelpers::ALPHA_NUMERIC,
                maxLength: 64
            );
            $stmt = DatabaseHelpers::filterBind(
                stmt: $stmt,
                fieldName: 'data',
                value: $data,
                pdoType: DatabaseHelpers::ALPHA_NUMERIC,
                maxLength: 1048576
            );
            $stmt = DatabaseHelpers::filterBind(
                stmt: $stmt,
                fieldName: 'date_created',
                value: time(),
                pdoType: DatabaseHelpers::INT
            );
            $stmt = DatabaseHelpers::filterBind(
                stmt: $stmt,
                fieldName: 'trys',
                value: 0,
                pdoType: DatabaseHelpers::INT
            );
            $stmt->execute();
            // ensure the block was stored
            $id = (int)$this->db->lastInsertId();

            if ($id <= 0) {
                throw new RuntimeException('failed to add queue to the database: ' . $command);
            }

            $this->db->commit();
        } catch (Exception $e) {
            $id = 0;
            Console::log('Rolling back transaction: ' . $e->getMessage());
            $this->db->rollback();
        }

        return $id;
    }

    /**
     * @return bool
     */
    public function prune(): bool
    {
        $result = false;
        try {
            $this->db->beginTransaction();
            // delete the block
            $query = 'DELETE FROM queue WHERE trys > 4;';
            $this->db->query($query);
            $this->db->commit();
            $result = true;
        } catch (Exception|RuntimeException $e) {
            Console::log('Rolling back transaction: ' . $e->getMessage());
            $this->db->rollback();
        }

        return $result;
    }

    /**
     * @param int $id
     * @return bool
     */
    public function delete(int $id): bool
    {
        $result = false;
        try {
            $this->db->beginTransaction();
            // delete the block
            $query = 'DELETE FROM queue WHERE `id` = :id;';
            $stmt = $this->db->prepare($query);
            $stmt = DatabaseHelpers::filterBind(
                stmt: $stmt,
                fieldName: 'id',
                value: $id,
                pdoType: DatabaseHelpers::INT
            );
            $stmt->execute();
            $this->db->commit();
            $result = true;
        } catch (Exception|RuntimeException $e) {
            Console::log('Rolling back transaction: ' . $e->getMessage());
            $this->db->rollback();
        }

        return $result;
    }
}
