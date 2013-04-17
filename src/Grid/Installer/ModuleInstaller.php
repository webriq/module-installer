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
    public function __construct( IOInterface $io, Composer $composer, $type = self::TYPE_MODULE )
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
    public function install( InstalledRepositoryInterface $repo, PackageInterface $package )
    {
        parent::install( $repo, $package );
        $this->installPublic( $package );
    }

    /**
     * {@inheritDoc}
     */
    public function update( InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target )
    {
        if ( $repo->hasPackage( $initial ) )
        {
            $this->removePublic( $initial );
        }

        parent::update( $repo, $initial, $target );
        $this->installPublic( $target );
    }

    /**
     * {@inheritDoc}
     */
    public function uninstall( InstalledRepositoryInterface $repo, PackageInterface $package )
    {
        if ( $repo->hasPackage( $package ) )
        {
            $this->removePublic( $package );
        }

        parent::uninstall( $repo, $package );
    }

    /**
     * Install files to public-dir
     *
     * @param   PackageInterface    $package
     * @return  void
     */
    protected function installPublic( PackageInterface $package )
    {
        $extra  = (array) $package->getExtra();
        $public = isset( $extra['public-dir'] )
                ? trim( $extra['public-dir'], '/' )
                : static::DEFAULT_PUBLIC_DIR;

        $dir = $this->getInstallPath( $package ) . '/' . $public;

        if ( ! is_dir( $dir ) || ! is_readable( $dir ) )
        {
            return;
        }

        foreach ( PublicDirIterator::flattern( $dir, true ) as $entry )
        {
            $sub = ltrim( $entry->getSubPathname(), '/' );

            if ( $entry->isDir() )
            {
                mkdir( $this->publicDir . '/' . $sub, 0777, true );
            }
            else if ( $entry->isFile() )
            {
                copy( $entry->getPathname(), $this->publicDir . '/' . $sub );
            }
        }
    }

    /**
     * Remove files from public-dir
     *
     * @param   PackageInterface    $package
     * @return  void
     */
    protected function removePublic( PackageInterface $package )
    {
        $extra  = (array) $package->getExtra();
        $public = isset( $extra['public-dir'] )
                ? trim( $extra['public-dir'], '/' )
                : static::DEFAULT_PUBLIC_DIR;

        $dir = $this->getInstallPath( $package ) . '/' . $public;

        if ( ! is_dir( $dir ) || ! is_readable( $dir ) )
        {
            return;
        }

        foreach ( PublicDirIterator::flattern( $dir, false ) as $entry )
        {
            $sub = ltrim( $entry->getSubPathname(), '/' );

            if ( $entry->isDir() )
            {
                rmdir( $this->publicDir . '/' . $sub );
            }
            else if ( $entry->isFile() )
            {
                unlink( $this->publicDir . '/' . $sub );
            }
        }
    }

}
