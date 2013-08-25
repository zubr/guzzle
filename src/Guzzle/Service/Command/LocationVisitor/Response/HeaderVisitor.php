<?php

namespace Guzzle\Service\Command\LocationVisitor\Response;

use Guzzle\Http\Message\Response;
use Guzzle\Service\Description\Parameter;
use Guzzle\Service\Command\CommandInterface;

/**
 * Location visitor used to add a particular header of a response to a key in the result
 */
class HeaderVisitor extends AbstractResponseVisitor
{
    public function visit(
        CommandInterface $command,
        Response $response,
        Parameter $param,
        &$value,
        $context = null
    ) {
        $name = $param->getName();
        if ($header = $response->getHeader($param->getWireName())) {
            $value[$name] = $param->filter((string) $header);
        }

        // Handle additional, undefined headers
        $additional = $param->getAdditionalProperties();

        if ($additional === null || $additional === true) {
            // Process all headers with main schema
            $this->processAllHeaders($response, $param, $value);
        } elseif ($additional instanceof Parameter) {
            if ($prefix = $param->getSentAs()) {
                // Process prefixed headers
                $this->processPrefixedHeaders($prefix, $response, $param, $value);
            } else {
                // Process all headers with the additionalProperties schema
                $this->processAllHeaders($response, $additional, $value);
            }
        }
    }

    /**
     * Process a prefixed header array
     *
     * @param string    $prefix   Header prefix to use
     * @param Response  $response Response that contains the headers
     * @param Parameter $param    Parameter object
     * @param array     $value    Value response array to modify
     */
    private function processPrefixedHeaders($prefix, Response $response, Parameter $param, &$value)
    {
        // Grab prefixed headers that should be placed into an array with the prefix stripped
        $container = $param->getName();
        $len = strlen($prefix);

        // Find all matching headers and place them into the containing element
        foreach ($response->getHeaders()->toArray() as $key => $header) {
            if (stripos($key, $prefix) === 0) {
                // Account for multi-value headers
                $value[$container][substr($key, $len)] = count($header) == 1 ? end($header) : $header;
            }
        }
    }

    /**
     * Process a header array
     *
     * @param Response  $response Response that contains the headers
     * @param Parameter $param    Parameter object
     * @param array     $value    Value response array to modify
     */
    private function processAllHeaders(Response $response, Parameter $param, &$value)
    {
        foreach ($response->getHeaders()->toArray() as $key => $header) {
            $value[$key] = $param->filter(count($header) == 1 ? end($header) : $header);
        }
    }
}
