<?php

namespace Grid\Installer;

use RecursiveFilterIterator;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

/**
 * PublicDirIterator
 *
 * @author David Pozsar <david.pozsar@megaweb.hu>
 */
class PublicDirIterator extends RecursiveFilterIterator
{

    /**
     * Constructor
     *
     * @param   string  $path
     */
    public function __construct( $path )
    {
        parent::__construct( new RecursiveDirectoryIterator(
            $path,
            RecursiveDirectoryIterator::CURRENT_AS_SELF |
            RecursiveDirectoryIterator::KEY_AS_FILENAME |
            RecursiveDirectoryIterator::UNIX_PATHS
        ) );
    }

    /**
     * Check whether the current element of the iterator is acceptable
     * @link http://php.net/manual/en/filteriterator.accept.php
     *
     * @return  bool    <b>TRUE</b> if the current element is acceptable,
     *                  otherwise <b>FALSE</b>.
     */
    public function accept()
    {
        return '.' !== $this->key()[0];
    }

    /**
     * Flattern public-dir
     *
     * @param   string|PublicDirIterator    $iterator
     * @param   bool                        $selfFirst
     * @return  RecursiveIteratorIterator
     */
    public static function flattern( $iterator, $selfFirst )
    {
        if ( ! $iterator instanceof self )
        {
            $iterator = new static( $iterator );
        }

        return new RecursiveIteratorIterator(
            $iterator,
            $selfFirst
                ? RecursiveIteratorIterator::SELF_FIRST
                : RecursiveIteratorIterator::CHILD_FIRST
        );
    }

}
