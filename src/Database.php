<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-databse
 * @copyright Copyright (c) 2017 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-databse/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Database;

/**
 * Database abstraction decoupling Maria DB, MS SQL interaction.
 *
 * @package SimpleComplex\Database
 */
class Database
{
    /**
     * Class name of \SimpleComplex\Database\MariaDbQuery or extending class.
     *
     * @code
     * // Overriding class must use fully qualified (namespaced) class name.
     * const CLASS_CLIENT_MARIADB = \Package\Library\CustomMariaDbQuery::class;
     * @endcode
     *
     * @see \SimpleComplex\Database\MariaDbClient
     *
     * @var string
     */
    const CLASS_CLIENT_MARIADB = MariaDbClient::class;

    /**
     * Class name of \SimpleComplex\Database\MsSqlClient or extending class.
     *
     * @code
     * // Overriding class must use fully qualified (namespaced) class name.
     * const CLASS_CLIENT_MARIADB = \Package\Library\CustomMsSqlClient::class;
     * @endcode
     *
     * @see \SimpleComplex\Database\MsSqlClient
     *
     * @var string
     */
    const CLASS_CLIENT_MSSQL = MsSqlClient::class;

    /**
     * Default connect timeout in seconds.
     *
     * @var int
     */
    const CONNECT_TIMEOUT = 5;

    /**
     * Default query timeout in seconds.
     *
     * @var int
     */
    const QUERY_TIMEOUT = 20;

    /**
     * Default (minimum) surplus PHP execution time to leave for script execution
     * after reception of response; in seconds.
     *
     * @var int
     *
    const SURPLUS_EXECTIME_DEFAULT = 5:*/

    /**
     * Default when no 'log_severity' option.
     *
     * @var int
     */
    const LOG_SEVERITY = LOG_WARNING;

    /**
     * @var int
     */
    const ERROR_CODE_OFFSET = 1500;

    /**
     * Actual numeric values may be affected by non-zero ERROR_CODE_OFFSET
     * of classes extending Client.
     *
     * @see Client::ERROR_CODE_OFFSET
     *
     * @var array
     */
    const ERROR_CODES = [
        'unknown' => 1,
        'server_arg_empty' => 31,
        'protocol_not_supported' => 32,
        'method_not_supported' => 35,
        'option_not_supported' => 36,
        'option_value_missing' => 37,
        'option_value_empty' => 38,
        'option_value_invalid' => 39,
        'argument_type_invalid' => 40,
        'init_connection' => 41,
        'request_options' => 42,
        'parser_not_callable' => 49,
        'response_false' => 51,

        // cURL equivalents.
        'url_malformed' => 52,
        'host_not_found' => 53,
        'connection_failed' => 54,
        'request_timed_out' => 55,
        'too_many_redirects' => 56,

        // cURL equivalents.
        'ssl_client_certificate' => 61,
        'ssl_bad_cipher' => 62,
        'ssl_self_signed' => 63,
        'ssl_cacertfile_notpem' => 64,
        'ssl_cacertfile_missing' => 65,
        'ssl_cacertfile_empty' => 66,
        'ssl_cacertfile_bad' => 67,

        'content_type_mismatch' => 71,
        'response_parse' => 72,
        'keypath_not_found' => 75,
        'response_error' => 81,
    ];

    /**
     * Options:
     * - (obj) logger: PSR-3 logger; otherwise checks in Utils\Dependency.
     * - (bool) parse_json_assoc: parse objects to associative arrays;
     *      ignored if using the parser() method
     * - (str) accept
     * - (str) accept_charset
     * - (str) content_type: of request body; default application/json; supported:
     *      ''|application/json[; charset=some-charset]|application/x-www-form-urlencoded
     * - (int) connect_timeout: default 5 (seconds);
     *      class constant CONNECT_TIMEOUT_DEFAULT
     * - (int) request_timeout: default 20 (seconds);
     *      class constant REQUEST_TIMEOUT_DEFAULT
     * - (bool) ssl_verify: default true;
     *      class constant SSL_VERIFY_DEFAULT
     * - (str) ssl_cacert_file: use custom CA cert file instead the common file
     * - (bool) status_vain_result_void (~ result() returns empty string if
     *      status >=300; suppress error messages etc. received in response body)
     * - (bool) ignore_status: ~ don't trust response status code;
     *      like 200 might actually be 404)
     * - (bool) ignore_content_type: ~ don't trust response content type;
     *      HTML might actually be JSON, and vice versa
     * - (str) auth: 'basic' or 'ntlm'; defaults to 'basic' if option _user_
     * - (str) user: for auth [username]:[password]
     * - (str) pass: for auth [username]:[password]
     * - (arr) headers: request headers
     * - (bool) get_headers: get response headers
     * - (int) log_severity: severity level when logging any error type except
     *      logical error and (runtime) configuration error; default warning;
     *      class constant LOG_SEVERITY_DEFAULT
     * - (string) log_type: use that log type when logging; default 'restmini_client';
     *      class constant LOG_TYPE_DEFAULT
     * - (bool) service_response_info_wrapper: tell service to wrap response
     *      in object listing service response properties
     * - (bool) record_args: make path+query+body args available after request
     * 
     * @see Client::alterOptions()
     *
     * @var array
     */
    const OPTIONS_SUPPORTED = [
        'logger',
        'parse_json_assoc',
        'accept',
        'accept_charset',
        'content_type',
        'connect_timeout',
        'request_timeout',
        'ssl_verify',
        'ssl_cacert_file',
        'status_vain_result_void',
        'ignore_status',
        'ignore_content_type',
        'auth',
        'user',
        'pass',
        // Array; key => value, not 'key: value'.
        'headers',
        'get_headers',
        'log_severity',
        'log_type',
        'correlation_id_header',
        'service_response_info_wrapper',
        'record_args',
    ];

    /**
     * Record of last time (if any) a client postponed PHP execution timeout.
     *
     * Has to be static; tampering with PHP configuration affects the whole
     * request.
     *
     * @var int
     */
    protected static $tExecTimeoutPostponed = 0;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * If error, buckets are:
     * - (int) code
     * - (str) name
     * - (str) message
     *
     * @var array
     */
    protected $error = [];

    /**
     * @var string
     */
    protected $server = '';

    /**
     * @var string
     */
    protected $endpoint = '';

    /**
     * @var array
     */
    protected $options = [];

    /**
     * Timestamp of request start.
     *
     * @var int
     */
    protected $started = 0;

    /**
     * Request duration, in seconds.
     *
     * @var int
     */
    protected $duration = 0;

    /**
     * @var int
     */
    protected $status = 0;

