<?php

namespace Grid\Installer;

use Traversable;
use PDOException;
use IteratorAggregate;
use FilesystemIterator;
use CallbackFilterIterator;

/**
 * Patch
 *
 * @author David Pozsar <david.pozsar@megaweb.hu>
 */
class Patcher
{

    /**
     * @const string
     */
    const FIX_PATTERN = '/^fix\.(?P<version>\d+(\.((dev|a|b|rc)\d*|\d+))*)-(?P<fix>\d+)\.sql$/i';

    /**
     * @const string
     */
    const PATCH_PATTERN = '/^(?P<type>data|schema)\.(?P<from>\d+(\.((dev|a|b|rc)\d*|\d+))*)-(?P<to>\d+(\.((dev|a|b|rc)\d*|\d+))*)\.sql$/i';

    /**
     * @var array
     */
    protected $config;

    /**
     * @var \PDO
     */
    private $db;

    /**
     * Log callback - like printf()
     *
     * @var null|callable
     */
    private $log;

    /**
     * Is current db a multisite?
     *
     * @var bool
     */
    private $isMultisite;

    /**
     * Schemas' exists
     *
     * @var array
     */
    private $schemaExists = array();

    /**
     * Schemas' cache
     *
     * @var array
     */
    private $schemaCache = null;

    /**
     * Versions' cache
     *
     * @var array
     */
    private $versionCache = array();

    /**
     * Patch info cache
     *
     * @var array
     */
    private $fixInfoCache = array();

    /**
     * Patch info cache
     *
     * @var array
     */
    private $patchInfoCache = array();

    /**
     * Get the db-config
     *
     * @return  array
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * Set the db-config
     *
     * @return  Patcher
     */
    public function setConfig( array $config = null )
    {
        $this->config = $config;
        $this->db = null;
        $this->isMultisite = null;
        $this->schemaCache = null;
        $this->versionCache = array();
        return $this;
    }

    /**
     * Quote identifier
     *
     * @param   string  $id
     * @return  string
     */
    protected static function quoteIdentifier( $id )
    {
        return '"' . str_replace( '"', '""', $id ) . '"';
    }

    /**
     * Set db-schema(s)
     *
     * @param   array|string    $schemas
     * @return  null|string     The old schema
     */
    protected function setDbSchema( $schemas )
    {
        $db = $this->getDb();
        $oldSchema = null;

        if ( $schemas instanceof Traversable )
        {
            $schemas = iterator_to_array( $schemas );
        }

        /** @var $result \PDOStatement */
        $result = $db->query( 'SELECT UNNEST( CURRENT_SCHEMAS( FALSE ) )' );
        $current = $result->fetchAll( PDO::FETCH_COLUMN );
        $oldSchema = reset( $current );

        if ( ! is_array( $schemas ) )
        {
            $schemas = (string) $schemas;

            if ( $oldSchema == $schemas )
            {
                return $schemas;
            }

            array_shift( $current );
            array_unshift( $current, $schemas );
            $schemas = $current;
        }
        else if ( $schemas == $current )
        {
            return $oldSchema;
        }

        foreach ( $schemas as $schema )
        {
            $this->checkSchemaExists( $schema );
        }

        $setSql = implode(
            ', ',
            array_map(
                array( __CLASS__, 'quoteIdentifier' ),
                $schemas
            )
        );

        $db->exec( 'SET search_path TO ' . $setSql );

        $log = $this->getLog();
        $log( 'Set current schema to %s', $setSql );

        return $oldSchema;
    }

