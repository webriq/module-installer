<?php

namespace Grid\Installer;

use Composer\IO\IOInterface;

/**
 * PatchData
 *
 * @author David Pozsar <david.pozsar@megaweb.hu>
 */
class PatchData
{

    /**
     * IO
     *
     * @var \Composer\IO\IOInterface
     */
    protected $io;

    /**
     * Patch data
     *
     * @var array
     */
    protected $data = array();

    /**
     * Get IO
     *
     * @return \Composer\IO\IOInterface
     */
    public function getIo()
    {
        return $this->io;
    }

    /**
     * Get patch data
     *
     * @return array
     */
    public function getData()
    {
        return $this->data();
    }

    /**
     * Set data
     *
     * @param   array|mixed $data
     * @return  \Grid\Installer\PatchData
     */
    public function setData( $data )
    {
        $this->data = (array) $data;
        return $this;
    }

    /**
     * Add data
     *
     * @param   array|mixed $data
     * @return  \Grid\Installer\PatchData
     */
    public function addData( $data )
    {
        $this->data = array_merge( $this->data, (array) $data );
        return $this;
    }

    /**
     * Constructor
     *
     * @param   \Composer\IO\IOInterface $io
     * @param   array|mixed $data
     */
    public function __construct( IOInterface $io, $data = null )
    {
        $this->io = $io;

        if ( ! empty( $data ) )
        {
            $this->setData( $data );
        }
    }

    /**
     * Get a patch-data
     *
     * @param   string          $section
     * @param   string          $key
     * @param   string          $ask
     * @param   string          $default
     * @param   string|callable $validator
     * @param   bool            $throwIfEmpty
     * @return  string
     * @throws  Exception\DomainException
     */
    public function get( $section, $key, $ask = null, $default = null, $validator = null, $throwIfEmpty = true )
    {
        if ( ! empty( $this->data[$section][$key] ) )
        {
            return $this->data[$section][$key];
        }

        if ( ! $this->io->isInteractive() || empty( $ask ) )
        {
            $result = $default;
        }
        else
        {
            $question = (string) $ask;

            if ( null !== $default )
            {
                $question .= ' (default: <info>' . $default . '</info>)';
            }

            $ask = '      ' . $question . ': ';

            if ( is_string( $validator ) && ! function_exists( $validator ) )
            {
                $pattern   = (string) $validator;
                $validator = function ( $value ) use ( $pattern ) {
                    return (bool) preg_match( $pattern, $value );
                };
            }

            if ( is_callable( $validator ) )
            {
                $result = $this->io->askAndValidate( $ask, $validator, 3, $default );
            }
            else
            {
                $result = $this->io->ask( $ask, $default );
            }
        }

        if ( empty( $result ) )
        {
            if ( $throwIfEmpty )
            {
                throw new Exception\DomainException( sprintf(
                    '%s: patch-data "%s": "%s", asked as "%s" should not be empty',
                    __METHOD__,
                    $section,
                    $key,
                    $ask
                ) );
            }
        }
        else
        {
            $this->data[$section][$key] = $result;
        }

        return $result;
    }

}