    /**
     * @see Client::make()
     * @see Client::alterOptions()
     *
     * @param string $server
     *      Protocol + domain (~ http://ser.ver).
     *      Prepends http:// if no protocol (only http and https supported).
     *      Trailing slash will be removed.
     * @param string $endpoint
     *      Examples: 'path', '/base/route/end-point', '/endpoint.php',
     *      '/dir/endpoint.aspx?arg=val'.
     *      Leading slash is optional; will be prepended if missing.
     * @param array $options
     *      Supported: see Client::alterOptions().
     */
    public function __construct(string $server, string $endpoint = '', array $options = [])
    {
        if (!$server) {
            $this->error = [
                'code' => static::errorCode('server_arg_empty'),
                'name' => 'server_arg_empty',
                'message' => $em = 'Client constructor arg server is not non-empty string.',
            ];
            $this->log(
                LOG_ERR,
                $em,
                $server
            );
            return;
        }

        // Check if SSL.
        if (strpos($server, 'https://') === 0) {
            $this->ssl = true;
        }
        // Prepend default protocol, if none.
        elseif (strpos($server, 'http://') === false) {
            if (strpos($server, ':/') !== false) {
                $this->error = [
                    'code' => static::errorCode('protocol_not_supported'),
                    'name' => 'protocol_not_supported',
                    'message' => $em = 'Client protocol suggested by constructor arg server is not supported.',
                ];
                $this->log(
                    LOG_ERR,
                    $em,
                    $server
                );
                return;
            }
            $server = 'http://' . $server;
        }

        // Remove trailing slash.
        $this->server = rtrim($server, '/');

        // Endpoint may be anything from '/restmini_endpoint'
        // to 'dir/non_restmini_endpoint.aspx?arg=val'.
        $this->endpoint = '/' . ltrim($endpoint, '/');

        // Resolve options.
        $this->alterOptions($options);
    }

    /**
     * Convenience factory which facilitates chaining.
     *
     * @code
     * use SimpleComplex\RestMini\Client;
     *
     * // Get JSON-decoded response data.
     * $data = Client::make('http://server', '/endpoint')->get()->result();
     *
     * // Check status first.
     * $response = Client::make('http://server', '/endpoint')->get();
     * if ($response->status() == 200) {
     *   $data = $response->result();
     * }
     * else {
     *   $info = $response->info();
     *   $container = \SimpleComplex\Utils\Dependency::container();
     *   $container->get('logger')->warning("Darned:\n" . json_encode($info, JSON_PRETTY_PRINT));
     * }
     *
     * // Get raw response data.
     * $raw = Client::make('http://server', '/endpoint')->get()->raw():
     * @endcode
     *
     * @see Client::alterOptions()
     *
     * @param string $server
     *      Protocol + domain (~ http://ser.ver).
     *      Prepends http:// if no protocol (only http and https supported).
     *      Trailing slash will be removed.
     * @param string $endpoint
     *      Examples: 'path', '/base/route/end-point', '/endpoint.php',
     *      '/dir/endpoint.aspx?arg=val'.
     *      Leading slash is optional; will be prepended if missing.
     * @param array $options
     *
     * @return Client|static
     *      Client or extending type.
     */
    public static function make(string $server, string $endpoint = '', array $options = []) : Client
    {
        return new static($server, $endpoint, $options);
    }

    /**
     * May also be passed via options, or set in dependency injection container.
     *
     * @param LoggerInterface $logger
     *
     * @return $this|Client
     */
    public function setLogger(LoggerInterface $logger) : Client
    {
        $this->logger = $logger;
        return $this;
    }

