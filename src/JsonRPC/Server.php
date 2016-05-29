<?php

namespace JsonRPC;

use Closure;
use Exception;
use JsonRPC\Request\BatchRequestParser;
use JsonRPC\Request\RequestParser;
use JsonRPC\Response\ResponseBuilder;
use JsonRPC\Validator\HostValidator;
use JsonRPC\Validator\JsonFormatValidator;
use JsonRPC\Validator\UserValidator;

/**
 * JsonRPC server class
 *
 * @package JsonRPC
 * @author  Frederic Guillot
 */
class Server
{
    /**
     * Allowed hosts
     *
     * @access protected
     * @var array
     */
    protected $hosts = array();

    /**
     * Data received from the client
     *
     * @access protected
     * @var array
     */
    protected $payload = array();

    /**
     * List of exception classes that should be relayed to client
     *
     * @access protected
     * @var array
     */
    protected $exceptions = array();

    /**
     * Username
     *
     * @access protected
     * @var string
     */
    protected $username = '';

    /**
     * Password
     *
     * @access protected
     * @var string
     */
    protected $password = '';

    /**
     * Allowed users
     *
     * @access protected
     * @var array
     */
    protected $users = array();

    /**
     * $_SERVER
     *
     * @access protected
     * @var array
     */
    protected $serverVariable;

    /**
     * ProcedureHandler object
     *
     * @access protected
     * @var ProcedureHandler
     */
    protected $procedureHandler;

    /**
     * MiddlewareHandler object
     *
     * @access protected
     * @var MiddlewareHandler
     */
    protected $middlewareHandler;

    /**
     * Response builder
     *
     * @access protected
     * @var ResponseBuilder
     */
    protected $responseBuilder;

    /**
     * Response builder
     *
     * @access protected
     * @var RequestParser
     */
    protected $requestParser;

    /**
     *
     * Batch request parser
     *
     * @access protected
     * @var BatchRequestParser
     */
    protected $batchRequestParser;

    /**
     * Constructor
     *
     * @access public
     * @param  string              $request
     * @param  array               $server
     * @param  ResponseBuilder     $responseBuilder
     * @param  RequestParser       $requestParser
     * @param  BatchRequestParser  $batchRequestParser
     * @param  ProcedureHandler    $procedureHandler
     * @param  MiddlewareHandler   $middlewareHandler
     */
    public function __construct(
        $request = '',
        array $server = array(),
        ResponseBuilder $responseBuilder = null,
        RequestParser $requestParser = null,
        BatchRequestParser $batchRequestParser = null,
        ProcedureHandler $procedureHandler = null,
        MiddlewareHandler $middlewareHandler = null
    ) {
        if ($request !== '') {
            $this->payload = json_decode($request, true);
        } else {
            $this->payload = json_decode(file_get_contents('php://input'), true);
        }

        $this->serverVariable = $server ?: $_SERVER;
        $this->responseBuilder = $responseBuilder ?: ResponseBuilder::create();
        $this->requestParser = $requestParser ?: RequestParser::create();
        $this->batchRequestParser = $batchRequestParser ?: BatchRequestParser::create();
        $this->procedureHandler = $procedureHandler ?: new ProcedureHandler();
        $this->middlewareHandler = $middlewareHandler ?: new MiddlewareHandler();
    }

    /**
     * Define alternative authentication header
     *
     * @access public
     * @param  string   $header   Header name
     * @return Server
     */
    public function setAuthenticationHeader($header)
    {
        if (! empty($header)) {
            $header = 'HTTP_'.str_replace('-', '_', strtoupper($header));
            $value = $this->getServerVariable($header);

            if (! empty($value)) {
                list($this->username, $this->password) = explode(':', base64_decode($value));
            }
        }

        return $this;
    }

    /**
     * Get ProcedureHandler
     *
     * @access public
     * @return ProcedureHandler
     */
    public function getProcedureHandler()
    {
        return $this->procedureHandler;
    }

