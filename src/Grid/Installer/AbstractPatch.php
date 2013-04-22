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