    /**
     * Set and/or remove options.
     *
     * Aborts, and sets client in error state, if a key of the $set arg
     * isn't supported.
     *
     * Chainable, returns self.
     *
     * If removing connect_timeout or request_timeout: they will be set again
     * to module defaults.
     *
     * @code
     * $client = new Client('http://ser.ver', '/end-point');
     * // First request, get index.
     * $list = $client->get();
     * // ...
     * // Second request, update a record - but first change some options.
     * $client->alterOptions(
     *   // Set.
     *   [
     *     'get_headers' => true,
     *   ],
     *   // Remove.
     *   [
     *     'connect_timeout',
     *     'request_timeout',
     *   ],
     * )->put(
     *   [21],
     *   null,
     *   ['title' => 'Changed record']
     * );
     * @endcode
     *
     * @param array $set
     * @param array $unset
     *
     * @return $this|Client
     *
     * @throws \TypeError
     *      Propagated. From setLogger().
     */
    public function alterOptions(array $set = [], array $unset = []) : Client
    {
        $options =& $this->options;

        if ($set) {
            $supported = static::OPTIONS_SUPPORTED;
            foreach ($set as $key => $val) {
                if (in_array($key, $supported)) {
                    $options[$key] = $val;
                    switch ($key) {
                        case 'accept':
                            $this->accept = $val;
                            break;
                        case 'accept_charset':
                        case 'acceptCharset':
                            $this->acceptCharset = $val;
                            break;
                    }
                }
                else {
                    $this->error = [
                        'code' => static::errorCode('option_not_supported'),
                        'name' => 'option_not_supported',
                        'message' => $em = 'Client option['
                            . Sanitize::getInstance()->plainText($key) . '] not supported.',
                    ];
                    $this->log(
                        LOG_ERR,
                        $em,
                        $set
                    );

                    return $this;
                }
            }
        }
        if ($unset) {
            foreach ($unset as $val) {
                unset($options[$val]);
            }
        }

        if (!empty($options['logger'])) {
            $this->setLogger($options['logger']);
        }

        if (
            isset($options['parse_json_assoc'])
            && $this->parser['object'] == 'this' && $this->parser['method'] == 'parseJson'
        ) {
            $this->parser['options'] = !!$options['parse_json_assoc'];
        }

        // Get (deprecated) accept and accept charset set in headers.
        if (!empty($options['headers'])) {
            $deprecated = [];
            $opts_raw = null;
            if (!empty($options['headers']['Accept'])) {
                $this->accept = $options['headers']['Accept'];
                $opts_raw = $options;
                unset($options['headers']['Accept']);
                $options['accept'] = $this->accept;
                $deprecated[] = 'headers.Accept - accept';
            }
            if (!empty($options['headers']['Accept-Charset'])) {
                $this->acceptCharset = $options['headers']['Accept-Charset'];
                if (!$opts_raw) {
                    $opts_raw = $options;
                }
                unset($options['headers']['Accept-Charset']);
                $options['accept_charset'] = $this->acceptCharset;
                $deprecated[] = 'headers.Accept-Charset - accept_charset';
            }
            if ($deprecated) {
                $this->log(
                    LOG_NOTICE,
                    'Deprecated headers option(s), use root option(s) instead; ' . join(', ', $deprecated),
                    [
                        'options seen' => $opts_raw,
                        'options fixed' => $options,
                    ]
                );
                unset($deprecated, $opts_raw);
            }
        }

        // Secure valid request body content type, or empty.
        // Request body content type is only required if POST|PUT, so we don't
        // require it to be set at all.
        if (
            !empty($options['content_type'])
            && $options['content_type'] != 'application/x-www-form-urlencoded'
            && strpos($options['content_type'], 'application/json') !== 0
        ) {
            $this->error = [
                'code' => static::errorCode('option_value_invalid'),
                'name' => 'option_value_invalid',
                'message' => $em = 'Client option \'content_type\' value invalid,'
                    . ' must be empty or application/x-www-form-urlencoded or start with application/json',
            ];
            $this->log(
                LOG_ERR,
                $em,
                [
                    'options' => $options,
                    'set' => $set,
                    'unset' => $unset,
                ]
            );
        }

        // Secure timeout options.
        if (!$options || !array_key_exists('connect_timeout', $options)) {
            $options['connect_timeout'] = static::CONNECT_TIMEOUT_DEFAULT;
        }
        if (!$options || !array_key_exists('request_timeout', $options)) {
            $options['request_timeout'] = static::REQUEST_TIMEOUT_DEFAULT;
        }
        // cUrl request timeout includes connection timeout, so effectively
        // request timeout cannot be less than connect timeout.
        if ($options['request_timeout'] <= $options['connect_timeout']) {
            $options['request_timeout'] = $options['connect_timeout'] + 1;
        }

        // Resolve SSL issues.
        if ($this->ssl) {
            // Set 'ssl_verify' option, if not set.
            if (!array_key_exists('ssl_verify', $options)) {
                // Turned off by variable setting? Otherwise use current class default.
                $options['ssl_verify'] = static::SSL_VERIFY_DEFAULT;
            }
            // Secure CA certs file.
            if ($options['ssl_verify']) {
                // Use default SSL CA certs bundle file, unless custom specified.
                if (empty($options['ssl_cacert_file'])) {
                    $options['ssl_cacert_file'] = 'cacert.pem';
                }
            }
        }

        // user:pass.
        if (!empty($options['user'])) {
            if (empty($options['pass'])) {
                $em = 'Client option \'user\' set and non-empty, but option \'pass\' ';
                if (!array_key_exists('pass', $options)) {
                    $this->error = [
                        'code' => static::errorCode('option_value_missing'),
                        'name' => 'option_value_missing',
                        'message' => $em .= 'not set.',
                    ];
                }
                else {
                    $this->error = [
                        'code' => static::errorCode('option_value_empty'),
                        'name' => 'option_value_empty',
                        'message' => $em .= 'empty.',
                    ];
                }
                $this->log(
                    LOG_ERR,
                    $em,
                    [
                        'options' => $options,
                        'set' => $set,
                        'unset' => $unset,
                    ]
                );

                return $this;
            }
            if (empty($options['auth'])) {
                $options['auth'] = 'basic';
            }
            else {
                switch ('' . $options['auth']) {
                    case 'basic':
                    case 'ntlm':
                        break;
                    default:
                        $this->error = [
                            'code' => static::errorCode('option_value_invalid'),
                            'name' => 'option_value_invalid',
                            'message' => $em = 'Client option \'auth\' value invalid',
                        ];
                        $this->log(
                            LOG_ERR,
                            $em,
                            [
                                'options' => $options,
                                'set' => $set,
                                'unset' => $unset,
                            ]
                        );
                }
            }
        }

        // log_severity must be int; RFC-5424.
        if (isset($options['log_severity'])) {
            if (
                !ctype_digit($options['log_severity'])
                || $options['log_severity'] < LOG_EMERG
                || $options['log_severity'] > LOG_DEBUG
            ) {
                $this->error = [
                    'code' => static::errorCode('option_value_invalid'),
                    'name' => 'option_value_invalid',
                    'message' => $em = 'Client option \'log_severity\' value must be an integer 7 thru 0',
                ];
                $this->log(
                    LOG_ERR,
                    $em,
                    [
                        'options' => $options,
                        'set' => $set,
                        'unset' => $unset,
                    ]
                );
                // Use class constant default.
                unset($options['log_severity']);
            }
        }

        return $this;
    }

    /**
     * Set response parser.
     *
     * Chainable, returns self.
     *
     * @param object|string $object
     *      String 'this' resolves to client instance self.
     * @param string $method
     * @param mixed $options
     *      Gets passed as second arg to the parser method, unless null.
     * @param null|bool $errorReturn
     *      Set return value of parser when failing.
     *
     * @return $this|Client
     */
    public function parser($object, string $method, $options = null, $errorReturn = null) : Client
    {
        $is_callable = true;
        $obj = $object;
        if ($obj === 'this') {
            $obj = $this;
        }
        elseif (!is_object($object)) {
            $is_callable = false;
            $this->log(
                LOG_ERR,
                'Client parser not callable, arg object not object',
                func_get_args()
            );
        }
        if ($is_callable && !method_exists($obj, $method)) {
            $is_callable = false;
            $this->log(
                LOG_ERR,
                'Client parser not callable, arg object has no such method',
                func_get_args()
            );
        }
        if (!$is_callable) {
            $this->error = [
                'code' => static::errorCode('parser_not_callable'),
                'name' => 'parser_not_callable',
                'message' => 'Client parser not callable',
            ];
        } else {
            $this->parser = [
                $object,
                $method,
                $options,
                $errorReturn,
            ];
        }

        return $this;
    }

    /**
     * Send HTTP HEAD request, as content-less alternative to GET.
     *
     * Chainable, returns self.
     *
     * @see Client::make()
     *
     * @param array $pathArgs
     *      Each bucket will be added to the server + endpoint URL.
     *      Example: http://ser.ver/end-point/first-path-arg/second-path-arg
     * @param array $queryArgs
     *      Each key-value pair becomes key=value.
     *      Example: http://ser.ver/end-point?first=arg&second=arg
     *
     * @return $this|Client
     */
    public function head(array $pathArgs = [], array $queryArgs = []) : Client
    {
        return $this->request('HEAD', $pathArgs, $queryArgs);
    }

    /**
     * Send HTTP GET request, for index or retrieve operation.
     *
     * Chainable, returns self.
     *
     * @see Client::make()
     *
     * @param array $pathArgs
     *      Each bucket will be added to the server + endpoint URL.
     *      Example: http://ser.ver/end-point/first-path-arg/second-path-arg
     * @param array $queryArgs
     *      Each key-value pair becomes key=value.
     *      Example: http://ser.ver/end-point?first=arg&second=arg
     *
     * @return $this|Client
     */
    public function get(array $pathArgs = [], array $queryArgs = []) : Client
    {
        return $this->request('GET', $pathArgs, $queryArgs);
    }

