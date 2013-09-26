<?php

namespace Grid\Installer;

use PDOException;
use FilesystemIterator;
use CallbackFilterIterator;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use RecursiveCallbackFilterIterator;
use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Installer\LibraryInstaller;
use Composer\Repository\InstalledRepositoryInterface;

/**
 * ModuleInstaller
 *
 * @author David Pozsar <david.pozsar@megaweb.hu>
 */
class ModuleInstaller extends LibraryInstaller
{

    /**
     * @const string
     */
    const TYPE_MODULE = 'gridguyz-module';

    /**
     * @const string
     */
    const TYPE_MODULES = 'gridguyz-modules';

    /**
     * @const string
     */
    const DEFAULT_PUBLIC_DIR = 'public';

    /**
     * @const string
     */
    const DEFAULT_PATCH_DIR = 'sql';

    /**
     * @const string
     */
    const DEFAULT_CONFIG_PATH = 'config/autoload';

    /**
     * Default config files
     *
     * @var array
     */
    protected static $defaultConfigFiles = array(
        'application'   => 'application.php',
        'db'            => 'db.local.php',
    );

    /**
     * Supported types
     *
     * @var array
     */
    protected static $supportedTypes = array(
        self::TYPE_MODULE,
        self::TYPE_MODULES,
    );

    /**
     * Sub-directories under $publicDir
     *
     * @var array
     */
    protected static $subDirs = array(
        'app',
        'images',
        'scripts',
        'styles',
    );

    /**
     * Public dir
     *
     * @var string
     */
    protected $publicDir = self::DEFAULT_PUBLIC_DIR;

    /**
     * Chmod mode
     *
     * @var int
     */
    protected $chmodMode = 0777;

    /**
     * Chmod entries
     *
     * @var array
     */
    protected $chmodEntries = array(
        'public',
        'public/thumbnails',
        'public/uploads',
        'public/tmp',
        'public/tmp/captcha',
        'data',
        'data/cache',
        'data/my-translations',
        'data/update',
        'log',
        'log/exception',
        'log/mail',
    );

    /**
     * Custom patch-data
     *
     * @var \Grid\Installer\PatchData
     */
    protected $patchData;

    /**
     * Patcher
     *
     * @var \Grid\Installer\Patcher
     */
    protected $patcher;

    /**
     * Repository
     *
     * @var \Composer\Repository\RepositoryInterface
     */
    protected $repository;

    /**
     * Config path
     *
     * @var string
     */
    protected $configPath;

    /**
     * Config data
     *
     * @var array
     */
    protected $configData = array();

    /**
     * Config files
     *
     * @var string
     */
    protected $configFiles = array();

    /**
     * Get custom patch-data
     *
     * @return  PatchData
     */
    public function getPatchData()
    {
        return $this->patchData;
    }

    /**
     * Get patcher
     *
     * @return  Patcher
     */
    public function getPatcher()
    {
        return $this->patcher;
    }

    /**
     * Repository
     *
     * @return  \Composer\Repository\RepositoryInterface
     */
    public function getRepository()
    {
        return $this->repository;
    }

    /**
     * Get extra data from root package
     *
     * @return  array
     */
    public function getExtra()
    {
        return $this->composer
                    ->getPackage()
                    ->getExtra();
    }

    /**
     * Get config file
     *
     * @param   string  $config
     * @return  string
     */
    public function getConfigFile( $config )
    {
        $extra = $this->getExtra();

        if ( null === $this->configPath )
        {
            $this->configPath = isset( $extra['config-path'] )
                    ? trim( $extra['config-path'], '/' )
                    : static::DEFAULT_CONFIG_PATH;
        }

        if ( empty( $this->configFiles[$config] ) )
        {
            $this->configFiles[$config] = $config . '.php';

            if ( isset( $extra['config-files'][$config] ) )
            {
                $this->configFiles[$config] = $extra['config-files'][$config];
            }
            else if ( isset( static::$defaultConfigFiles[$config] ) )
            {
                $this->configFiles[$config] = static::$defaultConfigFiles[$config];
            }
        }

        return $this->configPath . '/' . $this->configFiles[$config];
    }

    /**
     * Get config data
     *
     * @return  array
     */
    public function getConfigData( $config )
    {
        if ( ! isset( $this->configData[$config] ) )
        {
            $this->configData[$config] = array();
            $file = $this->getConfigFile( $config );

            if ( is_file( $file ) )
            {
                $this->configData[$config] = (array) include $file;
            }
        }

        return $this->configData[$config];
    }

