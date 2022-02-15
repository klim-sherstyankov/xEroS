<?php declare(strict_types=1);

namespace Xeros;

use PDO;
use Exception;
use RuntimeException;


class Account
{
    private PDO $db;
    private OpenSsl $openSsl;
    private Address $address;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->openSsl = new OpenSsl();
        $this->address = new Address();
    }

    public function get(int $id): ?array
    {
        $query = 'SELECT `id`,`address`,`public_key`,`public_key_raw`,`private_key`,`date_created` FROM accounts WHERE `id` = :id LIMIT 1';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind($stmt, 'id', $id, DatabaseHelpers::INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getByPublicKeyRaw(string $publicKeyRaw): ?array
    {
        $query = 'SELECT `id`,`address`,`public_key`,`public_key_raw`,`private_key`,`date_created` FROM accounts WHERE `public_key_raw` = :public_key_raw LIMIT 1';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind($stmt, 'public_key_raw', $publicKeyRaw, DatabaseHelpers::TEXT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function create(): int
    {
        try {
            $this->db->beginTransaction();

            $keys = $this->openSsl->createRsaKeyPair();
            $address = $this->address->create($keys['public_key']);
            $dateCreated = time();

            // prepare the statement and execute
            $query = 'INSERT INTO accounts (`public_key`,`public_key_raw`,`private_key`,`address`,`date_created`) VALUES (:public_key,:public_key_raw,:private_key,:address,`date_created`)';
            $stmt = $this->db->prepare($query);
            $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'public_key', value: $keys['public_key'], pdoType: DatabaseHelpers::TEXT);
            $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'public_key_raw', value: $keys['public_key_raw'], pdoType: DatabaseHelpers::TEXT);
            $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'private_key', value: $keys['private_key'], pdoType: DatabaseHelpers::TEXT);
            $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'address', value: $address, pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 40);
            $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'date_created', value: $dateCreated, pdoType: DatabaseHelpers::INT);
            $stmt->execute();

            // ensure the block was stored
            $id = $this->db->lastInsertId();
            if ($id <= 0) {
                throw new RuntimeException("failed to add block to the database: " . $block['block_id']);
            }
            $this->db->commit();
        } catch (Exception $ex) {
            $id = 0;
            Console::log('Rolling back transaction: ' . $ex->getMessage());
            $this->db->rollback();
        }

        return $id;
    }

    public function delete(int $id): bool
    {
        $result = false;
        try {
            $this->db->beginTransaction();

            // delete the block
            $query = 'DELETE FROM accounts WHERE `id` = :id;';
            $stmt = $this->db->prepare($query);
            $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'id', value: $id, pdoType: DatabaseHelpers::INT);
            $stmt->execute();

            $this->db->commit();
            $result = true;
        } catch (Exception|RuntimeException $ex) {
            Console::log('Rolling back transaction: ' . $ex->getMessage());
            $this->db->rollback();
        }
        return $result;
    }

    public function getBalance(string $address): string
    {
        $query = 'SELECT `value` FROM transaction_outputs WHERE `address` = :address';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind($stmt, 'address', $address, DatabaseHelpers::ALPHA_NUMERIC, 40);
        $stmt->execute();
        $unspentTransactions = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $balance = "0";
        foreach ($unspentTransactions as $unspentTransaction) {
            $balance = bcadd($balance, $unspentTransaction['value'], 0);
        }

        return $balance;
    }

    public function getPendingBalance(string $address): string
    {
        // get the current balance
        $balance = $this->getBalance($address);

        // get all the mempool transactions for the address
        $query = 'SELECT `transaction_id`,`tx_id`,`value` FROM mempool_outputs WHERE `address` = :address';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind($stmt, 'address', $address, DatabaseHelpers::ALPHA_NUMERIC, 40);
        $stmt->execute();
        $transactions = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        foreach ($transactions as $transaction) {
            $key = $transaction['transaction_id'] . '-' . $transaction['tx_id'];
            $transactions[$key] = $transaction['value'];
        }

        // get all the mempool transactions for the address
        $query = 'SELECT `previous_transaction_id`,`previous_tx_out_id` FROM mempool_inputs WHERE `address` = :address';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind($stmt, 'address', $address, DatabaseHelpers::ALPHA_NUMERIC, 40);
        $stmt->execute();
        $spentTxs = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        foreach ($spentTxs as $spent) {
            $key = $spent['previous_transaction_id'] . '-' . $spent['previous_tx_out_id'];
            unset($transactions[$key]);
        }

        // add the pending to the balance
        foreach ($transactions as $value) {
            $balance = bcadd($balance, $value, 0);
        }

        return $balance;
    }
}