    /**
     * Get MiddlewareHandler
     *
     * @access public
     * @return MiddlewareHandler
     */
    public function getMiddlewareHandler()
    {
        return $this->middlewareHandler;
    }

    /**
     * Get username
     *
     * @access public
     * @return string
     */
    public function getUsername()
    {
        return $this->username ?: $this->getServerVariable('PHP_AUTH_USER');
    }

    /**
     * Get password
     *
     * @access public
     * @return string
     */
    public function getPassword()
    {
        return $this->password ?: $this->getServerVariable('PHP_AUTH_PW');
    }

    /**
     * IP based client restrictions
     *
     * @access public
     * @param  array   $hosts   List of hosts
     * @return Server
     */
    public function allowHosts(array $hosts)
    {
        $this->hosts = $hosts;
        return $this;
    }

    /**
     * HTTP Basic authentication
     *
     * @access public
     * @param  array   $users   Dictionary of username/password
     * @return Server
     */
    public function authentication(array $users)
    {
        $this->users = $users;
        return $this;
    }

    /**
     * Register a new procedure
     *
     * @access public
     * @param  string   $procedure       Procedure name
     * @param  closure  $callback        Callback
     * @return Server
     */
    public function register($procedure, Closure $callback)
    {
        $this->procedureHandler->withCallback($procedure, $callback);
        return $this;
    }

    /**
     * Bind a procedure to a class
     *
     * @access public
     * @param  string   $procedure    Procedure name
     * @param  mixed    $class        Class name or instance
     * @param  string   $method       Procedure name
     * @return Server
     */
    public function bind($procedure, $class, $method = '')
    {
        $this->procedureHandler->withClassAndMethod($procedure, $class, $method);
        return $this;
    }

    /**
     * Bind a class instance
     *
     * @access public
     * @param  mixed   $instance    Instance name
     * @return Server
     */
    public function attach($instance)
    {
        $this->procedureHandler->withObject($instance);
        return $this;
    }

    /**
     * Bind an exception
     * If this exception occurs it is relayed to the client as JSON-RPC error
     *
     * @access public
     * @param  mixed   $exception    Exception class. Defaults to all.
     * @return Server
     */
    public function attachException($exception = 'Exception')
    {
        $this->exceptions[] = $exception;
        return $this;
    }

    /**
     * Parse incoming requests
     *
     * @access public
     * @return string
     */
    public function execute()
    {
        try {
            JsonFormatValidator::validate($this->payload);
            HostValidator::validate($this->hosts, $this->getServerVariable('REMOTE_ADDR'));
            UserValidator::validate($this->users, $this->getUsername(), $this->getPassword());

            $this->middlewareHandler
                ->withUsername($this->getUsername())
                ->withPassword($this->getPassword())
            ;

            $response = $this->parseRequest();

        } catch (Exception $e) {
            $response = $this->responseBuilder->withException($e)->build();
        }

        $this->responseBuilder->sendHeaders();
        return $response;
    }

    /**
     * Parse incoming request
     *
     * @access protected
     * @return string
     */
    protected function parseRequest()
    {
        if (BatchRequestParser::isBatchRequest($this->payload)) {
            return $this->batchRequestParser
                ->withPayload($this->payload)
                ->withProcedureHandler($this->procedureHandler)
                ->withMiddlewareHandler($this->middlewareHandler)
                ->parse();
        }

        return $this->requestParser
            ->withPayload($this->payload)
            ->withProcedureHandler($this->procedureHandler)
            ->withMiddlewareHandler($this->middlewareHandler)
            ->parse();
    }

    /**
     * Check existence and get value of server variable
     *
     * @access protected
     * @param  string $variable
     * @return string|null
     */
    protected function getServerVariable($variable)
    {
        return isset($this->serverVariable[$variable]) ? $this->serverVariable[$variable] : null;
    }
}