    /**
     * Check schema exists
     *
     * @param   string $schema
     * @return  void
     */
    protected function checkSchemaExists( $schema )
    {
        if ( ! empty( $this->schemaExists[$schema] ) )
        {
            return;
        }

        $exists = false;
        $db     = $this->getDb();
        $query  = $db->prepare( 'SELECT TRUE AS "exists"
                                   FROM information_schema.schemata
                                  WHERE schema_name = :schema' );

        $query->execute( array(
            'schema' => $schema,
        ) );

        while ( $row = $query->fetchObject() )
        {
            $exists = $exists || $row->exists;
        }

        if ( ! $exists )
        {
            $db->exec( 'CREATE SCHEMA ' . static::quoteIdentifier( $schema ) );
        }

        $this->schemaExists[$schema] = true;
    }

    /**
     * Get the db object
     *
     * @return  \PDO
     */
    public function getDb()
    {
        if ( null === $this->db )
        {
            $config = $this->getConfig();
            $dsn    = '';

            if ( isset( $config['dsn'] ) )
            {
                $dsn = $config['dsn'];
            }
            else
            {
                $dsn = 'pgsql:';
            }

            foreach ( array( 'host', 'port', 'dbname' ) as $param )
            {
                if ( isset( $config[$param]) )
                {
                    if ( ! in_array( substr( $dsn, -1 ), array( ':', ';' ) ) )
                    {
                        $dsn .= ';';
                    }

                    $dsn .= $param . '=' . $config[$param];
                }
            }

            $this->db = new PDO(
                $dsn,
                isset( $config['username'] ) ? $config['username'] : null,
                isset( $config['password'] ) ? $config['password'] : null,
                array(
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                )
            );

            if ( ! empty( $config['schema'] ) )
            {
                $this->setDbSchema( $config['schema'] );
            }
        }

        return $this->db;
    }

    /**
     * Set the db-adapter object
     *
     * @param   \PDO    $db
     * @return  Patcher
     */
    public function setDb( \PDO $db = null )
    {
        $this->db = $db;
        $this->config = array();
        $this->isMultisite = null;
        $this->schemaCache = null;
        $this->versionCache = array();
        return $this;
    }

    /**
     * Get log callback
     *
     * @return  callable
     */
    public function getLog()
    {
        return is_callable( $this->log ) ? $this->log : array( $this, 'noop' );
    }

    /**
     * Dummy fallback for log callback
     */
    public function noop()
    {
        // dummy function
    }

    /**
     * Constructor
     *
     * @param   null|array|PDO  $db
     * @param   null|callable   $log
     * @throws  Exception\InvalidArgumentException
     */
    public function __construct( $db = null, callable $log = null )
    {
        if ( $db instanceof \PDO )
        {
            $this->setDb( $db );
        }
        else if ( is_array( $db ) )
        {
            $this->setConfig( $db );
        }
        else if ( ! empty( $db ) )
        {
            throw new Exception\InvalidArgumentException( sprintf(
                '%s: $db must be a PDO instance,' .
                ' or a db-config array, "%s" given.',
                __METHOD__,
                is_object( $db ) ? get_class( $db ) : gettype( $db )
            ) );
        }

        $this->log = $log;
    }

    /**
     * Clear patch-info cache
     *
     * @return  Patcher
     */
    public function clearPatchInfoCache( $path = null )
    {
        if ( null === $path )
        {
            $this->fixInfoCache   = array();
            $this->patchInfoCache = array();
        }
        else
        {
            unset( $this->fixInfoCache[$path] );
            unset( $this->patchInfoCache[$path] );
        }

        return $this;
    }

    /**
     * Patch with sql-files under multiple paths
     *
     * @param   string|array|\Traversable   $paths
     * @param   string|null                 $toVersion
     * @param   array|null                  $onlySchemas
     * @return  void
     */
    public function patch( $paths, $toVersion = null, $onlySchemas = null )
    {
        if ( ! $paths instanceof Traversable && ! is_array( $paths ) )
        {
            $paths = array( (string) $paths );
        }

        $filter = function ( $path ) {
            return $path && is_dir( $path ) && is_readable( $path );
        };

        if ( is_array( $paths ) )
        {
            $paths = array_filter( $paths, $filter );

            if ( empty( $paths ) )
            {
                return;
            }
        }
        elseif ( $paths instanceof Traversable )
        {
            if ( $paths instanceof IteratorAggregate )
            {
                $paths = $paths->getIterator();
            }

            $paths = new CallbackFilterIterator( $paths, $filter );
        }

        $db = $this->getDb();
        $db->beginTransaction();
        $this->isMultisite();

        try
        {
            foreach ( $paths as $path )
            {
                $iterator = new CallbackFilterIterator(
                    new FilesystemIterator(
                        $path,
                        FilesystemIterator::CURRENT_AS_PATHNAME |
                        FilesystemIterator::KEY_AS_FILENAME |
                        FilesystemIterator::SKIP_DOTS |
                        FilesystemIterator::UNIX_PATHS
                    ),
                    function ( $current, $key, $iterator ) {
                        return $iterator->isDir() && '.' !== $key[0];
                    }
                );

                foreach ( $iterator as $section => $pathname )
                {
                    $this->patchSection( $pathname, $section, $toVersion, $onlySchemas );
                }
            }

            $db->commit();
        }
        catch ( \Exception $exception )
        {
            $db->rollBack();
            throw $exception;
        }
    }

    /**
     * Patch within a section
     *
     * @param   string      $path
     * @param   string      $section
     * @param   string|null $toVersion
     * @param   array|null  $onlySchemas
     * @return  void
     */
    protected function patchSection( $path,
                                     $section,
                                     $toVersion     = null,
                                     $onlySchemas   = null )
    {
        if ( is_dir( $dir = $path . '/common' ) && ( null === $onlySchemas || in_array( '_common', $onlySchemas ) ) )
        {
            $this->patchSchema( $dir, $section, '_common', $toVersion );
        }

        if ( is_dir( $dir = $path . '/central' ) && ( null === $onlySchemas || in_array( '_central', $onlySchemas ) ) )
        {
            $this->patchSchema( $dir, $section, '_central', $toVersion );
        }

        if ( is_dir( $dir = $path . '/site' ) )
        {
            $patchedSchemas = array();

            foreach ( $this->getSchemas() as $schema )
            {
                if ( null === $onlySchemas || in_array( $schema, $onlySchemas ) )
                {
                    $this->patchSchema( $dir, $section, $schema, $toVersion );
                    $patchedSchemas[] = $schema;
                }
            }

            if ( ! empty( $onlySchemas ) )
            {
                foreach ( $onlySchemas as $schema )
                {
                    if ( ! in_array( $schema, $patchedSchemas ) )
                    {
                        $this->patchSchema( $dir, $section, $schema, $toVersion );

                        if ( null !== $this->schemaCache )
                        {
                            $this->schemaCache[$schema] = $schema;
                        }
                    }
                }
            }
        }
    }

    /**
     * Is current db a multisite?
     *
     * @return  bool
     */
    public function isMultisite()
    {
        if ( null === $this->isMultisite )
        {
            $this->isMultisite = false;

            $db     = $this->getDb();
            $query  = $db->prepare( 'SELECT TRUE AS "exists"
                                       FROM information_schema.tables
                                      WHERE table_schema  = :schema
                                        AND table_name    = :table' );

            $query->execute( array(
                'schema'    => '_central',
                'table'     => 'site',
            ) );

            while ( $row = $query->fetchObject() )
            {
                $this->isMultisite = $this->isMultisite || $row->exists;
            }
        }

        return $this->isMultisite;
    }

    /**
     * Set multisite flag
     *
     * @param   bool    $flag
     * @return  Patcher
     */
    public function setMultisite( $flag = true )
    {
        $this->isMultisite = $flag === null ? null : (bool) $flag;
        return $this;
    }

    /**
     * Get schemas
     *
     * @return  array
     */
    protected function getSchemas()
    {
        if ( $this->isMultisite() )
        {
            if ( null === $this->schemaCache )
            {
                $db     = $this->getDb();
                $query  = $db->query( 'SELECT "schema" FROM "_central"."site"' );

                $query->execute();
                $this->schemaCache = array( '_template' => '_template' );

                while ( $row = $query->fetchObject() )
                {
                    $schema = $row->schema;
                    $this->schemaCache[$schema] = $schema;
                }
            }

            return $this->schemaCache;
        }

        return array( null );
    }

    /**
     * Patch a single schema
     *
     * @param   string      $path
     * @param   string      $section
     * @param   string|null $schema
     * @param   string|null $toVersion
     * @return  void
     */
    protected function patchSchema( $path,
                                    $section,
                                    $schema     = null,
                                    $toVersion  = null )
    {
        if ( null !== $schema )
        {
            $oldSchema = $this->setDbSchema( $schema );
        }

        $from = $this->getVersion( $section, $schema );

        if ( null === $toVersion || ! $from->version || version_compare( $from->version, '0', '==' ) )
        {
            $direction = 1;
        }
        else if ( ! $toVersion || version_compare( $toVersion, '0', '==' ) )
        {
            $direction = -1;
            $toVersion = '0';
        }
        else
        {
            $direction = version_compare( $toVersion, $from->version );
        }

        if ( $direction < 0 )
        {
            $this->downgradeSchema(
                $path,
                $section,
                $from->version,
                $from->fix,
                $toVersion,
                $schema
            );
        }
        else
        {
            $this->upgradeSchema(
                $path,
                $section,
                $from->version,
                $from->fix,
                $toVersion,
                $schema
            );
        }

        if ( null !== $schema )
        {
            $this->setDbSchema( $oldSchema );
        }
    }

    /**
     * Fix patch table
     *
     * @param   string  $schema
     * @param   string  $prefix
     * @param   array   $addColumns
     */
    private function fixPatchTable( $schema, $prefix, $addColumns )
    {
        $columnExists   = array();
        $db             = $this->getDb();
        $query          = $db->prepare( '
            SELECT column_name
              FROM information_schema.columns
             WHERE table_name   = \'patch\'
               AND table_schema = ' . ( empty( $schema )
                                        ? 'current_schema'
                                        : ':schema' ) . '
        ' );

        $query->execute( empty( $schema ) ? null : array(
            'schema' => $schema,
        ) );

        while ( $row = $query->fetchObject() )
        {
            $columnExists[$row->column_name] = true;
        }

        foreach ( $addColumns as $column => $definition )
        {
            if ( empty( $columnExists[$column] ) )
            {
                $db->exec( '
                    ALTER TABLE ' . $prefix . '"patch"
                     ADD COLUMN ' . static::quoteIdentifier( $column ) . '
                                ' . $definition . '
                ' );
            }
        }
    }

    /**
     * Get version of section (in a schema)
     *
     * @param   string      $section
     * @param   string|null $schema
     * @return  object
     */
    protected function getVersion( $section, $schema = null )
    {
        if ( ! isset( $this->versionCache[$schema] ) )
        {
            $db     = $this->getDb();
            $quoted = '';

            if ( $schema )
            {
                $this->checkSchemaExists( $schema );
                $quoted = static::quoteIdentifier( $schema ) . '.';
            }

            $db->exec( '
                CREATE TABLE IF NOT EXISTS ' . $quoted . '"patch"
                (
                    "id"        SERIAL              PRIMARY KEY,
                    "section"   CHARACTER VARYING   NOT NULL        UNIQUE,
                    "version"   CHARACTER VARYING   NOT NULL,
                    "fix"       INTEGER             NOT NULL        DEFAULT 0
                )
            ' );

            $this->fixPatchTable( $schema, $quoted, array(
                'fix' => 'INTEGER  NOT NULL  DEFAULT 0',
            ) );

            $query = $db->query( 'SELECT * FROM ' . $quoted . '"patch"' );
            $query->execute();

            while ( $row = $query->fetchObject() )
            {
                $this->versionCache[$schema][$row->section] = $row;
            }
        }

        $section = (string) $section;

        if ( empty( $this->versionCache[$schema][$section] ) )
        {
            return (object) array(
                'id'        => null,
                'section'   => $section,
                'version'   => '0',
                'fix'       => '0',
            );
        }

        return $this->versionCache[$schema][$section];
    }

    /**
     * Set version of section (in a schema)
     *
     * @param   string      $section
     * @param   string      $newVersion
     * @param   int         $newFix
     * @param   string|null $schema
     * @return  \Zork\Patcher\Patcher
     */
    protected function setVersion( $section,
                                   $newVersion,
                                   $newFix,
                                   $schema = null )
    {
        $old        = $this->getVersion( $section, $schema );
        $newVersion = (string) $newVersion;
        $newFix     = (string) (int) $newFix;

        if ( $old->version !== $newVersion || $old->fix !== $newFix )
        {
            $db     = $this->getDb();
            $prefix = $schema ? static::quoteIdentifier( $schema ) . '.' : '';
            $params = array();
            $insert = false;

            if ( ! $newVersion || version_compare( $newVersion, '0', '==' ) )
            {
                $query  = $db->prepare( '
                    DELETE FROM ' . $prefix . '"patch"
                          WHERE "section" = :section
                ' );

                $params = array(
                    'section' => $section,
                );
            }
            else if ( $old->version && version_compare( $old->version, '0', '>' ) )
            {
                $query  = $db->prepare( '
                    UPDATE ' . $prefix . '"patch"
                       SET "version"    = :version,
                           "fix"        = :fix
                     WHERE "section"    = :section
                ' );

                $params = array(
                    'section'   => $section,
                    'version'   => $newVersion,
                    'fix'       => $newFix,
                );
            }
            else
            {
                $query  = $db->prepare( '
                    INSERT INTO ' . $prefix . '"patch" ( "section", "version", "fix" )
                         VALUES ( :section, :version, :fix )
                ' );

                $params = array(
                    'section'   => $section,
                    'version'   => $newVersion,
                    'fix'       => $newFix,
                );
            }

            $query->execute( $params );

            if ( isset( $this->versionCache[$schema][$section] ) )
            {
                $this->versionCache[$schema][$section]->version = $newVersion;
                $this->versionCache[$schema][$section]->fix     = $newFix;
            }
            else
            {
                $query = $db->prepare( '
                    SELECT *
                      FROM ' . $prefix . '"patch"
                     WHERE "section" = :section
                ' );

                $query->execute( array(
                    'section' => $section,
                ) );

                while ( $row = $query->fetchObject() )
                {
                    $this->versionCache[$schema][$row->section] = $row;
                }
            }
        }

        $log = $this->getLog();

        $log(
            'Set version to %s (fix %s) at section %s in schema %s',
            $newVersion,
            $newFix,
            $section,
            $schema
        );

        return $this;
    }

    /**
     * Get fix info
     *
     * @param   string $path
     * @return  array
     */
    protected function getFixInfo( $path )
    {
        if ( isset( $this->fixInfoCache[$path] ) )
        {
            return $this->fixInfoCache[$path];
        }

        $iterator = new FilesystemIterator(
            $path,
            FilesystemIterator::CURRENT_AS_PATHNAME |
            FilesystemIterator::KEY_AS_FILENAME |
            FilesystemIterator::SKIP_DOTS |
            FilesystemIterator::UNIX_PATHS
        );

        $cache = array();

        foreach ( $iterator as $name => $pathname )
        {
            $matches = array();

            if ( preg_match( static::FIX_PATTERN, $name, $matches ) )
            {
                $cache[] = array(
                    'name'      => $name,
                    'path'      => $pathname,
                    'version'   => strtolower( $matches['version'] ),
                    'fix'       => (int) $matches['fix'],
                );
            }
        }

        return $this->fixInfoCache[$path] = $cache;
    }

    /**
     * Get patch info
     *
     * @param   string $path
     * @return  array
     */
    protected function getPatchInfo( $path )
    {
        if ( isset( $this->patchInfoCache[$path] ) )
        {
            return $this->patchInfoCache[$path];
        }

        $iterator = new FilesystemIterator(
            $path,
            FilesystemIterator::CURRENT_AS_PATHNAME |
            FilesystemIterator::KEY_AS_FILENAME |
            FilesystemIterator::SKIP_DOTS |
            FilesystemIterator::UNIX_PATHS
        );

        $data   = array();
        $schema = array();

        foreach ( $iterator as $name => $pathname )
        {
            $matches = array();

            if ( preg_match( static::PATCH_PATTERN, $name, $matches ) )
            {
                $store = array(
                    'name'  => $name,
                    'path'  => $pathname,
                    'type'  => strtolower( $matches['type'] ),
                    'from'  => strtolower( $matches['from'] ),
                    'to'    => strtolower( $matches['to'] ),
                );

                switch ( $matches['type'] )
                {
                    case 'data':    $data[]   = $store; break;
                    case 'schema':  $schema[] = $store; break;
                }
            }
        }

        return $this->patchInfoCache[$path] = array_merge( $schema, $data );
    }

    /**
     * Run patches from exact version to exact version
     *
     * @param   array   $info
     * @param   string  $fromVersion
     * @param   string  $toVersion
     * @return  void
     */
    private function runPatches( $info, $fromVersion, $toVersion )
    {
        foreach ( array( 'schema', 'data' ) as $type )
        {
            $patchFiles = $this->findPatchFiles(
                $info,
                $fromVersion,
                $toVersion,
                $type
            );

            foreach ( $patchFiles as $patchFile )
            {
                $this->runPatchFile( $patchFile );
            }
        }
    }

    /**
     * Run fixes from exact fix & return last ran fix
     *
     * @param   array   $info
     * @param   string  $version
     * @param   int     $fromFix
     * @return  int
     */
    private function runFixes( $info, $version, $fromFix )
    {
        $run        = array();
        $fromFix    = (int) $fromFix;

        foreach ( $info as $fix )
        {
            $i = (int) $fix['fix'];

            if ( ( $fix['version'] == $version ||
                   version_compare( $fix['version'], $version, '==' ) ) &&
                 $i > $fromFix )
            {
                $run[$i] = $fix['path'];
            }
        }

        ksort( $run );
        $lastFix = $fromFix;

        foreach ( $run as $fix => $path )
        {
            $this->runPatchFile( $path );
            $lastFix = $fix;
        }

        return $lastFix;
    }

    /**
     * Find max fix at an exact version, from info
     *
     * @param   array   $info
     * @param   string  $version
     * @return  int
     */
    private function findMaxFix( $info, $version )
    {
        $max = 0;

        foreach ( $info as $fix )
        {
            $i = (int) $fix['fix'];

            if ( ( $fix['version'] == $version ||
                   version_compare( $fix['version'], $version, '==' ) ) &&
                 $i > $max )
            {
                $max = $i;
            }
        }

        return $max;
    }

    /**
     * Run a single patch file
     *
     * @param   string  $file
     * @return  void
     * @throws  Exception\RuntimeException
     */
    private function runPatchFile( $file )
    {
        $db     = $this->getDb();
        $log    = $this->getLog();

        try
        {
            $db->exec( file_get_contents( $file ) );
        }
        catch ( PDOException $exception )
        {
            throw new Exception\RuntimeException(
                sprintf(
                    'PDOException: "%s"%s occured in patch: "%s"',
                    $exception->getMessage(),
                    PHP_EOL,
                    $file
                ),
                0,
                $exception
            );
        }

        $log( 'Patch ran at %s', $file );
    }

    /**
     * Find patch files form a version to another
     *
     * @param   array   $info
     * @param   string  $fromVersion
     * @param   string  $toVersion
     * @param   string  $type
     * @return  array
     */
    private function findPatchFiles( $info, $fromVersion, $toVersion, $type )
    {
        $direction = version_compare( $toVersion, $fromVersion );

        if ( 0 == $direction )
        {
            return array();
        }

        $exact = $this->findExactPatchFile(
            $info,
            $fromVersion,
            $toVersion,
            $type
        );

        if ( null !== $exact )
        {
            return array( $exact );
        }

        $paths = array();
        $prev = $next = $fromVersion;

        switch ( true )
        {
            case $direction > 0: // upgrade
                while ( true )
                {
                    $prev = $next;
                    $next = $this->getNextVersion(
                        $info,
                        $prev,
                        $toVersion,
                        $type
                    );

                    if ( null === $next )
                    {
                        break;
                    }

                    $paths[] = $this->findExactPatchFile(
                        $info,
                        $prev,
                        $next,
                        $type
                    );
                }
                break;

            case $direction < 0: // downgrade
                while ( true )
                {
                    $prev = $next;
                    $next = $this->getPrevVersion(
                        $info,
                        $prev,
                        $toVersion,
                        $type
                    );

                    if ( null === $next )
                    {
                        break;
                    }

                    $paths[] = $this->findExactPatchFile(
                        $info,
                        $prev,
                        $next,
                        $type
                    );
                }

                if ( $prev !== $toVersion )
                {
                    $next = $this->getLastVersion(
                        $info,
                        $prev,
                        $toVersion,
                        $type
                    );

                    if ( null !== $next )
                    {
                        $paths[] = $this->findExactPatchFile(
                            $info,
                            $prev,
                            $next,
                            $type
                        );
                    }
                }
                break;
        }

        return $paths;
    }

    /**
     * Find an exact patch file form a version to another
     *
     * @param   array   $info
     * @param   string  $fromVersion
     * @param   string  $toVersion
     * @param   string  $type
     * @return  string|null
     */
    private function findExactPatchFile( $info,
                                         $fromVersion,
                                         $toVersion,
                                         $type )
    {
        foreach ( $info as $patch )
        {
            if ( $patch['from'] == $fromVersion &&
                 $patch['to']   == $toVersion &&
                 $patch['type'] == $type )
            {
                return $patch['path'];
            }
        }

        return null;
    }

    /**
     * Patch a single schema
     *
     * @param   string      $path
     * @param   string      $section
     * @param   string      $fromVersion
     * @param   int         $fromFix
     * @param   string|null $toVersion
     * @param   string|null $schema
     * @return  void
     */
    private function upgradeSchema( $path,
                                    $section,
                                    $fromVersion,
                                    $fromFix,
                                    $toVersion  = null,
                                    $schema     = null )
    {
        $fixInfo    = $this->getFixInfo( $path );
        $newFix     = $this->runFixes( $fixInfo, $fromVersion, (int) $fromFix );

        if ( $newFix > $fromFix )
        {
            $this->setVersion( $section, $fromVersion, $newFix, $schema );
        }

        $info = $this->getPatchInfo( $path );
        $prev = $next = $fromVersion;

        while ( true )
        {
            $prev = $next;
            $next = $this->getNextVersion( $info, $prev, $toVersion );

            if ( null === $next )
            {
                break;
            }

            $this->runPatches( $info, $prev, $next );
        }

        if ( $prev !== $fromVersion &&
             version_compare( $prev, $fromVersion, '!=' ) )
        {
            $newFix = $this->findMaxFix( $fixInfo, $prev );
            $this->setVersion( $section, $prev, $newFix, $schema );
        }
    }

    /**
     * Patch a single schema
     *
     * @param   string      $path
     * @param   string      $section
     * @param   string      $fromVersion
     * @param   int         $fromFix
     * @param   string|null $toVersion
     * @param   string|null $schema
     * @return  void
     */
    private function downgradeSchema( $path,
                                      $section,
                                      $fromVersion,
                                      $fromFix,
                                      $toVersion    = null,
                                      $schema       = null )
    {
        $info = $this->getPatchInfo( $path );
        $prev = $next = $fromVersion;

        while ( true )
        {
            $prev = $next;
            $next = $this->getPrevVersion( $info, $prev, $toVersion );

            if ( null === $next )
            {
                break;
            }

            $this->runPatches( $info, $prev, $next );
        }

        if ( $prev !== $toVersion )
        {
            $next = $this->getLastVersion( $info, $prev, $toVersion );

            if ( null !== $next )
            {
                $this->runPatches( $info, $prev, $next );
                $prev = $next;
            }
        }

        if ( $prev !== $fromVersion &&
             version_compare( $prev, $fromVersion, '!=' ) )
        {
            $fixInfo    = $this->getFixInfo( $path );
            $newFix     = $this->findMaxFix( $fixInfo, $prev );
            $this->setVersion( $section, $prev, $newFix, $schema );
        }
    }

    /**
     * Get next version
     *
     * @param   array       $info
     * @param   string      $fromVersion
     * @param   string      $toVersion
     * @param   null|string $type
     * @return  string
     */
    private function getNextVersion( $info,
                                     $fromVersion,
                                     $toVersion,
                                     $type = null )
    {
        $max = null;

        foreach ( $info as $patch )
        {
            if ( null !== $type && $patch['type'] != $type )
            {
                continue;
            }

            if ( $patch['from'] == $fromVersion &&
                 version_compare( $patch['to'], $patch['from'], '>' ) &&
              // ( no max || ( current is greater than max                && ( no upper bound || current is under the upper bound                ) ) )
                 ( ! $max || ( version_compare( $patch['to'], $max, '>' ) && ( ! $toVersion || version_compare( $patch['to'], $toVersion, '<=' ) ) ) ) )
            {
                $max = $patch['to'];
            }
        }

        return $max;
    }

    /**
     * Get prev version
     *
     * @param   array       $info
     * @param   string      $fromVersion
     * @param   string      $toVersion
     * @param   null|string $type
     * @return  string
     */
    private function getPrevVersion( $info,
                                     $fromVersion,
                                     $toVersion,
                                     $type = null )
    {
        $min = null;

        foreach ( $info as $patch )
        {
            if ( null !== $type && $patch['type'] != $type )
            {
                continue;
            }

            if ( $patch['from'] == $fromVersion &&
                 version_compare( $patch['to'], $patch['from'], '<' ) &&
                // ( no min || ( current is lesser than min                 && ( no lower bound || current is above the lower bound                ) ) )
                   ( ! $min || ( version_compare( $patch['to'], $min, '<' ) && ( ! $toVersion || version_compare( $patch['to'], $toVersion, '>=' ) ) ) ) )
            {
                $min = $patch['to'];
            }
        }

        return $min;
    }

    /**
     * Get last version
     *
     * @param   array       $info
     * @param   string      $fromVersion
     * @param   string      $toVersion
     * @param   null|string $type
     * @return  string
     */
    private function getLastVersion( $info,
                                     $fromVersion,
                                     $toVersion,
                                     $type = null )
    {
        $max = null;

        foreach ( $info as $patch )
        {
            if ( null !== $type && $patch['type'] != $type )
            {
                continue;
            }

            if ( $patch['from'] == $fromVersion &&
                 version_compare( $patch['to'], $patch['from'], '<' ) &&
                // ( no max || ( current is greater than max                && current is under the lower bound                ) )
                   ( ! $max || ( version_compare( $patch['to'], $max, '>' ) && version_compare( $patch['to'], $toVersion, '<=' ) ) ) )
            {
                $max = $patch['to'];
            }
        }

        return $max;
    }

}
