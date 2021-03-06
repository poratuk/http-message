<?php

namespace Jasny\HttpMessage\Message;

use Jasny\HttpMessage\HeadersInterface;
use Jasny\HttpMessage\Headers as HeadersObject;

/**
 * Implementation of the PSR-7 MessageInterface header methods
 */
trait Headers
{
    /**
     * HTTP headers
     *
     * @var HeadersInterface
     */
    protected $headers;
    
    
    /**
     * Get or set HTTP headers object
     * 
     * @param HeadersInterface $headers
     * @return HeadersInterface
     */
    final protected function headersObject(HeadersInterface $headers = null)
    {
        if (func_num_args() >= 1) {
            $this->headers = $headers;
        }
        
        return $this->headers;
    }
    
    
    /**
     * Determine the headers based on other information
     * 
     * @return array headers array with structure $key => [$value, ...]
     */
    protected function determineHeaders()
    {
        return [];
    }

    /**
     * Public function to create header object 
     * 
     */
    public function initHeaders()
    {
        if (!isset($this->headers)) {
            $this->headers = new HeadersObject($this->determineHeaders());
        }
    }

    /**
     * Retrieves all message header values.
     *
     * The keys represent the header name as it will be sent over the wire, and
     * each value is an array of strings associated with the header.
     *
     *     // Represent the headers as a string
     *     foreach ($message->getHeaders() as $name => $values) {
     *         echo $name . ": " . implode(", ", $values);
     *     }
     *
     *     // Emit headers iteratively:
     *     foreach ($message->getHeaders() as $name => $values) {
     *         foreach ($values as $value) {
     *             header(sprintf('%s: %s', $name, $value), false);
     *         }
     *     }
     *
     * While header names are not case-sensitive, getHeaders() will preserve the
     * exact case in which headers were originally specified.
     *
     * @return string[][] Returns an associative array of the message's headers. Each
     *     key MUST be a header name, and each value MUST be an array of strings
     *     for that header.
     */
    public function getHeaders()
    {
        $this->initHeaders();
        return $this->headers->getHeaders();
    }

    /**
     * Checks if a header exists by the given case-insensitive name.
     *
     * @param string $name
     *            Case-insensitive header field name.
     * @return bool Returns true if any header names match the given header
     *         name using a case-insensitive string comparison. Returns false if
     *         no matching header name is found in the message.
     */
    public function hasHeader($name)
    {
        $this->initHeaders();
        return $this->headers->hasHeader($name);
    }

    /**
     * Retrieves a comma-separated string of the values for a single header.
     *
     * This method returns all of the header values of the given
     * case-insensitive header name as a string concatenated together using
     * a comma.
     *
     * NOTE: Not all header values may be appropriately represented using
     * comma concatenation. For such headers, use getHeader() instead
     * and supply your own delimiter when concatenating.
     *
     * @param string $name Case-insensitive header field name.
     * @return string A string of values as provided for the given header
     *    concatenated together using a comma. If the header does not appear in
     *    the message, this method returns an empty string.
     */
    public function getHeaderLine($name)
    {
        $this->initHeaders();
        return $this->headers->getHeaderLine($name);
    }

    /**
     * Retrieves a message header value by the given case-insensitive name.
     *
     * This method returns an array of all the header values of the given
     * case-insensitive header name.
     *
     * @param string $name
     *            Case-insensitive header field name.
     * @return string[] An array of string values as provided for the given
     *         header. If the header does not appear in the message, this method MUST
     *         return an empty array.
     */
    public function getHeader($name)
    {
        $this->initHeaders();
        return $this->headers->getHeader($name);
    }

    /**
     * Return an instance with the provided value replacing the specified header.
     *
     * While header names are case-insensitive, the casing of the header will
     * be preserved by this function, and returned from getHeaders().
     *
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return an instance that has the
     * new and/or updated header and value.
     *
     * @param string $name Case-insensitive header field name.
     * @param string|string[] $value Header value(s).
     * @return static
     * @throws \InvalidArgumentException for invalid header names or values.
     */
    public function withHeader($name, $value)
    {
        $this->initHeaders();
        
        $clone = clone $this;
        $clone->headers = $this->headers->withHeader($name, $value);
        
        return $clone;
    }

    /**
     * Return an instance with the specified header appended with the given value.
     *
     * Existing values for the specified header will be maintained. The new
     * value(s) will be appended to the existing list. If the header did not
     * exist previously, it will be added.
     *
     * @param string $name Case-insensitive header field name to add.
     * @param string|string[] $value Header value(s).
     * @return static
     * @throws \InvalidArgumentException for invalid header names.
     * @throws \InvalidArgumentException for invalid header values.
     */
    public function withAddedHeader($name, $value)
    {
        $this->initHeaders();
        
        $clone = clone $this;
        $clone->headers = $this->headers->withAddedHeader($name, $value);
        
        return $clone;
    }

    /**
     * Return an instance without the specified header.
     *
     * @param string $name Case-insensitive header field name to remove.
     * @return static
     */
    public function withoutHeader($name)
    {
        $this->initHeaders();
        
        if ($this->headers->hasHeader($name)) {
            $clone = clone $this;
            
            $clone->headers = $this->headers->withoutHeader($name);
            return $clone;
        }
        
        return $this;
    }
    
    /**
     * Turn upper case param into header case.
     * (SOME_HEADER -> Some-Header)
     * 
     * @param string $param
     * @return string
     */
    protected function headerCase($param)
    {
        $sentence = preg_replace('/[\W_]+/', ' ', $param);
        return str_replace(' ', '-', ucwords(strtolower($sentence)));
    }
}
