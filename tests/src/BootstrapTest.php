<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-databse
 * @copyright Copyright (c) 2018 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-databse/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Tests\Database;

use PHPUnit\Framework\TestCase;

use Psr\Container\ContainerInterface;
use SimpleComplex\Utils\Dependency;
use SimpleComplex\Utils\Bootstrap;

/**
 * @code
 * // CLI, in document root:
 * vendor/bin/phpunit vendor/simplecomplex/database/tests/src/BootstrapTest.php
 * @endcode
 *
 * @package SimpleComplex\Tests\Database
 */
class BootstrapTest extends TestCase
{
    protected static $booted = false;

    /**
     * Only prepares dependencies at first call.
     *
     * @return ContainerInterface
     */
    public function testDependencies()
    {
        // @todo: what about error handlers?

        if (!static::$booted) {
            static::$booted = true;
            Bootstrap::prepareDependenciesIfExist();
        }

        $container = Dependency::container();

        $this->assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }
}
