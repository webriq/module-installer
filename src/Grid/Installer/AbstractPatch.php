<?php

namespace Grid\Installer;

/**
 * AbstractPatch
 *
 * @author David Pozsar <david.pozsar@megaweb.hu>
 */
abstract class AbstractPatch implements PatchInterface
{

    /**
     * Module installer
     *
     * @var ModuleInstaller
     */
    protected $installer;

    /**
     * Quote sql-identifier
     *
     * @param   string  $id
     * @return  string
     */
    protected static function quoteIdentifier( $id )
    {
        return '"' . str_replace( '"', '""', $id ) . '"';
    }

    /**
     * Get module-installer
     *
     * @return  ModuleInstaller
     */
    public function getInstaller()
    {
        return $this->installer;
    }

    /**
     * Get patch data
     *
     * @return  PatchData
     */
    public function getPatchData()
    {
        return $this->getInstaller()
                    ->getPatchData();
    }

    /**
     * Get patcher
     *
     * @return  Patcher
     */
    public function getPatcher()
    {
        return $this->getInstaller()
                    ->getPatcher();
    }

    /**
     * Get db
     *
     * @return  PDO
     */
    public function getDb()
    {
        return $this->getPatcher()
                    ->getDb();
    }

    /**
     * Constructor
     *
     * @param   ModuleInstaller $installer
     */
    public function __construct( /* ModuleInstaller */ $installer )
    {
        $this->installer = $installer;
    }

    /**
     * Detect a version is zero (for install & uninstall)
     *
     * @param   string  $version
     * @return  bool
     */
    protected function isZeroVersion( $version )
    {
        return empty( $version ) || 0 === version_compare( $version, '0' );
    }

    /**
     * Detect if two versions are the same
     *
     * @param   string  $from
     * @param   string  $to
     * @return  bool
     */
    protected function isSameVersion( $from, $to )
    {
        return $from == $to || 0 === version_compare( $from, $to );
    }

    /**
     * Execute an sql-query
     *
     * @param   string  $sql
     * @param   array   $params
     * @return  \PDOStatement
     */
    protected function query( $sql, array $params = null )
    {
        $query = $this->getDb()
                      ->prepare( $sql );

        $query->execute( $params );
        return $query;
    }

    /**
     * Select a field from a table
     *
     * @param   array|string    $table
     * @param   string          $column
     * @param   array           $where
     * @return  int
     */
    protected function selectFromTable( $table,
                                        $column,
                                        array $where = array() )
    {
        $whereSql = '';

        foreach ( $where as $col => $value )
        {
            if ( $whereSql )
            {
                $whereSql .= ' AND ';
            }

            $whereSql .= static::quoteIdentifier( $col ) . ' = ';

            if ( null === $value )
            {
                $whereSql .= 'IS NULL';
                unset( $where[$col] );
            }
            else
            {
                $whereSql .= ':' . $col;
            }
        }

        $quote = array( get_called_class(), 'quoteIdentifier' );
        $query = $this->query(
            sprintf(
                'SELECT %s FROM %s WHERE %s ORDER BY %s ASC LIMIT 1',
                static::quoteIdentifier( $column ),
                implode( '.', array_map( $quote, (array) $table ) ),
                $whereSql ?: 'TRUE',
                static::quoteIdentifier( $column )
            ),
            $where
        );

        if ( ! $query->rowCount() )
        {
            return null;
        }

        return $query->fetchObject()->$column;
    }

    /**
     * Select a column from a table
     *
     * @param   array|string    $table
     * @param   string          $column
     * @param   array           $where
     * @return  array
     */
    protected function selectColumnFromTable( $table,
                                              $column,
                                              array $where = array() )
    {
        $whereSql = '';

        foreach ( $where as $col => $value )
        {
            if ( $whereSql )
            {
                $whereSql .= ' AND ';
            }

            $whereSql .= static::quoteIdentifier( $col ) . ' = ';

            if ( null === $value )
            {
                $whereSql .= 'IS NULL';
                unset( $where[$col] );
            }
            else
            {
                $whereSql .= ':' . $col;
            }
        }

        $quote = array( get_called_class(), 'quoteIdentifier' );
        $query = $this->query(
            sprintf(
                'SELECT %s FROM %s WHERE %s ORDER BY %s ASC',
                static::quoteIdentifier( $column ),
                implode( '.', array_map( $quote, (array) $table ) ),
                $whereSql ?: 'TRUE',
                static::quoteIdentifier( $column )
            ),
            $where
        );

        if ( ! $query->rowCount() )
        {
            return array();
        }

        return $query->fetchAll( PDO::FETCH_COLUMN );
    }

