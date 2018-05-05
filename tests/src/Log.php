<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-databse
 * @copyright Copyright (c) 2018 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-databse/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Tests\Database;

use SimpleComplex\Utils\CliEnvironment;
use SimpleComplex\Utils\Dependency;

/**
 * @package SimpleComplex\Tests\Database
 */
class Log
{
    /**
     * @param \Throwable $xcptn
     *
     * @return void
     */
    public function log(\Throwable $xcptn) /*:void*/
    {
        $msg = null;
        try {
            $container = Dependency::container();
            if ($container->has('inspect')) {
                /** @var \SimpleComplex\Inspect\Inspect $inspect */
                $inspect = $container->get('inspect');
                $msg = '' . $inspect->trace($xcptn, [
                        'wrappers' => 1,
                    ]);
            }
            if ($container->has('logger')) {
                /** @var \Psr\Log\LoggerInterface $logger */
                $logger = $container->get('logger');
                $logger->warning($msg ? $msg : '%exception', [
                    'exception' => $xcptn,
                ]);
            }
        }
        catch (\Throwable $ignore) {
        }
        if (CliEnvironment::cli()) {
            echo "\n" . ($msg ?? $xcptn) . "\n";
        }
    }
}
