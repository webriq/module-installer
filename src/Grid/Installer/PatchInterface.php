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
     * @param   ModuleInstaller $installer
     */
    public function __construct( /* ModuleInstaller */ $installer );

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
