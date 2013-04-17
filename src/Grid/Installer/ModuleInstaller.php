<?php

namespace Grid\Installer;

use RuntimeException;
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
        $extra = (array) $composer->getConfig()->get( 'extra' );

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

        foreach ( $this->getModulesPaths( $package ) as $path )
        {
            $this->installModule( $path, $package );
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
            foreach ( $this->getModulesPaths( $initial ) as $path )
            {
                $this->beforeUpdateModule( $path, $initial );
            }
        }

        parent::update( $repo, $initial, $target );

        foreach ( $this->getModulesPaths( $target ) as $path )
        {
            $this->beforeUpdateModule( $path, $target );
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
            foreach ( $this->getModulesPaths( $package ) as $path )
            {
                $this->uninstallModule( $path, $package );
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
                $extra  = (array) $package->getExtra();
                $module = isset( $extra['module-dir'] )
                        ? trim( $extra['module-dir'], '/' )
                        : 'module';
                return new ModuleDirIterator( $path . '/' . $module );

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
        $extra  = (array) $package->getExtra();
        $public = isset( $extra['public-dir'] )
                ? trim( $extra['public-dir'], '/' )
                : static::DEFAULT_PUBLIC_DIR;

        $this->copyPublic( $path . '/' . $public );
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
        $extra  = (array) $package->getExtra();
        $public = isset( $extra['public-dir'] )
                ? trim( $extra['public-dir'], '/' )
                : static::DEFAULT_PUBLIC_DIR;

        $this->removePublic( $path . '/' . $public );
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
        $extra  = (array) $package->getExtra();
        $public = isset( $extra['public-dir'] )
                ? trim( $extra['public-dir'], '/' )
                : static::DEFAULT_PUBLIC_DIR;

        $this->copyPublic( $path . '/' . $public );
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
        $extra  = (array) $package->getExtra();
        $public = isset( $extra['public-dir'] )
                ? trim( $extra['public-dir'], '/' )
                : static::DEFAULT_PUBLIC_DIR;

        $this->removePublic( $path . '/' . $public );
    }

    /**
     * Copy files to public-dir
     *
     * @param   string  $path
     * @return  void
     */
    protected function copyPublic( $path )
    {
        foreach ( static::$subDirs as $sub )
        {
            $dir = $path . '/' . $sub;

            if ( ! is_dir( $dir ) || ! is_readable( $dir ) )
            {
                continue;
            }

            foreach ( PublicDirIterator::flattern( $dir, true ) as $entry )
            {
                $dest = $this->publicDir
                      . '/' . $sub
                      . '/' . ltrim( $entry->getSubPathname(), '/' );

                if ( $entry->isDir() )
                {
                    mkdir( $dest, 0777, true );
                }
                else if ( $entry->isFile() )
                {
                    copy( $entry->getPathname(), $dest );
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
    protected function removePublic( $path )
    {
        foreach ( static::$subDirs as $sub )
        {
            $dir = $path . '/' . $sub;

            if ( ! is_dir( $dir ) || ! is_readable( $dir ) )
            {
                continue;
            }

            foreach ( PublicDirIterator::flattern( $dir, true ) as $entry )
            {
                $dest = $this->publicDir
                      . '/' . $sub
                      . '/' . ltrim( $entry->getSubPathname(), '/' );

                if ( $entry->isDir() )
                {
                    rmdir( $dest );
                }
                else if ( $entry->isFile() )
                {
                    unlink( $dest );
                }
            }
        }
    }

}
