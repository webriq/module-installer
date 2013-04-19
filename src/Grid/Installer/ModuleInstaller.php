<?php

namespace Grid\Installer;

use RuntimeException;
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

        if ( ! is_dir( $this->publicDir ) )
        {
            throw new RuntimeException( sprintf(
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
                throw new RuntimeException( sprintf(
                    '%s: Directory "%s" under public directory "%s"' .
                    ' does not exists, or not writable',
                    __METHOD__,
                    $subDir,
                    $this->publicDir
                ) );
            }
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
    }

    /**
     * {@inheritDoc}
     */
    public function update( InstalledRepositoryInterface $repo,
                            PackageInterface $initial,
                            PackageInterface $target )
    {
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
    }

    /**
     * {@inheritDoc}
     */
    public function uninstall( InstalledRepositoryInterface $repo,
                               PackageInterface $package )
    {
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

        parent::uninstall( $repo, $package );
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

}
