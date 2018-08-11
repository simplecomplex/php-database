<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-database
 * @copyright Copyright (c) 2018 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-database/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Tests\Database\MsSql;

use SimpleComplex\Utils\Explorable;

/**
 * @package SimpleComplex\Tests\Database\MsSql
 */
class Typish extends Explorable
{
    protected $arg0;

    protected $id;

    protected $_0_int;

    protected $_1_float;

    protected $_2_decimal;

    protected $_3_varchar;

    protected $_4_blob;

    protected $_5_date;

    protected $_6_datetime;

    protected $_7_nvarchar;

    protected $_8_bit;

    protected $_9_time;

    protected $_10_uuid;

    /**
     * @param string|null $arg0
     */
    public function __construct(string $arg0 = null)
    {
        $this->explorablesAutoDefine();

        if ($arg0) {
            $this->arg0 = $arg0;
        }
    }

    /**
     * @param string $name
     * @param mixed|null $value
     *
     * @return void
     *
     * @throws \OutOfBoundsException
     *      If no such instance property.
     */
    public function __set(string $name, $value)
    {
        if (!in_array($name, $this->explorableIndex, true)) {
            throw new \OutOfBoundsException(get_class($this) . ' instance exposes no property[' . $name . '].');
        }
        $this->{$name} = $value;
    }

    /**
     * Get a property.
     *
     * @param string $name
     *
     * @return mixed
     *
     * @throws \OutOfBoundsException
     *      If no such instance property.
     */
    public function __get(string $name)
    {
        if (in_array($name, $this->explorableIndex, true)) {
            return $this->{$name};
        }
        throw new \OutOfBoundsException(get_class($this) . ' instance exposes no property[' . $name . '].');
    }
}
