<?php

namespace Guzzle\Service\Command;

use Guzzle\Http\Message\Response;
use Guzzle\Service\Command\LocationVisitor\VisitorFlyweight;
use Guzzle\Service\Command\LocationVisitor\Response\ResponseVisitorInterface;
use Guzzle\Service\Description\Parameter;
use Guzzle\Service\Description\OperationInterface;
use Guzzle\Service\Description\Operation;
use Guzzle\Service\Exception\ResponseClassException;
use Guzzle\Service\Resource\Model;

/**
 * Response parser that attempts to marshal responses into an associative array based on models in a service description
 */
class OperationResponseParser extends DefaultResponseParser
{
    /** @var VisitorFlyweight $factory Visitor factory */
    protected $factory;

    /** @var self */
    protected static $instance;

    /**
     * @return self
     * @codeCoverageIgnore
     */
    public static function getInstance()
    {
        if (!static::$instance) {
            static::$instance = new static(VisitorFlyweight::getInstance());
        }

        return static::$instance;
    }

    /**
     * @param VisitorFlyweight $factory Factory to use when creating visitors
     */
    public function __construct(VisitorFlyweight $factory)
    {
        $this->factory = $factory;
    }

    /**
     * Add a location visitor to the command
     *
     * @param string                   $location Location to associate with the visitor
     * @param ResponseVisitorInterface $visitor  Visitor to attach
     *
     * @return self
     */
    public function addVisitor($location, ResponseVisitorInterface $visitor)
    {
        $this->factory->addResponseVisitor($location, $visitor);

        return $this;
    }

    protected function handleParsing(CommandInterface $command, Response $response, $contentType)
    {
        $operation = $command->getOperation();
        $type = $operation->getResponseType();
        $model = null;

        if ($type == OperationInterface::TYPE_MODEL) {
            $model = $operation->getServiceDescription()->getModel($operation->getResponseClass());
        } elseif ($type == OperationInterface::TYPE_CLASS) {
            return $this->parseClass($command);
        }

        if (!$model) {
            // Return basic processing if the responseType is not model or the model cannot be found
            return parent::handleParsing($command, $response, $contentType);
        } elseif ($command[AbstractCommand::RESPONSE_PROCESSING] != AbstractCommand::TYPE_MODEL) {
            // Returns a model with no visiting if the command response processing is not model
            return new Model(parent::handleParsing($command, $response, $contentType), $model);
        } else {
            return new Model($this->visitResult($model, $command, $response), $model);
        }
    }

    /**
     * Parse a class object
     *
     * @param CommandInterface $command Command to parse into an object
     *
     * @return mixed
     * @throws ResponseClassException
     */
    protected function parseClass(CommandInterface $command)
    {
        // Emit the operation.parse_class event. If a listener injects a 'result' property, then that will be the result
        $event = new CreateResponseClassEvent(array('command' => $command));
        $command->getClient()->getEventDispatcher()->dispatch('command.parse_response', $event);
        if ($result = $event->getResult()) {
            return $result;
        }

        $className = $command->getOperation()->getResponseClass();
        if (!method_exists($className, 'fromCommand')) {
            throw new ResponseClassException("{$className} must exist and implement a static fromCommand() method");
        }

        return $className::fromCommand($command);
    }

    /**
     * Perform transformations on the result array
     *
     * @param Parameter        $model    Model that defines the structure
     * @param CommandInterface $command  Command that performed the operation
     * @param Response         $response Response received
     *
     * @return array Returns the array of result data
     */
    protected function visitResult(Parameter $model, CommandInterface $command, Response $response)
    {
        $foundVisitors = $result = array();

        if ($model->getType() == 'object') {
            $this->visitOuterObject($model, $command, $response, $result, $foundVisitors);
        } elseif ($model->getType() == 'array') {
            $this->visitOuterArray($model, $command, $response, $result, $foundVisitors);
        }

        // Call the after() method of each found visitor
        foreach ($foundVisitors as $visitor) {
            $visitor->after($command);
        }

        return $result;
    }

    private function visitOuterObject(
        Parameter $model,
        CommandInterface $command,
        Response $response,
        &$result,
        &$foundVisitors
    ) {
        // Use 'location' from all individual defined properties
        foreach ($model->getProperties() as $schema) {
            if ($location = $schema->getLocation()) {
                // Trigger the before method on the first found visitor of this type
                if (!isset($foundVisitors[$location])) {
                    $foundVisitors[$location] = $this->factory->getResponseVisitor($location);
                    $foundVisitors[$location]->before($command, $result);
                }
            }
        }

        // If top-level additionalProperties is a schema, use it together with main schema
        if (($additional = $model->getAdditionalProperties()) instanceof Parameter) {
            $this->visitAdditionalProperties($model, $command, $response, $result, $foundVisitors);
        }

        $knownProps = array();
        // Visit items for each defined property
        foreach ($model->getProperties() as $schema) {
            $knownProps[$schema->getName()] = 1;
            if ($location = $schema->getLocation()) {
                $foundVisitors[$location]->visit($command, $response, $schema, $result);
            }
        }

        // Remove any unknown and potentially unsafe top-level properties
        if ($additional === false) {
            $result = array_intersect_key($result, $knownProps);
        }
    }

    /**
     * Visits the additional properties of an outer object. Additional properties are handled in location visitors by
     * setting the sentAs and name to null, making the visitor not match a named property. However, the visitor then
     * checks for additionalProperties which are then visited based on the schema.
     *
     * @param Parameter $model
     * @param CommandInterface $command
     * @param Response $response
     * @param $result
     * @param $foundVisitors
     */
    private function visitAdditionalProperties(
        Parameter $model,
        CommandInterface $command,
        Response $response,
        &$result,
        &$foundVisitors
    ) {
        if (!($location = $model->getAdditionalProperties()->getLocation())) {
            return;
        }

        if (!isset($foundVisitors[$location])) {
            $foundVisitors[$location] = $this->factory->getResponseVisitor($location);
            $foundVisitors[$location]->before($command, $result);
        }

        // Remove the main model name from schema definition so it doesn't try to match any particular property...
        $oldWireName = $model->getWireName();
        $oldName = $model->getName();
        $model->setSentAs(null)->setName(null);
        // Run the visitor against main schema and allow it visit all undefined properties
        $foundVisitors[$location]->visit($command, $response, $model, $result, true);
        // Restore names
        $model->setSentAs($oldWireName)->setName($oldName);
    }

    private function visitOuterArray(
        Parameter $model,
        CommandInterface $command,
        Response $response,
        &$result,
        &$foundVisitors
    ) {
        // Use 'location' defined on the top of the model
        if ($location = $model->getLocation()) {
            if (!isset($foundVisitors[$location])) {
                $foundVisitors[$location] = $this->factory->getResponseVisitor($location);
                $foundVisitors[$location]->before($command, $result);
            }
        }

        // Use 'location' from items schema
        $items = $model->getItems();
        if ($items instanceof Parameter && ($location = $items->getLocation())) {
            // Trigger the before method on the first found visitor of this type
            if (!isset($foundVisitors[$location])) {
                $foundVisitors[$location] = $this->factory->getResponseVisitor($location);
                $foundVisitors[$location]->before($command, $result);
            }
        }

        if ($items = $model->getItems()) {
            // Visit if items or the model have a location
            if ($location = $items->getLocation() ?: $model->getLocation()) {
                // Visit items of a top-level array
                $foundVisitors[$location]->visit($command, $response, $model, $result, true);
            }
        }
    }
}
