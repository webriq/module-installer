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
     * Patch data
     *
     * @var \Grid\Installer\PatchData
     */
    protected $pathcData;

    /**
     * Get patch data
     *
     * @return  \Grid\Installer\PatchData
     */
    public function getPatchData()
    {
        return $this->patchData;
    }

    /**
     * Constructor
     *
     * @param   \Grid\Installer\PatchData   $patchData
     */
    public function __construct( PatchData $patchData )
    {
        $this->patchData = $patchData;
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

}