    /**
     * Set config data (& write to file)
     *
     * @param   array   $data
     * @return  ModuleInstaller
     */
    public function setConfigData( $config, array $data )
    {
        file_put_contents(
            $this->getConfigFile( $config ),
            sprintf(
                '%s%s%sreturn %s;%s',
                '<',
                '?php',
                PHP_EOL,
                var_export( $this->configData[$config] = $data, true ),
                PHP_EOL
            )
        );

        return $this;
    }

    /**
     * Clear config data cache
     *
     * @param   string|null $config
     * @return  \Grid\Installer\ModuleInstaller
     */
    public function clearConfigDataCache( $config = null )
    {
        if ( empty( $config ) )
        {
            $this->configData = array();
        }
        else
        {
            unset( $this->configData[$config] );
        }

        return $this;
    }

    /**
     * Merge config data (& write to file)
     *
     * @param   array   $data
     * @return  ModuleInstaller
     */
    public function mergeConfigData( $config, array $data )
    {
        $merge = $this->getConfigData( $config );
        self::merge( $merge, $data );
        return $this->setConfigData( $config, $merge );
    }

    /**
     * Merge arrays
     *
     * @param   array   $array
     * @param   array   $with
     */
    public static function merge( array & $array, array & $with )
    {
        foreach ( $with as $key => & $value )
        {
            if ( is_numeric( $key ) )
            {
                $array[] = $value;
            }
            else if ( ! isset( $array[$key] ) ||
                      ! is_array( $value ) ||
                      ! is_array( $array[$key] ) )
            {
                $array[$key] = $value;
            }
            else
            {
                self::merge( $array[$key], $value );
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function __construct( IOInterface $io,
                                 Composer $composer,
                                 $type = self::TYPE_MODULE )
    {
        parent::__construct( $io, $composer, $type );
        $extra = $composer->getPackage()->getExtra();

        $this->repository = $composer->getRepositoryManager()
                                     ->getLocalRepository();

        if ( isset( $extra['chmod-entries'] ) )
        {
            $this->chmodEntries = array_merge(
                $this->chmodEntries,
                (array) $extra['chmod-entries']
            );
        }

        if ( isset( $extra['chmod-mode'] ) )
        {
            $this->chmodMode = (int) $extra['chmod-mode'];
        }

        if ( isset( $extra['public-dir'] ) )
        {
            $this->publicDir = rtrim( $extra['public-dir'], '/' );
        }

        $this->patchData = new PatchData( $io );

        $this->getPatchData()
             ->addData( $this->getConfigData( 'db' ) );

        if ( isset( $extra['patch-data-file'] ) && is_file( $extra['patch-data-file'] ) )
        {
            switch ( strrchr( $extra['patch-data-file'], '.' ) )
            {
                case '.inc':
                case '.php':
                    $data = (array) include $extra['patch-data-file'];
                    break;

                case '.json':
                    $data = @ json_decode( file_get_contents( $extra['patch-data-file'] ), true );
                    break;

                case '.ini':
                    $data = @ parse_ini_file( $extra['patch-data-file'], true );
                    break;

                default:
                    $data = array();
            }

            $this->getPatchData()
                 ->addData( $data );
        }

        if ( isset( $extra['patch-data'] ) )
        {
            $this->getPatchData()
                 ->addData( $extra['patch-data'] );
        }

        if ( ! is_dir( $this->publicDir ) )
        {
            throw new Exception\RuntimeException( sprintf(
                '%s: Public directory "%s" does not exists',
                __METHOD__,
                $this->publicDir
            ) );
        }

        foreach ( $this->chmodEntries as $dir )
        {
            chmod( $dir, $this->chmodMode );
        }

        foreach ( static::$subDirs as $subDir )
        {
            $dir = $this->publicDir . '/' . $subDir;

            if ( ! is_dir( $dir ) || ! is_writable( $dir ) )
            {
                throw new Exception\RuntimeException( sprintf(
                    '%s: Directory "%s" under public directory "%s"' .
                    ' does not exists, or not writable',
                    __METHOD__,
                    $subDir,
                    $this->publicDir
                ) );
            }
        }

        $host   = $this->getPatchData()
                       ->get( 'db',
                              'host',
                              'Type your PostgreSQL database\'s host',
                              'localhost' );
        $port   = (int) $this->getPatchData()
                             ->get( 'db',
                                    'port',
                                    'Type your PostgreSQL database\'s port',
                                    '5432' );
        $user   = $this->getPatchData()
                       ->get( 'db',
                              'username',
                              'Type your PostgreSQL database\'s username' );
        $passwd = $this->getPatchData()
                       ->get( 'db',
                              'password',
                              'Type your PostgreSQL database\'s password',
                              null,
                              true );
        $dbname = $this->getPatchData()
                       ->get( 'db',
                              'dbname',
                              'Type your PostgreSQL database\'s dbname',
                              'gridguyz' );
        $schema = $this->getPatchData()
                       ->get( 'db',
                              'schema',
                              'Type your PostgreSQL database\'s schema name',
                              preg_replace( '/[^a-zA-Z0-9_]/', '_', $dbname ) );

        if ( ! is_array( $schema ) )
        {
            $schema = array( $schema, '_common' );
        }

        $dbConfigData = array(
            'driver'    => 'Pdo',
            'pdodriver' => 'pgsql',
            'host'      => $host,
            'port'      => $port,
            'username'  => $user,
            'password'  => $passwd,
            'dbname'    => $dbname,
            'schema'    => $schema,
        );

        $previous = null;

        try
        {
            $this->patcher  = new Patcher( $dbConfigData, array( $this, 'patchLog' ) );
            $db             = $this->getPatcher()->getDb();
        }
        catch ( PDOException $ex )
        {
            $previous = $ex;
        }

        if ( empty( $db ) )
        {
            throw new Exception\RuntimeException(
                sprintf(
                    '%s: Cannot connect to PostgreSQL at %s:%d/%s',
                    __METHOD__,
                    $host,
                    $port,
                    $dbname
                ),
                0,
                $previous
            );
        }

        if ( ! is_file( $this->getConfigFile( 'application' ) ) )
        {
            $this->setConfigData( 'application', array() );
        }

        if ( ! is_file( $this->getConfigFile( 'db' ) ) )
        {
            $this->setConfigData( 'db', array( 'db' => $dbConfigData ) );
        }
    }

    /**
     * Patch log callback
     *
     * @param   string  $format
     * @patam   string  $...
     * @return  void
     */
    public function patchLog( $format )
    {
        $params = func_get_args();
        $format = (string) array_shift( $params );

        foreach ( $params as & $param )
        {
            if ( null === $param )
            {
                $param = '<comment>null</comment>';
            }
            else
            {
                $param = (string) $param;

                if ( preg_match( '#^([/\\\\]|[a-zA-Z]:[/\\\\])#', $param )
                     && file_exists( $param ) )
                {
                    $param = $this->getRelativePath( $param );
                }

                $param = '<info>' . $param . '</info>';
            }
        }

        $this->io->write( '[patcher] ' . vsprintf( $format, $params ) );
    }

    /**
     * {@inheritDoc}
     */
    public function supports( $packageType )
    {
        return in_array( $packageType, static::$supportedTypes );
    }

    /**
     * {@inheritDoc}
     */
    public function install( InstalledRepositoryInterface $repo,
                             PackageInterface $package )
    {
        $this->repository = $repo;
        parent::install( $repo, $package );

        $this->beforePatches( $package, '0', $package->getVersion() );

        $modules = 0;

        foreach ( $this->getModulesPaths( $package ) as $path )
        {
            $this->installModule( $path, $package );
            $modules++;
        }

        if ( $modules )
        {
            $this->io->write( '' );
        }

        $this->afterPatches( $package, '0', $package->getVersion() );
    }

    /**
     * {@inheritDoc}
     */
    public function update( InstalledRepositoryInterface $repo,
                            PackageInterface $initial,
                            PackageInterface $target )
    {
        $this->repository = $repo;
        $this->beforePatches( $initial, $initial->getVersion(), $target->getVersion() );

        if ( $repo->hasPackage( $initial ) )
        {
            $modules = 0;

            foreach ( $this->getModulesPaths( $initial ) as $path )
            {
                $this->beforeUpdateModule( $path, $initial );
                $modules++;
            }

            if ( $modules )
            {
                $this->io->write( '' );
            }
        }

        parent::update( $repo, $initial, $target );

        $modules = 0;

        foreach ( $this->getModulesPaths( $target ) as $path )
        {
            $this->afterUpdateModule( $path, $target );
            $modules++;
        }

        if ( $modules )
        {
            $this->io->write( '' );
        }

        $this->afterPatches( $initial, $initial->getVersion(), $target->getVersion() );
    }

    /**
     * {@inheritDoc}
     */
    public function uninstall( InstalledRepositoryInterface $repo,
                               PackageInterface $package )
    {
        $this->repository = $repo;
        $this->beforePatches( $package, $package->getVersion(), '0' );

        if ( $repo->hasPackage( $package ) )
        {
            $modules = 0;

            foreach ( $this->getModulesPaths( $package ) as $path )
            {
                $this->uninstallModule( $path, $package );
                $modules++;
            }

            if ( $modules )
            {
                $this->io->write( '' );
            }
        }

        $this->afterPatches( $package, $package->getVersion(), '0' );

        parent::uninstall( $repo, $package );
    }

    /**
     * Run before patches
     *
     * @param   PackageInterface    $package
     * @param   string              $from
     * @param   string              $to
     * @return  void
     * @throws  Exception\RuntimeException
     */
    protected function beforePatches( PackageInterface $package, $from, $to )
    {
        $this->runPatchMethod( $package, $from, $to, 'beforePatch' );
    }

    /**
     * Run after patches
     *
     * @param   PackageInterface    $package
     * @param   string              $from
     * @param   string              $to
     * @return  void
     * @throws  Exception\RuntimeException
     */
    protected function afterPatches( PackageInterface $package, $from, $to )
    {
        $this->runPatchMethod( $package, $from, $to, 'afterPatch' );
    }

    /**
     * Run a patch method
     *
     * Method could be:
     * - beforePatch
     * - afterPatch
     *
     * @param   PackageInterface    $package
     * @param   string              $from
     * @param   string              $to
     * @param   string              $method
     * @throws  Exception\RuntimeException
     */
    private function runPatchMethod( PackageInterface $package, $from, $to, $method )
    {
        $extra = $package->getExtra();

        if ( isset( $extra['patch-classes'] ) )
        {
            $db       = $this->getPatcher()->getDb();
            $basePath = rtrim( $this->getInstallPath( $package ), '/' );

            $db->beginTransaction();

            try
            {
                foreach ( (array) $extra['patch-classes'] as $class => $path )
                {
                    $cleanUp = false;

                    if ( $path )
                    {
                        $effectiveClass = $class;
                        $fullPath       = $basePath . '/' . ltrim( $path, '/' );

                        if ( ! is_file( $fullPath ) || ! is_readable( $fullPath ) )
                        {
                            throw new Exception\RuntimeException( sprintf(
                                '%s: file not found (or not readable) at "%s" for patch class "%s"',
                                __METHOD__,
                                $path,
                                $class
                            ) );
                        }

                        if ( class_exists( $class ) )
                        {
                            $suffix   = 0;
                            $basePath = preg_replace( '/\\.(php|inc)$/', '', $fullPath );

                            while ( class_exists( $class . '_tmp' . $suffix ) ||
                                    is_file( $basePath . '_tmp'. $suffix . '.php' ) )
                            {
                                $suffix++;
                            }

                            $suffix         = '_tmp' . $suffix;
                            $effectiveClass = $class . $suffix;
                            $cleanUp        = $basePath . $suffix . '.php';

                            file_put_contents(
                                $cleanUp,
                                preg_replace(
                                    '/class\\s+([^\\s\\{]+)/',
                                    'class $1' . $suffix,
                                    file_get_contents( $fullPath ),
                                    1
                                )
                            );

                            $fullPath = $cleanUp;
                        }

                        require_once $fullPath;
                    }

                    if ( ! class_exists( $effectiveClass ) )
                    {
                        if ( $cleanUp )
                        {
                            unlink( $cleanUp );
                            $cleanUp = false;
                        }

                        throw new Exception\RuntimeException( sprintf(
                            '%s: patch class "%s" not found in file "%s"',
                            __METHOD__,
                            $class,
                            $path
                        ) );
                    }

                    $patch = new $effectiveClass( $this );
                    $patch->$method( $from, $to );

                    if ( $cleanUp )
                    {
                        unlink( $cleanUp );
                        $cleanUp = false;
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
    }

    /**
     * Get modules' path
     *
     * @param   PackageInterface $package
     * @return  array|\Traversable
     */
    public function getModulesPaths( PackageInterface $package )
    {
        $path = $this->getInstallPath( $package );

        switch ( $package->getType() )
        {
            case static::TYPE_MODULE:
                return array( $path );

            case static::TYPE_MODULES:
                $extra  = $package->getExtra();
                $module = isset( $extra['module-dir'] )
                        ? trim( $extra['module-dir'], '/' )
                        : 'module';

                return new CallbackFilterIterator(
                    new FilesystemIterator(
                        $path . '/' . $module,
                        FilesystemIterator::CURRENT_AS_PATHNAME |
                        FilesystemIterator::KEY_AS_FILENAME |
                        FilesystemIterator::SKIP_DOTS |
                        FilesystemIterator::UNIX_PATHS
                    ),
                    function ( $current, $key, $iterator ) {
                        return $iterator->isDir() && '.' !== $key[0];
                    }
                );

            default:
                throw new RuntimeException( aprintf(
                    '%s: package-type "%s" does not supported',
                    __METHOD__,
                    $package->getType()
                ) );
        }
    }

    /**
     * Install a module
     *
     * @param   string              $path
     * @param   PackageInterface    $package
     * @return  void
     */
    protected function installModule( $path, PackageInterface $package )
    {
        $this->io->write( sprintf(
            '    Install gridguyz-module: <info>%s</info>',
            $this->getRelativePath( $path )
        ) );

        $this->copyPublic( $path, $package );
        $this->patch( $path, $package, $package->getVersion() );
    }

    /**
     * Before update a module
     *
     * @param   string              $path
     * @param   PackageInterface    $package
     * @return  void
     */
    protected function beforeUpdateModule( $path, PackageInterface $package )
    {
        $this->io->write( sprintf(
            '    Before update gridguyz-module: <info>%s</info>',
            $this->getRelativePath( $path )
        ) );

        $this->removePublic( $path, $package );
    }

    /**
     * After update a module
     *
     * @param   string              $path
     * @param   PackageInterface    $package
     * @return  void
     */
    protected function afterUpdateModule( $path, PackageInterface $package )
    {
        $this->io->write( sprintf(
            '    After update gridguyz-module: <info>%s</info>',
            $this->getRelativePath( $path )
        ) );

        $this->copyPublic( $path, $package );
        $this->patch( $path, $package, $package->getVersion() );
    }

    /**
     * Install a module
     *
     * @param   string              $path
     * @param   PackageInterface    $package
     * @return  void
     */
    protected function uninstallModule( $path, PackageInterface $package )
    {
        $this->io->write( sprintf(
            '    Uninstall gridguyz-module: <info>%s</info>',
            $this->getRelativePath( $path )
        ) );

        $this->removePublic( $path, $package );
        $this->patch( $path, $package, '0' );
    }

    /**
     * Get path parts
     *
     * @param   string  $path
     * @param   string  $sep
     * @return  array
     */
    private function getPathParts( $path, $sep = '/' )
    {
        $sep = $sep[0];

        return explode(
            $sep,
            trim(
                str_replace(
                    DIRECTORY_SEPARATOR,
                    $sep,
                    realpath( $path )
                ),
                $sep
            )
        );
    }

    /**
     * Get relative path
     *
     * @param   string  $path
     * @param   string  $from
     * @return  string
     */
    protected function getRelativePath( $path, $from = '.' )
    {
        $path = $this->getPathParts( $path );
        $from = $this->getPathParts( $from ?: '.' );

        while ( isset( $path[0] ) && isset( $from[0] ) && $path[0] === $from[0] )
        {
            array_shift( $path );
            array_shift( $from );
        }

        while ( ! empty( $from ) )
        {
            array_shift( $from );
            array_unshift( $path, '..' );
        }

        return implode( DIRECTORY_SEPARATOR, $path );
    }

    /**
     * Copy files to public-dir
     *
     * @param   string  $path
     * @return  void
     */
    protected function copyPublic( $path, PackageInterface $package )
    {
        $extra  = $package->getExtra();
        $public = isset( $extra['public-dir'] )
                ? trim( $extra['public-dir'], '/' )
                : static::DEFAULT_PUBLIC_DIR;

        if ( ! is_dir( $path . '/' . $public ) )
        {
            return;
        }

        foreach ( static::$subDirs as $sub )
        {
            $dir = $path . '/' . $public . '/' . $sub;

            if ( ! is_dir( $dir ) || ! is_readable( $dir ) )
            {
                continue;
            }

            $this->io->write( sprintf(
                '      Copy contents of <info>%s</info> into public',
                $this->getRelativePath( $dir )
            ) );

            foreach ( $this->getPublicDirIterator( $dir, true ) as $entry )
            {
                $dest = $this->publicDir
                      . '/' . $sub
                      . '/' . ltrim( $entry->getSubPathname(), '/' );

                if ( $entry->isDir() )
                {
                    @mkdir( $dest, 0777, true );
                }
                else if ( $entry->isFile() )
                {
                    @copy( $entry->getPathname(), $dest );
                }
            }
        }
    }

    /**
     * Remove files from public-dir
     *
     * @param   PackageInterface    $package
     * @return  void
     */
    protected function removePublic( $path, PackageInterface $package )
    {
        $extra  = $package->getExtra();
        $public = isset( $extra['public-dir'] )
                ? trim( $extra['public-dir'], '/' )
                : static::DEFAULT_PUBLIC_DIR;

        if ( ! is_dir( $path . '/' . $public ) )
        {
            return;
        }

        foreach ( static::$subDirs as $sub )
        {
            $dir = $path . '/' . $public . '/' . $sub;

            if ( ! is_dir( $dir ) || ! is_readable( $dir ) )
            {
                continue;
            }

            $this->io->write( sprintf(
                '      Remove contents of <info>%s</info> from public',
                $this->getRelativePath( $dir )
            ) );

            foreach ( $this->getPublicDirIterator( $dir, false ) as $entry )
            {
                $dest = $this->publicDir
                      . '/' . $sub
                      . '/' . ltrim( $entry->getSubPathname(), '/' );

                if ( $entry->isDir() )
                {
                    @rmdir( $dest );
                }
                else if ( $entry->isFile() )
                {
                    @unlink( $dest );
                }
            }
        }
    }

    /**
     * Get public-dir iterator
     *
     * @param   string  $path
     * @param   bool    $selfFirst
     * @return  RecursiveIteratorIterator
     */
    private function getPublicDirIterator( $path, $selfFirst )
    {
        return new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator(
                    $path,
                    RecursiveDirectoryIterator::CURRENT_AS_SELF |
                    RecursiveDirectoryIterator::KEY_AS_FILENAME |
                    RecursiveDirectoryIterator::SKIP_DOTS |
                    RecursiveDirectoryIterator::UNIX_PATHS
                ),
                function ( $current, $key ) {
                    return '.' !== $key[0];
                }
            ),
            $selfFirst
                ? RecursiveIteratorIterator::SELF_FIRST
                : RecursiveIteratorIterator::CHILD_FIRST
        );
    }

    /**
     * Run patch
     *
     * @param   string              $path
     * @param   PackageInterface    $package
     * @param   string              $toVersion
     * @param   array|null          $onlySchemas
     * @return  void
     */
    public function patch( $path, PackageInterface $package, $toVersion = null, $onlySchemas = null )
    {
        $extra  = $package->getExtra();
        $patch  = isset( $extra['patch-dir'] )
                ? trim( $extra['patch-dir'], '/' )
                : static::DEFAULT_PATCH_DIR;

        if ( is_dir( $dir = $path . '/' . $patch ) )
        {
            $this->io->write( sprintf(
                '      Run patches at <info>%s</info>',
                $this->getRelativePath( $dir )
            ) );

            $this->io->write(
                '        for schema(s): ' . (
                    null === $onlySchemas
                        ? 'all'
                        : '<info>' . implode( '</info>, <info>', $onlySchemas ) . '</info>'
                )
            );

            $this->getPatcher()
                 ->patch( array( $dir ), $toVersion, $onlySchemas );
        }
    }

    /**
     * Convert platform to multisite
     *
     * @return  void
     */
    public function convertToMultisite()
    {
        $patcher = $this->getPatcher();

        if ( $patcher->isMultisite() )
        {
            return;
        }

        $patcher->setMultisite( true );

        foreach ( $this->getRepository()->getPackages() as $package )
        {
            if ( ! $this->supports( $package->getType() ) )
            {
                continue;
            }

            foreach ( $this->getModulesPaths( $package ) as $path )
            {
                $this->patch( $path, $package, $package->getVersion(), array( '_template' ) );
            }
        }
    }

}
