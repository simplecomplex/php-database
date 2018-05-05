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
use SimpleComplex\Database\DatabaseClient;

/**
 * @package SimpleComplex\Database\Tests
 */
class ConfigurationTest extends TestCase
{
    /**
     * Fallback database infos.
     *
     * @var array[]
     */
    const DATABASE_INFO = [
        'mariadb' => [
            'host' => 'localhost',
            // Zero: use default port.
            //'port' => 0,
            'database' => 'test_scx_mariadb',
            'user' => 'test_scx_mariadb',
            'pass' => '1234',
        ],
        'mssql' => [
            'host' => 'localhost',
            // Zero: use default port.
            //'port' => 0,
            'database' => 'test_scx_mssql',
            'user' => 'test_scx_mssql',
            'pass' => '1234',
            'options' => [
                'tls_trust_self_signed' => true,
            ],
        ],
    ];

    /**
     * @param ContainerInterface $container
     *
     * @return array|null
     */
    protected function getConfigIfExist(ContainerInterface $container)
    {
        $database_info = null;
        if ($container->has('config')) {
            /** @var \SimpleComplex\Config\IniSectionedConfig $config_store */
            $config_store = $container->get('config');
            $config_class = '\\SimpleComplex\\Config\\IniSectionedConfig\\SectionedConfigInterface';
            if (is_a($config_store, $config_class)) {
                $database_info = $config_store->get('database-info.test_scx_mssql', '*', []);
                if (!is_array($database_info) || !$database_info) {
                    $database_info = null;
                }
            }
        }
        return $database_info;
    }


    /**
     * @see BootstrapTest::testDependencies
     *
     * @return array
     */
    public function testMariaDb()
    {
        $container = (new BootstrapTest())->testDependencies();

        $database_info = $this->getConfigIfExist($container);
        if (!$database_info) {
            $database_info = static::DATABASE_INFO['mariadb'];
        }

        $this->assertInternalType('array', $database_info);
        $this->assertNotEmpty($database_info);
        if (is_array($database_info) && $database_info) {
            $requireds = DatabaseClient::DATABASE_INFO_REQUIRED;
            foreach ($requireds as $key) {
                $this->assertArrayHasKey($key, $database_info);
                $this->assertNotEmpty($database_info[$key]);
            }
        }

        (new Log())->log(new \Exception(__FUNCTION__));

        return $database_info;
    }

    /**
     * @see BootstrapTest::testDependencies
     *
     * @return array
     */
    public function testMsSql()
    {
        $container = (new BootstrapTest())->testDependencies();

        $database_info = $this->getConfigIfExist($container);
        if (!$database_info) {
            $database_info = static::DATABASE_INFO['mssql'];
        }

        $this->assertInternalType('array', $database_info);
        $this->assertNotEmpty($database_info);
        if (is_array($database_info) && $database_info) {
            $requireds = DatabaseClient::DATABASE_INFO_REQUIRED;
            foreach ($requireds as $key) {
                $this->assertArrayHasKey($key, $database_info);
                $this->assertNotEmpty($database_info[$key]);
            }
        }

        return $database_info;
    }
}
