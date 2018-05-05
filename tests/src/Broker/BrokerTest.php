<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-databse
 * @copyright Copyright (c) 2018 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-databse/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Tests\Database\Broker;

use PHPUnit\Framework\TestCase;

use Psr\Container\ContainerInterface;
use SimpleComplex\Database\DatabaseBroker;

use SimpleComplex\Tests\Database\BootstrapTest;

/**
 * @package SimpleComplex\Tests\Database
 */
class BrokerTest extends TestCase
{
    /**
     * @depends BootstrapTest::boot
     *
     * @param ContainerInterface $container
     *
     * @return DatabaseBroker
     */
    public function testInstantiation(ContainerInterface $container)
    {
        /** @var \SimpleComplex\Database\DatabaseBroker $db_broker */
        $db_broker = $container->get('database-broker');

        $this->assertObjectHasAttribute('CLASS_CLIENT_MARIADB', $db_broker);

        return $db_broker;
    }
}
