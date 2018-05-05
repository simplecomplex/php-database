<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-databse
 * @copyright Copyright (c) 2018 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-databse/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Database\Tests;

use PHPUnit\Framework\TestCase;

use Psr\Container\ContainerInterface;
use SimpleComplex\Utils\Dependency;
use SimpleComplex\Utils\Bootstrap;

/**
 * @package SimpleComplex\Database\Tests
 */
class BootstrapTest extends TestCase
{
    protected $booted = false;

    /**
     * Only prepares dependencies at first call.
     *
     * @return ContainerInterface
     */
    public function testDependencies()
    {
        // @todo: what about error handlers?

        if (!$this->booted) {
            $this->booted = true;
            Bootstrap::prepareDependenciesIfExist();
        }

        $container = Dependency::container();

        $this->assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }
}