    /**
     * Send HTTP POST request, for create operation.
     *
     * Chainable, returns self.
     *
     * @see Client::make()
     *
     * @param array $pathArgs
     *      Each bucket will be added to the server + endpoint URL.
     *      Example: http://ser.ver/end-point/first-path-arg/second-path-arg
     * @param array $queryArgs
     *      Each key-value pair becomes key=value.
     *      Example: http://ser.ver/end-point?first=arg&second=arg
     * @param mixed $body
     *      Must be array|object if application/x-www-form-urlencoded.
     *
     * @return $this|Client
     */
    public function post(array $pathArgs = [], array $queryArgs = [], $body = null) : Client
    {
        return $this->request('POST', $pathArgs, $queryArgs, $body);
    }

    /**
     * Send HTTP PUT request, for update operation.
     *
     * Chainable, returns self.
     *
     * @see Client::make()
     *
     * @param array $pathArgs
     *      Each bucket will be added to the server + endpoint URL.
     *      Example: http://ser.ver/end-point/first-path-arg/second-path-arg
     * @param array $queryArgs
     *      Each key-value pair becomes key=value.
     *      Example: http://ser.ver/end-point?first=arg&second=arg
     * @param mixed $body
     *      Must be array|object if application/x-www-form-urlencoded.
     *
     * @return $this|Client
     */
    public function put(array $pathArgs = [], array $queryArgs = [], $body = null) : Client
    {
        return $this->request('PUT', $pathArgs, $queryArgs, $body);
    }

    /**
     * Send HTTP DELETE request, for remove or delete operation.
     *
     * Chainable, returns self.
     *
     * @see Client::make()
     *
     * @param array $pathArgs
     *      Each bucket will be added to the server + endpoint URL.
     *      Example: http://ser.ver/end-point/first-path-arg/second-path-arg
     * @param array $queryArgs
     *      Each key-value pair becomes key=value.
     *      Example: http://ser.ver/end-point?first=arg&second=arg
     *
     * @return $this|Client
     */
    public function delete(array $pathArgs = [], array $queryArgs = []) : Client
    {
        return $this->request('DELETE', $pathArgs, $queryArgs);
    }

