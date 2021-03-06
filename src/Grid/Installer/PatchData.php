<?php

namespace Grid\Installer;

use LogicException;
use Composer\IO\IOInterface;

/**
 * PatchData
 *
 * @author David Pozsar <david.pozsar@megaweb.hu>
 */
class PatchData
{

    /**
     * @const string
     */
    const PADDING = '      ';

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
     * Has a patch-data already exists?
     *
     * @param   string  $section
     * @param   string  $key
     * @return  string
     */
    public function has( $section, $key )
    {
        return ! empty( $this->data[$section][$key] );
    }

    /**
     * Get a patch-data
     *
     * @param   string          $section
     * @param   string          $key
     * @param   string          $ask
     * @param   string          $default
     * @param   string|callable $validator
     * @param   int|false       $attempts
     * @param   bool            $throwIfEmpty
     * @return  string
     * @throws  Exception\DomainException
     */
    public function get( $section,
                         $key,
                         $ask           = null,
                         $default       = null,
                         $validator     = null,
                         $attempts      = 3,
                         $throwIfEmpty  = true )
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

            $ask    = static::PADDING . $question . ': ';
            $hidden = false;

            if ( true === $validator )
            {
                $hidden     = true;
                $validator  = null;
            }

            if ( is_string( $validator ) && ! function_exists( $validator ) )
            {
                $pattern   = (string) $validator;
                $validator = function ( $value ) use ( $pattern )
                {
                    $matches = array();

                    if ( ! preg_match( $pattern, $value, $matches ) )
                    {
                        throw new LogicException( sprintf(
                            '"%s" does not match "%s"',
                            $value,
                            $pattern
                        ) );
                    }

                    return $matches[0];
                };
            }

            if ( is_array( $validator ) && (
                    count( $validator ) != 2 || ! function_exists( $validator )
                ) )
            {
                $values    = (array) $validator;
                $validator = function ( $value ) use ( $values )
                {
                    if ( ! in_array( $value, $values ) )
                    {
                        throw new LogicException( sprintf(
                            '"%s" is not available, only "%s" accepted',
                            $value,
                            implode( '", "', $values )
                        ) );
                    }

                    return $value;
                };
            }

            if ( is_callable( $validator ) )
            {
                $result = $this->io->askAndValidate( $ask, $validator, $attempts, $default );
            }
            else if ( $hidden )
            {
                $result = $this->io->askAndHideAnswer( $ask ) ?: $default;
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

    /**
     * Print choices
     *
     * @param   string  $label
     * @param   array   $choices
     * @return  void
     */
    public function printChoices( $label, array $choices )
    {
        if ( $this->io->isInteractive() && ! empty( $choices ) )
        {
            $this->io->write( static::PADDING . $label );

            foreach ( $choices as $choice => $description )
            {
                $this->io->write( static::PADDING . " * <info>$choice</info>: $description" );
            }
        }
    }

}
