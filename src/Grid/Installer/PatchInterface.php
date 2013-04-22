<?php

namespace Grid\Installer;

/**
 * PatchInterface
 *
 * @author David Pozsar <david.pozsar@megaweb.hu>
 */
interface PatchInterface
{

    /**
     * Constructor
     *
     * @param   \Grid\Installer\PatchData   $patchData
     */
    public function __construct( PatchData $patchData );

    /**
     * Run before patching
     *
     * @param   string  $from
     * @param   string  $to
     * @return  void
     */
    public function beforePatch( $from, $to );

    /**
     * Run after patching
     *
     * @param   string  $from
     * @param   string  $to
     * @return  void
     */
    public function afterPatch( $from, $to );

}
