<?php

namespace Grid\Installer;

use Composer\Package\PackageInterface;
use Composer\Installer\LibraryInstaller;

/**
 * ModuleInstaller
 *
 * @author David Pozsar <david.pozsar@megaweb.hu>
 */
class ModuleInstaller extends LibraryInstaller
{

    /**
     * {@inheritDoc}
     */
    public function supports($packageType)
    {
        return in_array( $packageType, array( 'gridguyz-module',
                                              'gridguyz-modules' ) );
    }

}
