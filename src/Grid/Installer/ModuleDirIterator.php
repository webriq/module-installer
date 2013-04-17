<?php

namespace Grid\Installer;

use FilterIterator;
use FilesystemIterator;

/**
 * PublicDirIterator
 *
 * @author David Pozsar <david.pozsar@megaweb.hu>
 */
class ModuleDirIterator extends FilterIterator
{

    /**
     * Constructor
     *
     * @param   string  $path
     */
    public function __construct( $path )
    {
        parent::__construct( new FilesystemIterator(
            $path,
            FilesystemIterator::CURRENT_AS_PATHNAME |
            FilesystemIterator::KEY_AS_FILENAME |
            FilesystemIterator::UNIX_PATHS
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
        return $this->isDir() && '.' !== $this->key()[0];
    }

}
