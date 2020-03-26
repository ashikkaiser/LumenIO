<?php
/**
 * This file is part of the Elephant.io package
 *
 * For the full copyright and license information, please view the LICENSE file
 * that was distributed with this source code.
 *
 * @copyright Wisembly
 * @license   http://www.opensource.org/licenses/MIT-License MIT License
 */

namespace ElephantIO\Engine;

use ElephantIO\Exception\MalformedUrlException;

class Socket
{
    /**
     * @var string
     */
    protected $url = null;

    /**
     * @var string[]
     */
    protected $parsed = null;

    /**
     * @var array
     */
    protected $context = null;

    /**
     * @var array
     */
    protected $options = null;

    /**
     * @var resource
     */
    protected $handle = null;

    /**
     * @var array
     */
    protected $errors = null;

    /**
     * @var array
     */
    protected $result = null;

    /**
     * Constructor.
     *
     * @param string $url
     * @param array $context
     * @param array $options
     */
    public function __construct($url, $context = [], $options = [])
    {
        $this->url = $url;
        $this->context = $context;
        $this->options = $options;
        $this->initialize();
    }

    /**
     * Initialize.
     */
    protected function initialize()
    {
        $this->parsed = $this->parseUrl($this->url);
        $errors       = [null, null];
        $timeout      = isset($this->options['timeout']) ? $this->options['timeout'] : 5;
        $host         = sprintf('%s:%d', $this->parsed['host'], $this->parsed['port']);

        if (true === $this->parsed['secured']) {
            $host = 'ssl://' . $host;
        }

        $this->handle = stream_socket_client($host, $errors[0], $errors[1], $timeout, STREAM_CLIENT_CONNECT | STREAM_CLIENT_PERSISTENT,
            stream_context_create($this->context));

        if (is_resource($this->handle)) {
            stream_set_timeout($this->handle, $timeout);
        } else {
            $this->errors = $errors;
        }
    }

    /**
     * Get errors.
     *
     * @return array
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * Get socket handle.
     *
     * @return resource
     */
    public function getHandle()
    {
        return $this->handle;
    }

    /**
     * Get parsed URL.
     *
     * @return string[]
     */
    public function getParsedUrl()
    {
        return $this->parsed;
    }

    /**
     * Get request content.
     *
     * @param string $uri
     * @param array $headers
     * @param array $options
     * @return boolean
     */
    public function request($uri, $headers = [], $options = [])
    {
        if (!is_resource($this->handle)) {
            return;
        }

        $eol = "\r\n";

        $method     = isset($options['method']) ? $options['method'] : 'GET';
        $skip_body  = isset($options['skip_body']) ? $options['skip_body'] : false;
        $payload    = isset($options['payload']) ? $options['payload'] : null;

        $request = array_merge([
            sprintf('%s %s HTTP/1.1', strtoupper($method), $uri),
            sprintf('Host: %s', $this->parsed['host']/* . ':' . $this->parsed['port']*/),
        ], $headers);
        $request = implode($eol, $request) . $eol . $eol . $payload;

        $this->send($request);

        $this->result = ['headers' => [], 'body' => null];

        // wait for response
        $header = true;
        while (false !== ($content = fgets($this->handle))) {
            if ($content === $eol && $header) {
                if (!$skip_body) {
                    $header = false;
                } else {
                    break;
                }
            } else {
                if ($header) {
                    $this->result['headers'][] = trim($content);
                } else {
                    $this->result['body'] .= $content;
                }
            }
        }

        return count($this->result['headers']) ? true : false;
    }

    /**
     * Get headers.
     *
     * @return array
     */
    public function getHeaders()
    {
        return is_array($this->result) ? $this->result['headers'] : null;
    }

    /**
     * Get body.
     *
     * @return string
     */
    public function getBody()
    {
        return is_array($this->result) ? $this->result['body'] : null;
    }

    /**
     * Get request status.
     *
     * @return string
     */
    public function getStatus()
    {
        if (count($headers = $this->getHeaders())) {
            return $headers[0];
        }
    }

    /**
     * Get status code.
     *
     * @return string
     */
    public function getStatusCode()
    {
        if ($status = $this->getStatus()) {
            list(, $code, ) = explode(' ', $status, 3);

            return $code;
        }
    }

    /**
     * Parse an url into parts we may expect
     *
     * @param string $url
     *
     * @return string[] information on the given URL
     */
    protected function parseUrl($url)
    {
        $parsed = parse_url($url);

        if (false === $parsed) {
          throw new MalformedUrlException($url);
        }

        $result = array_replace([
            'scheme' => 'http',
            'host'   => 'localhost',
            'query'  => []
        ], $parsed);

        if (!isset($result['port'])) {
            $result['port'] = 'https' === $result['scheme'] ? 443 : 80;
        }

        if (!isset($result['path']) || $result['path'] == '/') {
            $result['path'] = 'socket.io';
        }

        if (!is_array($result['query'])) {
            $query = null;
            parse_str($result['query'], $query);
            $result['query'] = $query;
        }

        $result['secured'] = 'https' === $result['scheme'];

        return $result;
    }

    /**
     * Send data.
     *
     * @param string $data
     * @return int
     */
    public function send($data)
    {
        if (is_resource($this->handle)) {
            return fwrite($this->handle, (string) $data);
        }
    }

    /**
     * Close socket.
     */
    public function close()
    {
        if (!is_resource($this->handle)) {
            return;
        }
        fclose($this->handle);
        $this->handle = null;
    }
}