    /**
     * @see Client::make()
     *
     * @param $method
     *      HEAD.
     *      GET ~ index|retrieve (default).
     *      POST ~ create.
     *      PUT ~ update.
     *      DELETE.
     * @param array $pathArgs
     * @param array $queryArgs
     * @param mixed $body
     *      Ignored unless $method is POST or PUT.
     *      Must be array|object if application/x-www-form-urlencoded.
     *
     * @return $this|Client
     */
    public function request(
        string $method = 'GET', array $pathArgs = [], array $queryArgs = [], $body = null
    ) : Client {
        // Check for previous error, like empty constructor arg $server.
        if ($this->error) {
            return $this;
        }

        // Reset instance.
        $this->reset();

        $this->url = $this->server . $this->endpointAdjust();

        // Options.
        $options =& $this->options;

        $record_args = false;
        if (!empty($options['record_args'])) {
            $record_args = true;
            $this->argsRecorded = [];
        }

        // Path args: double URL encoding of some chars,
        // to prevent parsing errors.
        // A slash in a path arg could otherwise be interpreted as two path
        // fragments instead of one (if a request URL resolver url-decodes
        // full url before parsing into path fragments and query args).
        if ($pathArgs) {
            foreach ($pathArgs as $val) {
                $this->url .= '/' . rawurlencode(str_replace(['/', '?', '&', '='], ['%2F', '3F', '26', '3D'], $val));
            }
            if ($record_args) {
                $this->argsRecorded['path'] =& $pathArgs;
            }
        }

        // Query arguments: single URL encoding.
        if ($queryArgs) {
            $i = -1;
            foreach ($queryArgs as $key => $val) {
                // Only ?-delimiter if first arg and (empty endpoint
                // or endpoint doesn't contain ?).
                $this->url .= (!(++$i) && (!$this->endpoint || !strpos($this->endpoint, '?')) ? '?' : '&')
                    . $key . '=' . rawurlencode($val);
            }
            if ($record_args) {
                $this->argsRecorded['query'] =& $queryArgs;
            }
        }

        // Basic cURL options.
        $curl_opts = [
            // Don't include header in output.
            CURLOPT_HEADER => false,
            // Get response as string, don't echo it.
            CURLOPT_RETURNTRANSFER => true,
            // Follow redirects.
            CURLOPT_FOLLOWLOCATION => true,
            // Timeouts.
            CURLOPT_CONNECTTIMEOUT => $options['connect_timeout'],
            CURLOPT_TIMEOUT => $options['request_timeout'],
        ];

        // Handle long request timeout; make sure PHP doesn't time out
        // before cURL does.
        // Only if any max_execution_time at all (is zero in CLI mode).
        if (
            $options['request_timeout'] > static::REQUEST_TIMEOUT_DEFAULT
            && ($env_timeout = ini_get('max_execution_time'))
        ) {
            // We cannot know if/when anybody else postponed execution timeout,
            // but at least keep track of own postponal(s).
            $t_last_postponed = static::$tExecTimeoutPostponed;
            $elapsed = time() - (!$t_last_postponed ? (int) $_SERVER['REQUEST_TIME'] : $t_last_postponed);
            $remaining = (int) $env_timeout - $elapsed;
            $needed = $options['request_timeout'] + static::SURPLUS_EXECTIME_DEFAULT;
            if ($remaining < $needed) {
                static::$tExecTimeoutPostponed = time();
                set_time_limit($needed);
            }
        }

        // Getting response header comes with a performance price tag,
        // so we only do it on demand.
        if (!empty($options['get_headers'])) {
            $curl_opts[CURLOPT_HEADERFUNCTION] = [$this, 'responseHeaderCallback'];
        }

        // SSL.
        $ca_file = '';
        if ($this->ssl) {
            // Don't verify SSL certificate?
            if (!$options['ssl_verify']) {
                $curl_opts[CURLOPT_SSL_VERIFYPEER] = false;
            }
            else {
                // Use CA cert bundle file (or custom cert file).
                $ca_file = $options['ssl_cacert_file'];
                // Unless path+file (custom ssl_cacert_file option using path+file
                // instead of just file), prepend path.
                if (!strpos(' ' . $ca_file, '/')) {
                    $ca_file = static::CERTIFICATES_DIR . '/' . $ca_file;
                }
                $curl_opts[CURLOPT_CAINFO] = $ca_file;
            }
        }

        // user:pass?
        if (!empty($options['auth'])) {
            if ($options['auth'] == 'ntlm') {
                $curl_opts[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_1_1;
                $curl_opts[CURLOPT_HTTPAUTH] = CURLAUTH_NTLM;
            }
            $curl_opts[CURLOPT_USERPWD] = $options['user'] . ':' . $options['pass'];
        }

        // Headers.
        $headers = [
            'Accept: ' . $this->accept,
            'Accept-Charset: ' . $this->acceptCharset,
        ];
        if (!empty($options['headers'])) {
            // Copy, because we may add header(s), per request.
            $hdrs =& $options['headers'];
            foreach ($hdrs as $key => $val) {
                $headers[] = $key . ': ' . $val;
            }
            unset($hdrs);
        }
        if (!empty($options['service_response_info_wrapper'])) {
            // Custom header which tells RESTmini Service to wrap payload
            // in response info object.
            $headers[] = 'X-Rest-Service-Response-Info-Wrapper: 1';
        }

        // http method.
        switch ($method) {
            case 'HEAD':
                $curl_opts[CURLOPT_CUSTOMREQUEST] = 'HEAD';
                break;
            case 'GET':
                break;
            case 'POST':
                $curl_opts[CURLOPT_POST] = true;
                if ($body) {
                    // Resolve request body content type.
                    $content_type_json = false;
                    // Default content type of the payload is x-www-form-urlencoded.
                    if (empty($options['content_type'])) {
                        $options['content_type'] = 'application/x-www-form-urlencoded';
                    }
                    elseif (strpos($options['content_type'], 'application/json') === 0) {
                        $content_type_json = true;
                    }
                    $headers[] = 'Content-Type: ' . $options['content_type'];
                    if (!$content_type_json) {
                        if (!is_array($body) && !is_object($body)) {
                            $this->error = [
                                'code' => static::errorCode('argument_type_invalid'),
                                'name' => 'argument_type_invalid',
                                'message' => 'Non-empty body argument type[' . gettype($body) . '] is not valid'
                                    . ' for application/x-www-form-urlencoded request, must be array or object',
                            ];
                            $info = $this->info('request');
                            if ($record_args) {
                                $info['args'] = [
                                    'path' => $pathArgs,
                                    'query' => $queryArgs,
                                    'body' => $body,
                                ];
                            }
                            $this->log(
                                LOG_ERR,
                                'Client invalid body argument',
                                $info
                            );

                            return $this;
                        }
                        $headers[] = 'Content-Length: ' . strlen(
                                $curl_opts[CURLOPT_POSTFIELDS] = http_build_query($body)
                            );
                    } else {
                        $headers[] = 'Content-Length: ' . strlen(
                                $curl_opts[CURLOPT_POSTFIELDS] = json_encode($body)
                            );
                    }
                    if ($record_args) {
                        $this->argsRecorded['body'] = !is_object($body) ? $body : clone $body;
                    }
                }
                else {
                    // Prevent 413 Request Entity Too Large error; Apache responds
                    // like that when POST and no content length header.
                    $headers[] = 'Content-Length: 0';
                }
                break;
            case 'PUT':
                $curl_opts[CURLOPT_CUSTOMREQUEST] = 'PUT';
                // CURLOPT_PUT is no good, because it makes cUrl
                // send 'Tranfer-Encoding: chunked'.
                // And 'chunked' is only useful when sending files, not 'form data'.
                if ($body) {
                    // Making a server look for POST (body) vars when HTTP method is PUT
                    // may be real hard.
                    $headers[] = 'X-HTTP-Method-Override: PUT';
                    // Resolve request body content type.
                    $content_type_json = false;
                    if (empty($options['content_type'])) {
                        $options['content_type'] = 'application/x-www-form-urlencoded';
                    }
                    elseif (strpos($options['content_type'], 'application/json') === 0) {
                        $content_type_json = true;
                    }
                    $headers[] = 'Content-Type: ' . $options['content_type'];
                    if (!$content_type_json) {
                        if (!is_array($body) && !is_object($body)) {
                            $this->error = [
                                'code' => static::errorCode('argument_type_invalid'),
                                'name' => 'argument_type_invalid',
                                'message' => 'Non-empty body argument type[' . gettype($body) . '] is not valid'
                                    . ' for application/x-www-form-urlencoded request, must be array or object',
                            ];
                            $info = $this->info('request');
                            if ($record_args) {
                                $info['args'] = [
                                    'path' => $pathArgs,
                                    'query' => $queryArgs,
                                    'body' => $body,
                                ];
                            }
                            $this->log(
                                LOG_ERR,
                                'Client invalid body argument',
                                $info
                            );

                            return $this;
                        }
                        $headers[] = 'Content-Length: ' . strlen(
                                $curl_opts[CURLOPT_POSTFIELDS] = http_build_query($body)
                            );
                    } else {
                        $headers[] = 'Content-Length: ' . strlen(
                                $curl_opts[CURLOPT_POSTFIELDS] = json_encode($body)
                            );
                    }
                    if ($record_args) {
                        $this->argsRecorded['body'] = !is_object($body) ? $body : clone $body;
                    }
                }
                else {
                    $headers[] = 'Content-Length: 0';
                }
                break;
            case 'DELETE':
                $curl_opts[CURLOPT_CUSTOMREQUEST] = 'DELETE';
                break;
            default:
                $this->error = [
                    'code' => static::errorCode('method_not_supported'),
                    'name' => 'method_not_supported',
                    'message' => 'Unsupported HTTP method',
                ];
                $info = $this->info('request');
                if ($record_args) {
                    $info['args'] = [
                        'path' => $pathArgs,
                        'query' => $queryArgs,
                        'body' => $body,
                    ];
                }
                $this->log(
                    LOG_ERR,
                    'Client unsupported HTTP method',
                    $info
                );

                return $this;
        }
        $this->method = $method;

        $curl_opts[CURLOPT_HTTPHEADER] =& $headers;

        // cUrl begin.
        $resource = curl_init($this->url);
        $this->started = time();

        if ($resource === false) {
            $this->error = [
                'code' => static::errorCode('init_connection'),
                'name' => 'init_connection',
                'message' => 'Failed to initiate connection',
            ];
            $info = $this->info('request');
            if ($record_args) {
                $info['args'] = [
                    'path' => $pathArgs,
                    'query' => $queryArgs,
                    'body' => $body,
                ];
            }
            $this->log(
                isset($this->options['log_severity']) ? $this->options['log_severity'] : static::LOG_SEVERITY_DEFAULT,
                'Failed to initiate connection',
                $info
            );

            return $this;
        }

        // Set options.
        if (!curl_setopt_array($resource, $curl_opts)) {
            $this->error = [
                'code' => static::errorCode('request_options'),
                'name' => 'request_options',
                'message' => 'Failed to set request options',
            ];
            $info = $this->info('request');
            if ($record_args) {
                $info['args'] = [
                    'path' => $pathArgs,
                    'query' => $queryArgs,
                    'body' => $body,
                ];
            }
            $info['curl info'] = curl_getinfo($resource);
            $this->log(
                isset($this->options['log_severity']) ? $this->options['log_severity'] : static::LOG_SEVERITY_DEFAULT,
                'Failed to set request options',
                $info
            );
            curl_close($resource);

            return $this;
        }
        unset($curl_opts);

        // Send request.
        $this->response = curl_exec($resource);
        $this->duration = time() - $this->started;

        // Get status code.
        $this->status = curl_getinfo($resource, CURLINFO_HTTP_CODE);
        // Content type may be null (none), or false (empty),
        // and it may contain character set (~ text/html; charset=utf-8).
        if (($content_type = curl_getinfo($resource, CURLINFO_CONTENT_TYPE))) {
            // Remove ; charset=.
            if (($pos = strpos($content_type, ';'))) {
                $content_type = substr($content_type, 0, $pos);
            }
        }
        else {
            $content_type = null;
        }
        $this->contentType = $content_type;

        // Clean up response headers.
        if (static::RESPONSE_HEADER_CUMULATE && $this->stops < 2 && !empty($options['get_headers'])) {
            $response_headers =& $this->responseHeaders;
            $header_keys = array_keys($response_headers);
            foreach ($header_keys as $hdr) {
                /**
                 * Headers keys should be strings, but just in case.
                 * @see responseHeaderCallback()
                 */
                if (strpos('' . $hdr, 'cumulative_') === 0) {
                    unset($response_headers[$hdr]);
                }
            }
            unset($response_headers, $header_keys);
        }

        // Check response.
        if ($this->response === false) {
            $curl_error_code = curl_errno($resource);
            $curl_error_string = Sanitize::getInstance()->plainText(str_replace("\n", ' ', curl_error($resource)));
            $em = $curl_error_string . ' (' . $curl_error_code . ')';
            // Common error have dedicated error codes.
            switch ($curl_error_code) {
                case CURLE_URL_MALFORMAT:
                    $error_name = 'url_malformed';
                    break;
                case CURLE_COULDNT_RESOLVE_HOST:
                    $error_name = 'host_not_found';
                    break;
                case CURLE_COULDNT_CONNECT:
                    $error_name = 'connection_failed';
                    break;
                case CURLE_OPERATION_TIMEOUTED:
                    $error_name = 'request_timed_out';
                    break;
                case CURLE_TOO_MANY_REDIRECTS:
                    $error_name = 'too_many_redirects';
                    break;
                case CURLE_SSL_CERTPROBLEM:
                    // When sending a certificate. Something that this module
                    // doesn't support.
                    $error_name = 'ssl_client_certificate';
                    break;
                case CURLE_SSL_CIPHER:
                    $error_name = 'ssl_bad_cipher';
                    break;
                case CURLE_SSL_CACERT:
                    $error_name = 'ssl_self_signed';
                    break;
                case 77: // CURLE_SSL_CACERT_BADFILE; not defined in PHP (>5.4?).
                    if (!preg_match('/\.pem$/', $ca_file)) {
                        $error_name = 'ssl_cacertfile_notpem';
                    }
                    elseif (!file_exists($ca_file)) {
                        $error_name = 'ssl_cacertfile_missing';
                    }
                    elseif (!file_get_contents($ca_file)) {
                        $error_name = 'ssl_cacertfile_empty';
                    }
                    else {
                        $error_name = 'ssl_cacertfile_bad';
                    }
                    break;
                default:
                    $error_name = 'response_false';
            }
            $this->error = [
                'code' => static::errorCode($error_name),
                'name' => $error_name,
                'message' => $em,
            ];
            $info = $this->info('request');
            if ($record_args) {
                $info['args'] = [
                    'path' => $pathArgs,
                    'query' => $queryArgs,
                    'body' => $body,
                ];
            }
            $info['curl error code'] = $curl_error_code;
            $info['curl error message'] = $curl_error_string;
            $info['curl info'] = curl_getinfo($resource);
            $this->log(
                isset($this->options['log_severity']) ? $this->options['log_severity'] : static::LOG_SEVERITY_DEFAULT,
                $em,
                $info
            );
            curl_close($resource);

            return $this;
        }
        // ...else: Response must be string, because of CURLOPT_RETURNTRANSFER.
        $this->response = trim($this->response);
        $this->contentLength = strlen($this->response);

        curl_close($resource);

        // Check for error status.
        if ($this->status >= 500) {
            $this->error = [
                'code' => static::errorCode('response_error'),
                'name' => 'response_error',
                'message' => 'Response error',
            ];
            $info = $this->info('request');
            if ($record_args) {
                $info['args'] = [
                    'path' => $pathArgs,
                    'query' => $queryArgs,
                    'body' => $body,
                ];
            }
            $this->log(
                isset($this->options['log_severity']) ? $this->options['log_severity'] : static::LOG_SEVERITY_DEFAULT,
                'Response error status code ' . $this->status,
                $info
            );
        }

        return $this;
    }

    /**
     * Last requested URL.
     *
     * @return string
     *      Empty: no last request, or current request failed before actual sending.
     */
    public function url() : string
    {
        return $this->url;
    }

    /**
     * Empty unless request has been sent, and failed.
     *
     *  If error, buckets are:
     *  - (int) code
     *  - (str) name
     *  - (str) message
     *
     * @return array
     */
    public function error() : array
    {
        return $this->error;
    }

    /**
     * HTTP response status.
     *
     * @return int
     *      Zero: request not started, or request failed.
     */
    public function status() : int
    {
        return $this->status;
    }

    /**
     * Response headers.
     *
     * @return array
     *      Empty unless last request used the 'get_headers' option.
     */
    public function headers() : array
    {
        return $this->responseHeaders;
    }

    /**
     * Get all info about the client and it's last request (if any).
     *
     *  Request/response properties:
     *  - error (empty if none)
     *
     *  Request properties:
     *  - server
     *  - endpoint
     *  - method
     *  - url
     *  - options
     *  - accept
     *  - accept_chars
     *  - args (if option record_args)
     *
     *  Response properties:
     *  - status
     *  - content_type
     *  - content_length
     *  - headers
     *  - parser
     *  - stops (zero unless option get_headers)
     *  - started
     *  - duration
     *
     * @param string $only
     *      Values: request|response.
     *      Default: empty; expose info of both request and response.
     *
     * @return array
     */
    public function info($only = '') : array
    {
        $what = 3;
        if ($only) {
            $what = $only == 'request' ? 1 : 2;
        }
        if ($what == 2) {
            $request = [];
        }
        else {
            $request = [
                'error' => $this->error,
                'server' => $this->server,
                'endpoint' => $this->endpoint,
                'method' => $this->method,
                'url' => $this->url,
                'options' => $this->options,
                'accept' => $this->accept,
                'accept_chars' => $this->acceptCharset,
            ];
            if ($this->started && !empty($this->options['record_args'])) {
                $request['args'] = $this->argsRecorded;
            }
        }
        if ($what == 1) {
            $response = [];
        }
        else {
            $response = [
                'error' => $this->error,
                'status' => $this->status,
                'content_type' => $this->contentType,
                'content_length' => $this->contentLength,
                'headers' => $this->responseHeaders,
                'stops' => $this->stops,
                'started' => $this->started,
                'duration' => $this->duration,
                'parser' => $this->parser,
            ];
            if ($what == 3) {
                // Don't dupe.
                unset($response['error']);
            }
        }

        return $request + $response;
    }

    /**
     * @return string
     */
    public function logType() : string
    {
        return $this->options['log_type'] ?? static::LOG_TYPE_DEFAULT;
    }

    /**
     * @return string
     */
    public function __toString() : string
    {
        $s = get_class($this) . '(';
        $info = $this->info();
        $first = true;
        foreach ($info as $k => $v) {
            if ($first) {
                $first = false;
            }
            else {
                $s .= ', ';
            }
            $s .= $k . ':' . $v;
        }

        return $s . ')';
    }

    /**
     * Get raw response.
     *
     * @return string|bool|null
     *      Null: request not started.
     *      False: request failed.
     */
    public function raw()
    {
        return $this->response;
    }

    /**
     * Parsed response, or array of most properties of the response.
     *
     * If $fetchKeyPath, and it doesn't match: returns the whole parsed response.
     *
     *  Returned wrapper array buckets when $responseInfo:
     *  - (int) status
     *  - (str|null) content_type
     *  - (int) content_length
     *  - (arr) headers
     *  - (mixed) result
     *  - (arr) error
     *
     * @code
     * $get_result_key = Client::make('http://server', '/endpoint')->get()
     *     ->result(['remote', 'server', 'wraps', 'my', 'data']);
     * @endcode
     *
     * @param array $fetchKeyPath
     *      List of keys to traverse by to find the actual payload data.
     *      Default: empty.
     * @param bool $responseInfo
     *      Ignored if $fetchKeyPath, unless error; then response info may be useful.
     *      Truthy: get all properties of the response.
     *      Default: false (~ get result only).
     *
     * @return mixed
     *      Null: request not started, or failed to parse response.
     *      False: request failed, or actual parsed response.
     *      Empty string: empty response.
     */
    public function result($fetchKeyPath = [], $responseInfo = false)
    {
        if ($this->error) {
            return !$responseInfo ? false : (
                $this->info('response') + [
                    'result' => false,
                ]
            );
        }

        // Empty.
        if ($this->response == '') {
            return !$responseInfo ? '' : (
                $this->info('response') + [
                    'result' => '',
                ]
            );
        }

        // Get out if status indicates no usable content,
        // and option 'status_vain_result_void' set and truthy.
        if (
            !$responseInfo && $this->status >= 300
            && !empty($this->options['status_vain_result_void'])
        ) {
            $this->log(
                isset($this->options['log_severity']) ? $this->options['log_severity'] : static::LOG_SEVERITY_DEFAULT,
                'Response status ' . $this->status . ' indicates no usable content, option status_vain_result_void',
                get_object_vars($this)
            );
            return '';
        }

        // Detect HTML if we don't want that - lots of services return HTML
        // as fallback when erring.
        $parse = true;
        if (!empty($this->options['ignore_content_type'])) {
            if ($this->response{0} === '<'
                // text/xml|application/xml.
                && !strpos($this->accept, 'xml')
                // text/html.
                && !strpos($this->accept, 'html')
            ) {
                $parse = false;
            }
        } elseif (!$this->contentType || strpos($this->accept, $this->contentType) === false) {
            $parse = false;
        }
        if (!$parse) {
            $this->error = [
                'code' => static::errorCode('content_type_mismatch'),
                'name' => 'content_type_mismatch',
                'message' => 'Response content type doesnt match parser',
            ];
            return !$responseInfo ? null : (
                $this->info('response') + [
                    'result' => null,
                ]
            );
        }

        // Parse.
        $data = $this->parse();
        // Parse error.
        if ($data === $this->parser['error']) {
            $this->log(
                isset($this->options['log_severity']) ? $this->options['log_severity'] : static::LOG_SEVERITY_DEFAULT,
                'Failed to parse response',
                get_object_vars($this)
            );
            $this->error = [
                'code' => static::errorCode('response_parse'),
                'name' => 'response_parse',
                'message' => 'Failed to parse response',
            ];
            return !$responseInfo ? null : (
                $this->info('response') + [
                    'result' => null,
                ]
            );
        }

        if (!$fetchKeyPath) {
            return !$responseInfo ? $data : (
                $this->info('response') + [
                    'result' => $data,
                ]
            );
        }

        // Copy the whole data set in case the keypath doesn't match.
        $orig = $data;
        // Recurse.
        foreach ($fetchKeyPath as $key) {
            if ($data && is_array($data) && array_key_exists($key, $data)) {
                $data = $data[$key];
            } else {
                // No match, return all.
                $this->error = [
                    'code' => static::errorCode('keypath_not_found'),
                    'name' => 'keypath_not_found',
                    'message' => 'Result key-path not found in result data',
                ];
                return $orig;
            }
        }
        return $data;
    }

    /**
     * Resets all response properties that may waste memory space.
     *
     * Chainable, returns self.
     *
     * Does not reset parser properties.
     *
     * No need to call this method prior to a new request, unless previous
     * request erred.
     *
     * @return $this|Client
     */
    public function reset() : Client
    {
        $this->error = [];
        $this->method = '';
        $this->url = '';
        $this->argsRecorded = null;
        $this->started = 0;
        $this->duration = 0;
        $this->responseHeaders = [];
        $this->stops = 0;
        $this->status = 0;
        $this->contentType = null;
        $this->contentLength = 0;
        $this->response = null;

        return $this;
    }

    /**
     * Attempts to parse response body using the buckets of instance parser.
     *
     * @return mixed
     */
    protected function parse()
    {
        $parser = $this->parser;
        $object = $parser['object'];
        if ($object === 'this') {
            $object = $this;
        }
        $method = $parser['method'];

        return $parser['options'] === null ? $object->$method($this->response) :
            $object->$method($this->response, $parser['options']);
    }

    /**
     * Parses JSON.
     *
     * @param string $response
     * @param bool $assoc
     *      True: objects will be converted to associative arrays.
     *      Default: false. However, this class' instance parser defaults to true.
     * @return mixed
     *      Null on error.
     */
    public function parseJson($response, $assoc = false)
    {
        return json_decode($response, $assoc);
    }

    /**
     * Adjust endpoint before sending request.
     *
     * Mustn't change instance var endpoint, must only return that
     * or an adjusted version of it.
     *
     * Meant to be overridden in extending class.
     *
     * @see Client::request()
     *
     * @return string
     */
    protected function endpointAdjust() : string
    {
        return $this->endpoint;
    }

    /**
     * Get error code by name, or code list, or code range.
     *
     * @param string $name
     *      Non-empty: return code by name (defaults to 'unknown')
     *      Default: empty (~ return codes list).
     * @param bool $range
     *      true: return code range [(N-first, N-last].
     *      Default: false (~ ignore argument).
     *
     * @return int|array
     */
    public static function errorCode($name = '', $range = false)
    {
        static $codes;

        if ($name) {
            return static::ERROR_CODE_OFFSET
                + (array_key_exists($name, static::ERROR_CODES) ? static::ERROR_CODES[$name] :
                    static::ERROR_CODES['unknown']
                );
        }

        if ($range) {
            return [
                static::ERROR_CODE_OFFSET,
                // Range of sub modules should only be 100, to allow for all sub modules
                // within an overall range of 1000.
                static::ERROR_CODE_OFFSET + 99
            ];
        }

        if (!$codes) {
            // Copy.
            $codes = static::ERROR_CODES;
            if (($offset = static::ERROR_CODE_OFFSET)) {
                foreach ($codes as &$code) {
                    $code += $offset;
                }
                // Iteration ref.
                unset($code);
            }
        }

        return $codes;
    }

    /**
     * Uses optional PSR-3 logger and Inspect, and error_log() as fallback
     * (if severity:error or worse).
     *
     * @see \SimpleComplex\JsonLog\JsonLogEvent
     * @see \SimpleComplex\Inspect\Inspect
     *
     * @param int $severity
     * @param string $message
     * @param mixed|null $variable
     */
    protected function log(int $severity, string $message, $variable = null) /*: void*/
    {
        $container = Dependency::container();
        if (!$this->logger && $container->has('logger')) {
            $this->logger = $container->get('logger');
        }
        if ($this->logger) {
            // Enrich log context/keywords.
            $context = [
                'type' => $log_type = $this->options['log_type'] ?? static::LOG_TYPE_DEFAULT,
                'subType' => $log_type,
            ];
            if (!empty($this->error['code'])) {
                $context['code'] = $this->error['code'];
            }
            if (
                $this->started
                && !empty($this->options['correlation_id_header'])
                && $this->responseHeaders && !empty($this->responseHeaders[$this->options['correlation_id_header']])
            ) {
                $context['correlationId'] = $this->responseHeaders[$this->options['correlation_id_header']];
            }

            if ($container->has('inspect')) {
                $inspect = $container->get('inspect');
            } else {
                $inspect_class = static::CLASS_INSPECT;
                $inspect = new $inspect_class($container->has('config') ? $container->get('config') : null);
            }

            $this->logger->log(
                // We like (int) severity, PSR-3 log likes (str) word.
                Utils::getInstance()->logLevelToString($severity),
                $message . "\n" . $inspect->variable(
                    $variable,
                    [
                        'wrappers' => 1,
                    ]
                ),
                $context
            );
        } elseif ($severity <= LOG_ERR) {
            error_log(str_replace("\n", ' ', $message));
        }
    }

    /**
     * Extracts response headers.
     *
     * CURLOPT_HEADERFUNCTION implementation.
     *
     * @see http://php.net/manual/en/function.curl-setopt.php
     *
     * @param resource $resource
     * @param string $headerLine
     * @return int
     *      Header line byte length.
     */
    protected function responseHeaderCallback($resource, $headerLine)
    {
        // Remove trailing \r\n.
        $line = trim($headerLine);
        $l_line = strlen($line);
        if ($l_line) {
            $cumulate = static::RESPONSE_HEADER_CUMULATE;
            $headers =& $this->responseHeaders;

            // HTTP status text(s).
            if (
                strpos($line, 'HTTP') === 0
                && ($pos = strpos($line, ' '))
                && ctype_digit(substr($line, $pos + 1, 3))
            ) {
                $val = substr($line, $pos + 1);
                // Register 100 Continue status.
                if ($val == '100 Continue') {
                    $headers['initial_status'] = '100 Continue';
                }
                else {
                    ++$this->stops;
                    // Status text must be without status code.
                    $headers['status_text'] = substr($val, 4);
                    if ($cumulate) {
                        if (isset($headers['cumulative_status'])) {
                            if ($cumulate != 'rtl') {
                                $headers['cumulative_status'] .= static::RESPONSE_HEADER_SEP . $val;
                            }
                            else {
                                $headers['cumulative_status'] = $val . static::RESPONSE_HEADER_SEP
                                    . $headers['cumulative_status'];
                            }
                        }
                        else {
                            $headers['cumulative_status'] = $val;
                        }
                    }
                }
            }
            // Straight 'key: value' headers. If dupes, the values must be
            // concatenated as comma-separated list.
            else {
                $pos = strpos($line, ':');
                if ($pos) {
                    $name = substr($line, 0, $pos);
                    // 'key: value'.
                    if ($l_line > $pos + 1 && $line{$pos + 1} === ' ') {
                        $val = substr($line, $pos + 2);
                    }
                    // 'key:value'.
                    else {
                        $val = substr($line, $pos + 1);
                    }
                    $headers[$name] = $val;
                    if ($cumulate) {
                        $cumulate_name = 'cumulative_' . $name;
                        if (isset($headers[$cumulate_name])) {
                            if ($cumulate != 'rtl') {
                                $headers[$cumulate_name] .= static::RESPONSE_HEADER_SEP . $val;
                            }
                            else {
                                $headers[$cumulate_name] = $val . static::RESPONSE_HEADER_SEP . $headers[$cumulate_name];
                            }
                        }
                        else {
                            $headers[$cumulate_name] = $val;
                        }
                    }
                }
                // Wrongish header; not 'key: value'.
                else {
                    $headers['ill-formed_' . count($headers)] = $line;
                }
            }
        }

        // Satisfy return value contract with PHP cUrl.
        return strlen($headerLine);
    }
}
