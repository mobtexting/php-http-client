<?php

namespace Mobtexting\Http;

/**
 *
 * Quickly and easily access any REST or REST-like API.
 *
 * @method Response get($body = null, $query = null, $headers = null)
 * @method Response post($body = null, $query = null, $headers = null)
 * @method Response patch($body = null, $query = null, $headers = null)
 * @method Response put($body = null, $query = null, $headers = null)
 * @method Response delete($body = null, $query = null, $headers = null)
 *
 * @method Client|Response send()
 *
 *
 * Missed any? Simply add them by doing: @method Client method()
 */
class Client
{
    const TOO_MANY_REQUESTS_HTTP_CODE = 429;
    /**
     * @var string
     */
    protected $host;
    /**
     * @var array
     */
    protected $headers;

    /**
     * @var array
     */
    protected $path = [];
    /**
     * @var array
     */
    protected $curlOptions;
    /**
     * @var bool
     */
    protected $isConcurrentRequest;
    /**
     * @var array
     */
    protected $savedRequests;
    /**
     * @var bool
     */
    protected $retryOnLimit;
    /**
     * These are the supported HTTP verbs
     *
     * @var array
     */
    private $methods = ['get', 'post', 'patch', 'put', 'delete'];
    /**
     * Initialize the client
     *
     * @param string  $host          the base url
     * @param array   $headers       global request headers
     * @param array   $curlOptions   extra options to set during curl initialization
     * @param bool    $retryOnLimit  set default retry on limit flag
     */
    public function __construct($host, $headers = null, $curlOptions = null, $retryOnLimit = false)
    {
        $this->host = $host;
        $this->headers = $headers ?: [];
        $this->curlOptions = $curlOptions ?: [];
        $this->retryOnLimit = $retryOnLimit;
        $this->isConcurrentRequest = false;
        $this->savedRequests = [];
    }

    public function request($url, $headers, $curlOptions = null)
    {
        return new self($url, $headers, $curlOptions);
    }

    public static function instance($url, $headers, $curlOptions = null)
    {
        return new self($url, $headers, $curlOptions);
    }

    /**
     * @return string
     */
    public function getHost()
    {
        return $this->host;
    }
    /**
     * @return array
     */
    public function getHeaders()
    {
        return $this->headers;
    }

    /**
     * @return array
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * @return array
     */
    public function setPath($path)
    {
        $this->path = $path;

        return $this;
    }

    /**
     * @return array
     */
    public function getCurlOptions()
    {
        return $this->curlOptions;
    }
    /**
     * Set extra options to set during curl initialization
     *
     * @param array $options
     *
     * @return Client
     */
    public function setCurlOptions(array $options)
    {
        $this->curlOptions = $options;
        return $this;
    }
    /**
     * Set default retry on limit flag
     *
     * @param bool $retry
     *
     * @return Client
     */
    public function setRetryOnLimit($retry)
    {
        $this->retryOnLimit = $retry;
        return $this;
    }
    /**
     * Set concurrent request flag
     *
     * @param bool $isConcurrent
     *
     * @return Client
     */
    public function setIsConcurrentRequest($isConcurrent)
    {
        $this->isConcurrentRequest = $isConcurrent;
        return $this;
    }
    /**
     * Build the final URL to be passed
     *
     * @param array $queryParams an array of all the query parameters
     *
     * @return string
     */
    protected function buildUrl($queryParams = null)
    {
        $path = '/' . (is_array($this->path) ? implode('/', $this->path) : '');
        if (isset($queryParams)) {
            $path .= '?' . http_build_query($queryParams);
        }

        return sprintf('%s%s', $this->host, $path);
    }
    /**
     * Creates curl options for a request
     * this function does not mutate any private variables
     *
     * @param string $method
     * @param array $body
     * @param array $headers
     *
     * @return array
     */
    protected function createCurlOptions($method, $body = null, $headers = null)
    {
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FAILONERROR => false,
        ] + $this->curlOptions;

        if (isset($headers)) {
            $headers = array_merge($this->headers, $headers);
        } else {
            $headers = $this->headers;
        }

        if (isset($body) && is_array($body)) {
            $encodedBody = json_encode($body);
            $options[CURLOPT_POSTFIELDS] = $encodedBody;
            $headers = array_merge($headers, ['Content-Type: application/json']);
        } else if (isset($body)) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        $options[CURLOPT_HTTPHEADER] = $headers;

