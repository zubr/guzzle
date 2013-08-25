<?php

namespace Guzzle\Service\Command\LocationVisitor\Response;

use Guzzle\Http\Message\Response;
use Guzzle\Service\Description\Parameter;
use Guzzle\Service\Command\CommandInterface;

/**
 * Location visitor used to marshal JSON response data into a formatted array.
 *
 * Allows top level JSON parameters to be inserted into the result of a command. The top level attributes are grabbed
 * from the response's JSON data using the name value by default. Filters can be applied to parameters as they are
 * traversed. This allows data to be normalized before returning it to users (for example converting timestamps to
 * DateTime objects).
 */
class JsonVisitor extends AbstractResponseVisitor
{
    /** @var array The JSON document being visited */
    protected $json = array();

    public function before(CommandInterface $command, array &$result)
    {
        $this->json = $command->getResponse()->json();
    }

    public function after(CommandInterface $command)
    {
        // Free up memory
        $this->json = array();
    }

    public function visit(
        CommandInterface $command,
        Response $response,
        Parameter $param,
        &$value,
        $context = null
    ) {
        $name = $param->getName();
        $key = $param->getWireName();

        // Check if the result should be treated as a list
        if ($param->getType() == 'array' && ($context || !$key || $param->getSentAs() === '')) {
            // Treat as javascript array
            if ($context || !$name) {
                // top-level `array` or an empty name
                $value = array_merge($value, $this->recursiveProcess($param, $this->json));
            } else {
                // name provided, store it under a key in the array
                $value[$name] = $this->recursiveProcess($param, $this->json);
            }
        } elseif (isset($this->json[$key])) {
            // Treat as a javascript object
            if (!$name) {
                $value += $this->recursiveProcess($param, $this->json[$key]);
            } else {
                $value[$name] = $this->recursiveProcess($param, $this->json[$key]);
            }
        }

        // Handle additional, undefined properties
        $additional = $param->getAdditionalProperties();

        if ($additional === null || $additional === true) {
            // Blindly merge the JSON into resulting array skipping the already processed property
            $json = $this->json;
            unset($json[$key]);
            $value += $json;
        } elseif ($additional instanceof Parameter) {
            // Process all child elements according to the given schema
            foreach ($this->json as $prop => $val) {
                $value[$prop] = $this->recursiveProcess($additional, $val);
            }
        }
    }

    /**
     * Recursively process a parameter while applying filters
     *
     * @param Parameter $param API parameter being validated
     * @param mixed     $value Value to validate and process. The value may change during this process.
     * @return mixed|null
     */
    protected function recursiveProcess(Parameter $param, $value)
    {
        if ($value === null) {
            return null;
        } elseif (!is_array($value)) {
            // Scalar values don't need to be walked
            return $param->filter($value);
        }

        $result = array();
        $type = $param->getType();
        if ($type == 'array') {
            $items = $param->getItems();
            foreach ($value as $val) {
                $result[] = $this->recursiveProcess($items, $val);
            }
        } elseif ($type == 'object' && !isset($value[0])) {
            // On the above line, we ensure that the array is associative and not numerically indexed
            if ($properties = $param->getProperties()) {
                foreach ($properties as $property) {
                    $key = $property->getWireName();
                    if (isset($value[$key])) {
                        $result[$property->getName()] = $this->recursiveProcess($property, $value[$key]);
                        // Remove from the value so that AP can later be handled
                        unset($value[$key]);
                    }
                }
            }
            // Only check additional properties if everything wasn't already handled
            if ($value) {
                $additional = $param->getAdditionalProperties();
                if ($additional === null || $additional === true) {
                    // Blindly merge the JSON into resulting array skipping the already processed property
                    $result += $value;
                } elseif ($additional instanceof Parameter) {
                    // Process all child elements according to the given schema
                    foreach ($value as $prop => $val) {
                        $result[$prop] = $this->recursiveProcess($additional, $val);
                    }
                }
            }
        }

        return $param->filter($result);
    }
}
