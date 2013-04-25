<?php

namespace Grid\Installer;

/**
 * PDO
 *
 * @author David Pozsar <david.pozsar@megaweb.hu>
 */
class PDO extends \PDO
{

    /**
     * @var string
     */
    private $transactionUniqueId;

    /**
     * @var int
     */
    private $nextTransationIndex = 0;

    /**
     * @param   int $index
     * @return  string
     */
    private function getTransactionUniqueId( $index )
    {
        if ( empty( $this->transactionUniqueId ) )
        {
            $this->transactionUniqueId = strtr( uniqid( 'tr_', true ), '.', '_' );
        }

        return $this->transactionUniqueId . '_' . $index;
    }

    /**
     * Initiates a transaction
     * @link http://php.net/manual/en/pdo.begintransaction.php
     *
     * @return  bool
     */
    public function beginTransaction()
    {
        $index = $this->nextTransationIndex++;

        if ( $index )
        {
            $this->query( 'SAVEPOINT ' . $this->getTransactionUniqueId( $index ) );
            return true;
        }
        else
        {
            return parent::beginTransaction();
        }
    }

    /**
     * Commits a transaction
     * @link http://php.net/manual/en/pdo.commit.php
     *
     * @return  bool
     */
    public function commit()
    {
        $index = $this->nextTransationIndex = max( 0, $this->nextTransationIndex - 1 );

        if ( $index )
        {
            $this->query( 'RELEASE SAVEPOINT ' . $this->getTransactionUniqueId( $index ) );
            return true;
        }
        else
        {
            return parent::commit();
        }
    }

    /**
     * Rolls back a transaction
     * @link http://php.net/manual/en/pdo.rollback.php
     *
     * @return  bool
     */
    public function rollBack()
    {
        $index = $this->nextTransationIndex = max( 0, $this->nextTransationIndex - 1 );

        if ( $index )
        {
            $this->query( 'ROLLBACK TO SAVEPOINT ' . $this->getTransactionUniqueId( $index ) );
            return true;
        }
        else
        {
            return parent::rollBack();
        }
    }

}
