<?php

namespace Grid\Installer;

use Exception;
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
    const DEFAULT_DB_CONFIG = 'config/autoload/db.local.php';

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
     * {@inheritDoc}
     */
    public function __construct( IOInterface $io,
                                 Composer $composer,
                                 $type = self::TYPE_MODULE )
    {
        parent::__construct( $io, $composer, $type );
        $extra = $composer->getPackage()->getExtra();

        if ( isset( $extra['public-dir'] ) )
        {
            $this->publicDir = rtrim( $extra['public-dir'], '/' );
        }

        $this->patchData = new PatchData( $io );

        $dbConfigFile = static::DEFAULT_DB_CONFIG;

        if ( isset( $extra['db-config'] ) )
        {
            $dbConfigFile = ltrim( $extra['db-config'], '/' );
        }

        if ( is_file( $dbConfigFile ) && is_readable( $dbConfigFile ) )
        {
            $this->patchData->addData( include $dbConfigFile );
        }

        if ( isset( $extra['patch-data'] ) )
        {
            $this->patchData->addData( $extra['patch-data'] );
        }

        if ( ! is_dir( $this->publicDir ) )
        {
            throw new Exception\RuntimeException( sprintf(
                '%s: Public directory "%s" does not exists',
                __METHOD__,
                $this->publicDir
            ) );
        }

        foreach ( static::$subDirs as $subDir )
        {
            $dir = $this->publicDir . '/' . $subDir;

            if ( ! is_dir( $dir ) || !is_writable( $dir ) )
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

        $host   = $this->patchData->get( 'db', 'host', 'Type your PostgreSQL database\'s host', 'localhost' );
        $port   = (int) $this->patchData->get( 'db', 'port', 'Type your PostgreSQL database\'s port', '5432' );
        $user   = $this->patchData->get( 'db', 'username', 'Type your PostgreSQL database\'s username' );
        $passwd = $this->patchData->get( 'db', 'password', 'Type your PostgreSQL database\'s password' );
        $dbname = $this->patchData->get( 'db', 'dbname', 'Type your PostgreSQL database\'s dbname', 'gridguyz' );
        $schema = $this->patchData->get( 'db', 'schema', 'Type your PostgreSQL database\'s schema name', 'site' );

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

        try
        {
            $this->patcher  = new Patcher( $dbConfigData );
            $db             = $this->patcher->getDb();
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

        if ( ! is_file( $dbConfigFile ) )
        {
            @file_put_contents(
                $dbConfigFile,
                sprintf( 'return %s;', var_export(
                    array(
                        'db' => $dbConfigData,
                    ),
                    true
                ) )
            );
        }
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
        parent::install( $repo, $package );

        $this->beforePatches( $package, 0, $package->getVersion() );

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

        $this->afterPatches( $package, 0, $package->getVersion() );
    }

    /**
     * {@inheritDoc}
     */
    public function update( InstalledRepositoryInterface $repo,
                            PackageInterface $initial,
                            PackageInterface $target )
    {
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
        $this->beforePatches( $package, $package->getVersion(), 0 );

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

        $this->afterPatches( $package, $package->getVersion(), 0 );

        parent::uninstall( $repo, $package );
    }

    /**
     * Run before patches
     *
     * @param   \Composer\Package\PackageInterface  $package
     * @param   type                                $from
     * @param   type                                $to
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
     * @param   \Composer\Package\PackageInterface  $package
     * @param   type                                $from
     * @param   type                                $to
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
     * @param   \Composer\Package\PackageInterface $package
     * @param   string  $from
     * @param   string  $to
     * @param   string  $method
     * @throws  Exception\RuntimeException
     */
    private function runPatchMethod( PackageInterface $package, $from, $to, $method )
    {
        $extra = $package->getExtra();

        if ( isset( $extra['patch-classes'] ) )
        {
            $db       = $this->patcher->getDb();
            $basePath = rtrim( $this->getInstallPath( $package ), '/' );

            try
            {
                $db->beginTransaction();

                foreach ( (array) $extra['patch-classes'] as $class => $path )
                {
                    if ( $path )
                    {
                        require_once $basePath . '/' . ltrim( $path, '/' );
                    }

                    if ( ! class_exists( $class ) )
                    {
                        throw new Exception\RuntimeException( sprintf(
                            '%s: class "%s" not found at "%s"',
                            __METHOD__,
                            $class,
                            $path
                        ) );
                    }

                    $patch = new $class( $this->patchData, $db );
                    $patch->$method( $from, $to );
                }

                $db->commit();
            }
            catch ( Exception $exception )
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
    protected function getModulesPaths( PackageInterface $package )
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
            '    Install gridguyz-module: <info>%s</info>/<info>%s</info>',
            $package->getName(),
            basename( $path )
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
            '    Before update gridguyz-module: <info>%s</info>/<info>%s</info>',
            $package->getName(),
            basename( $path )
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
            '    After update gridguyz-module: <info>%s</info>/<info>%s</info>',
            $package->getName(),
            basename( $path )
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
            '    Uninstall gridguyz-module: <info>%s</info>/<info>%s</info>',
            $package->getName(),
            basename( $path )
        ) );

        $this->removePublic( $path, $package );
        $this->patch( $path, $package, 0 );
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
     * @param   string                              $path
     * @param   \Composer\Package\PackageInterface  $package
     * @param   string                              $toVersion
     * @return  void
     */
    protected function patch( $path, PackageInterface $package, $toVersion = null )
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

            $this->patcher->patch( array( $dir ), $toVersion );
        }
    }

}
