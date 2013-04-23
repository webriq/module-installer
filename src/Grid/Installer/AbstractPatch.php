<?php

namespace Grid\Installer;

use PDO;

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
     * Db
     *
     * @var PDO
     */
    protected $db;

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
     * Get db
     *
     * @return  PDO
     */
    public function getDb()
    {
        return $this->db;
    }

    /**
     * Constructor
     *
     * @param   \Grid\Installer\PatchData   $patchData
     */
    public function __construct( PatchData $patchData, PDO $db )
    {
        $this->patchData = $patchData;
        $this->db        = $db;
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
