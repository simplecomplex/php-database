<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-databse
 * @copyright Copyright (c) 2018 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-databse/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Tests\Database;

use SimpleComplex\Utils\Dependency;
use SimpleComplex\Utils\Utils;

/**
 * @package SimpleComplex\Tests\Database
 */
class TestHelper
{
    const LOG_LEVEL = 'debug';

    /**
     * @param string|mixed $message
     *      Non-string gets stringified; if possible.
     * @param mixed $subject
     *
     * @return void
     */
    public static function logVariable($message, $subject) /*: void*/
    {
        $msg = '' . $message;
        try {
            $container = Dependency::container();
            if (!$container->has('logger')) {
                return;
            }
            /** @var \Psr\Log\LoggerInterface $logger */
            $logger = $container->get('logger');
            if ($container->has('inspect')) {
                /** @var \SimpleComplex\Inspect\Inspect $inspect */
                $inspect = $container->get('inspect');
                $logger->log(
                    static::LOG_LEVEL,
                    $msg . (!$msg ? '' : "\n") . $inspect->variable(
                        $subject,
                        [
                            'wrappers' => 1,
                        ]
                    )
                );
            }
            else {
                $logger->log(
                    static::LOG_LEVEL,
                    $msg . (!$msg ? '' : "\n") . str_replace("\n", '', var_export($subject, true))
                );
            }
        }
        catch (\Throwable $ignore) {
        }
    }

    /**
     * @param string|mixed $message
     *      Non-string gets stringified; if possible.
     * @param \Throwable|null $xcptn
     *      Null: do backtrace.
     *
     * @return void
     */
    public static function logTrace($message = '', \Throwable $xcptn = null) /*: void*/
    {
        $msg = '' . $message;
        try {
            $container = Dependency::container();
            if (!$container->has('logger')) {
                return;
            }
            /** @var \Psr\Log\LoggerInterface $logger */
            $logger = $container->get('logger');
            if ($container->has('inspect')) {
                /** @var \SimpleComplex\Inspect\Inspect $inspect */
                $inspect = $container->get('inspect');
                $logger->log(
                    static::LOG_LEVEL,
                    $msg . (!$msg ? '' : "\n") . $inspect->trace(
                        $xcptn,
                        [
                            'wrappers' => 1,
                            'trace_limit' => 1,
                        ]
                    )
                );
            }
            elseif ($xcptn) {
                $logger->log(static::LOG_LEVEL, $msg ? $msg : '%exception', [
                    'exception' => $xcptn,
                ]);
            }
        }
        catch (\Throwable $ignore) {
        }
    }

    /**
     * Expected PHP composer vendor dir.
     *
     * @var string
     */
    const DIR_VENDOR = [
        '/vendor',
        '/backend/vendor',
    ];

    /**
     * Expected path to tests' src dir.
     *
     * @var string
     */
    const PATH_TESTS_SRC = TestHelper::DIR_VENDOR . '/simplecomplex/database/tests/src';

    /**
     * @return string
     *
     * @throws \SimpleComplex\Utils\Exception\ConfigurationException
     *      Propagated; from Utils::documentRoot().
     */
    public static function documentRoot() : string
    {
        return Utils::getInstance()->documentRoot();
    }

    /**
     * @param string $relativeToDocumentRoot
     *
     * @throws \RuntimeException
     *      Propagated; from Utils::resolvePath()
     * @throws \LogicException
     *      Propagated.
     *
     * @return bool
     */
    public static function fileExists(string $relativeToDocumentRoot) : bool
    {
        $document_root = static::documentRoot();
        $absolute_path = Utils::getInstance()->resolvePath($relativeToDocumentRoot);
        return file_exists($document_root . $absolute_path);
    }
}