        return $options;
    }
    /**
     * @param array $requestData
     *      e.g. ['method' => 'POST', 'url' => 'www.example.com', 'body' => 'test body', 'headers' => []]
     * @param bool $retryOnLimit
     *
     * @return array
     */
    protected function createSavedRequest(array $requestData, $retryOnLimit = false)
    {
        return array_merge($requestData, ['retryOnLimit' => $retryOnLimit]);
    }
    /**
     * @param array $requests
     *
     * @return array
     */
    protected function createCurlMultiHandle(array $requests)
    {
        $channels = [];
        $multiHandle = curl_multi_init();
        foreach ($requests as $id => $data) {
            $channels[$id] = curl_init($data['url']);
            $curlOpts = $this->createCurlOptions($data['method'], $data['body'], $data['headers']);
            curl_setopt_array($channels[$id], $curlOpts);
            curl_multi_add_handle($multiHandle, $channels[$id]);
        }
        return [$channels, $multiHandle];
    }
    /**
     * Prepare response object
     *
     * @param resource $channel  the curl resource
     * @param string   $content
     *
     * @return Response object
     */
    protected function parseResponse($channel, $content)
    {
        $headerSize = curl_getinfo($channel, CURLINFO_HEADER_SIZE);
        $statusCode = curl_getinfo($channel, CURLINFO_HTTP_CODE);
        $responseBody = substr($content, $headerSize);
        $responseHeaders = substr($content, 0, $headerSize);
        $responseHeaders = explode("\n", $responseHeaders);
        $responseHeaders = array_map('trim', $responseHeaders);

        return new Response($statusCode, $responseBody, $responseHeaders);
    }
    /**
     * Retry request
     *
     * @param array  $responseHeaders headers from rate limited response
     * @param string $method          the HTTP verb
     * @param string $url             the final url to call
     * @param array  $body            request body
     * @param array  $headers         original headers
     *
     * @return Response response object
     */
    protected function retryRequest(array $responseHeaders, $method, $url, $body, $headers)
    {
        $sleepDurations = $responseHeaders['X-Ratelimit-Reset'] - time();
        sleep($sleepDurations > 0 ? $sleepDurations : 0);
        return $this->makeRequest($method, $url, $body, $headers, false);
    }
    /**
     * Make the API call and return the response.
     * This is separated into it's own function, so we can mock it easily for testing.
     *
     * @param string $method       the HTTP verb
     * @param string $url          the final url to call
     * @param array  $body         request body
     * @param array  $headers      any additional request headers
     * @param bool   $retryOnLimit should retry if rate limit is reach?
     *
     * @return Response object
     */
    public function makeRequest($method, $url, $body = null, $headers = null, $retryOnLimit = false)
    {
        $channel = curl_init($url);
        $options = $this->createCurlOptions($method, $body, $headers);

        curl_setopt_array($channel, $options);
        $content = curl_exec($channel);
        $response = $this->parseResponse($channel, $content);
        if ($response->statusCode() === self::TOO_MANY_REQUESTS_HTTP_CODE && $retryOnLimit) {
            $responseHeaders = $response->headers(true);
            return $this->retryRequest($responseHeaders, $method, $url, $body, $headers);
        }
        curl_close($channel);
        return $response;
    }
    /**
     * Send all saved requests at once
     *
     * @param array $requests
     *
     * @return Response[]
     */
    public function makeAllRequests(array $requests = [])
    {
        if (empty($requests)) {
            $requests = $this->savedRequests;
        }
        list($channels, $multiHandle) = $this->createCurlMultiHandle($requests);
        // running all requests
        $isRunning = null;
        do {
            curl_multi_exec($multiHandle, $isRunning);
        } while ($isRunning);
        // get response and close all handles
        $retryRequests = [];
        $responses = [];
        $sleepDurations = 0;
        foreach ($channels as $id => $channel) {
            $content = curl_multi_getcontent($channel);
            $response = $this->parseResponse($channel, $content);
            if ($response->statusCode() === self::TOO_MANY_REQUESTS_HTTP_CODE && $requests[$id]['retryOnLimit']) {
                $headers = $response->headers(true);
                $sleepDurations = max($sleepDurations, $headers['X-Ratelimit-Reset'] - time());
                $requestData = [
                    'method' => $requests[$id]['method'],
                    'url' => $requests[$id]['url'],
                    'body' => $requests[$id]['body'],
                    'headers' => $headers,
                ];
                $retryRequests[] = $this->createSavedRequest($requestData, false);
            } else {
                $responses[] = $response;
            }
            curl_multi_remove_handle($multiHandle, $channel);
        }
        curl_multi_close($multiHandle);
        // retry requests
        if (!empty($retryRequests)) {
            sleep($sleepDurations > 0 ? $sleepDurations : 0);
            $responses = array_merge($responses, $this->makeAllRequests($retryRequests));
        }
        return $responses;
    }
    /**
     * Add variable values to the url. (e.g. /your/api/{variable_value}/call)
     * Another example: if you have a PHP reserved word, such as and, in your url, you must use this method.
     *
     * @param string $name name of the url segment
     *
     * @return Client object
     */
    public function _($name = null)
    {
        if (isset($name)) {
            $this->path[] = $name;
        }
        $client = new static($this->host, $this->headers);
        $client->setPath($this->path);
        $client->setCurlOptions($this->curlOptions);
        $client->setRetryOnLimit($this->retryOnLimit);
        $this->path = [];

        return $client;
    }

    /**
     * Dynamically add method calls to the url, then call a method.
     * (e.g. client.name.name.method())
     *
     * @param string $name name of the dynamic method call or HTTP verb
     * @param array  $args parameters passed with the method call
     *
     * @return Client|Response|Response[]|null object
     */
    public function __call($name, $args)
    {
        $name = strtolower($name);
        // send all saved requests
        if (($name === 'send') && $this->isConcurrentRequest) {
            return $this->makeAllRequests();
        }

        if (in_array($name, $this->methods, true)) {
            $body = isset($args[0]) ? $args[0] : null;
            $queryParams = isset($args[1]) ? $args[1] : null;
            $headers = isset($args[2]) ? $args[2] : null;
            $retryOnLimit = isset($args[3]) ? $args[3] : $this->retryOnLimit;

            if ($body && strtolower($name) == 'post') {
                $body = http_build_query($body);
            }

            $url = $this->buildUrl($queryParams);

            if ($this->isConcurrentRequest) {
                // save request to be sent later
                $requestData = ['method' => $name, 'url' => $url, 'body' => $body, 'headers' => $headers];
                $this->savedRequests[] = $this->createSavedRequest($requestData, $retryOnLimit);
                return null;
            }
            return $this->makeRequest($name, $url, $body, $headers, $retryOnLimit);
        }

        return $this->_($name);
    }
}