    /**
     * Select a column from a table
     *
     * @param   array|string    $table
     * @param   array|string    $columns
     * @param   array           $where
     * @param   array           $order
     * @return  int
     */
    protected function selectRowsFromTable( $table,
                                            $columns,
                                            array $where = array(),
                                            array $order = array() )
    {
        $whereSql = '';
        $orderSql = '';

        foreach ( $where as $col => $value )
        {
            if ( $whereSql )
            {
                $whereSql .= ' AND ';
            }

            $whereSql .= static::quoteIdentifier( $col ) . ' = ';

            if ( null === $value )
            {
                $whereSql .= 'IS NULL';
                unset( $where[$col] );
            }
            else
            {
                $whereSql .= ':' . $col;
            }
        }

        foreach ( $order as $col => $direction )
        {
            if ( $orderSql )
            {
                $orderSql .= ', ';
            }

            switch ( strtoupper( $direction ) )
            {
                case '':
                case '0':
                case '-1':
                case 'DESC':
                    $direction = 'DESC';
                    break;

                case '1':
                case 'ASC':
                default:
                    $direction = 'ASC';
                    break;
            }

            $orderSql .= static::quoteIdentifier( $col ) . ' ' . $direction;
        }

        $quote = array( get_called_class(), 'quoteIdentifier' );
        $query = $this->query(
            sprintf(
                'SELECT %s FROM %s',
                implode( ', ', array_map( $quote, (array) $columns ) ),
                implode( '.',  array_map( $quote, (array) $table   ) )
            ) .
            ( $whereSql ? ' WHERE '    . $whereSql : '' ) .
            ( $orderSql ? ' ORDER BY ' . $orderSql : '' ),
            $where
        );

        $rows = array();

        if ( $query->rowCount() )
        {
            while ( $row = $query->fetchObject() )
            {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Update fields in a table
     *
     * @param   array|string    $table
     * @param   array           $set
     * @param   array           $where
     * @return  int
     */
    protected function updateTable( $table, array $set, array $where )
    {
        $setSql   = '';
        $whereSql = '';
        $params   = array();

        foreach ( $set as $col => $value )
        {
            if ( $setSql )
            {
                $setSql .= ', ';
            }

            $setSql .= static::quoteIdentifier( $col ) . ' = :set_' . $col;
            $params['set_' . $col] = $value;
        }

        if ( empty( $setSql ) )
        {
            return null;
        }

        foreach ( $where as $col => $value )
        {
            if ( $whereSql )
            {
                $whereSql .= ' AND ';
            }

            $whereSql .= static::quoteIdentifier( $col ) . ' = ';

            if ( null === $value )
            {
                $whereSql .= 'IS NULL';
            }
            else
            {
                $whereSql .= ':where_' . $col;
                $params['where_' . $col] = $value;
            }
        }

        $quote = array( get_called_class(), 'quoteIdentifier' );
        $query = $this->query(
            sprintf(
                'UPDATE %s SET %s WHERE %s',
                implode( '.', array_map( $quote, (array) $table ) ),
                $setSql,
                $whereSql ?: 'TRUE'
            ),
            $params
        );

        return $query->rowCount();
    }

    /**
     * Insert data into table
     *
     * @param   array|string        $table
     * @param   array               $data
     * @param   null|bool|string    $seq
     * @return  int
     */
    protected function insertIntoTable( $table, array $data, $seq = null )
    {
        $table   = (array) $table;
        $columns = '';
        $values  = '';

        foreach ( $data as $field => $value )
        {
            if ( $columns )
            {
                $columns .= ', ';
            }

            if ( $values )
            {
                $values .= ', ';
            }

            $columns .= static::quoteIdentifier( $field );
            $values  .= ':' . $field;
        }

        $quote = array( get_called_class(), 'quoteIdentifier' );
        $query = $this->query(
            sprintf(
                'INSERT INTO %s ( %s ) VALUES ( %s )',
                implode( '.', array_map( $quote, $table ) ),
                $columns,
                $values
            ),
            $data
        );

        if ( $seq )
        {
            if ( true === $seq )
            {
                $seq = implode( '.', $table ) . '_id_seq';
            }

            return $this->getDb()
                        ->lastInsertId( $seq );
        }

        return null;
    }

    /**
     * Run before patching
     *
     * @param   string  $from
     * @param   string  $to
     * @return  void
     */
    public function beforePatch( $from, $to )
    {
        // dummy
    }

    /**
     * Run after patching
     *
     * @param   string  $from
     * @param   string  $to
     * @return  void
     */
    public function afterPatch( $from, $to )
    {
        // dummy
    }

}
