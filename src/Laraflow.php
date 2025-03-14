<?php

namespace laraflow;

use laraflow\Events\LaraflowEvents;
use laraflow\Events\LaraflowTransitionEvents;
use laraflow\Exceptions\LaraflowException;
use laraflow\Exceptions\LaraflowValidatorException;
use laraflow\Validator\LaraflowValidator;
use laraflow\Validator\LaraflowValidatorInterface;

class Laraflow implements LaraflowInterface
{
    /**
     * @var
     */
    protected $object;

    /**
     * Configuration array.
     */
    protected $configuration = [];

    /**
     * @var
     */
    protected $callbackFactory;

    /**
     * @var array
     */
    protected $validatorErrors = [];

    /**
     * Workflow constructor.
     *
     * @param $object
     * @param $configuration
     */
    public function __construct($object, array $configuration)
    {
        $this->object = $object;
        isset($configuration['property_path']) ?: $configuration['property_path'] = 'state';
        $this->configuration = $configuration;

        $this->getActualStep();
    }

    /**
     * Can the transition be applied on the underlying object.
     *
     * @param string $transition
     *
     * @return bool
     * @throws LaraflowException
     */
    public function can($transition)
    {
        if (! isset($this->configuration['transitions'][$transition])) {
            throw new LaraflowException(__('laraflow::exception.missing_transition', ['transition' => $transition]));
        }

        if (collect($this->configuration['transitions'])->where('from', $this->getActualStep())->isEmpty()) {
            return false;
        }

        if ($this->configuration['transitions'][$transition]['from'] != $this->getActualStep()) {
            throw new LaraflowException(__('laraflow::exception.invalid_transition', ['transition' => $transition]));
        }

        event(LaraflowEvents::CAN_TRANSITION, $this);

        return true;
    }

    /**
     * Applies the transition on the underlying object.
     *
     * @param string $transition Transition to apply
     *
     * @return void If the transition has been applied or not (in case of soft apply or rejected pre transition event)
     * @throws LaraflowException
     */
    public function apply($transition)
    {
        $this->can($transition);

        tap($this->setLaraflowEvent($transition), function ($event) use ($transition) {
            $this->firePreEvents($event)
                ->updateActualStep($this->configuration['transitions'][$transition]['to'])
                ->firePostEvents($event);
        });
    }

    /**
     * Returns the current state.
     *
     * @return string
     */
    public function getActualStep()
    {
        return $this->object->getAttribute($this->configuration['property_path']);
    }

    /**
     * Returns the underlying object.
     *
     * @return object
     */
    public function getObject()
    {
        return $this->object;
    }

    /**
     * Return the current configuration object.
     *
     * @return mixed
     */
    public function getConfiguration()
    {
        return $this->configuration;
    }

    /**
     * Return the possible transactions which are available from the
     * current state.
     *
     * @return array
     */
    public function getPossibleTransitions()
    {
        return collect($this->configuration['transitions'])
            ->where('from', $this->getActualStep())
            ->map(function ($value, $key) {
                return [
                    'key' => $key,
                    'text' => $value['text']
                ];
            })->toArray();
    }

    /**
     * Return the fieldname of the model this statemachine operates on.
     *
     * @return string
     */
    public function getStateField()
    {
        return $this->configuration['property_path'];
    }

    /**
     * Set a new state to the underlying object.
     *
     * @param string $step
     *
     * @return Laraflow
     * @throws SMException
     * @throws LaraflowException
     */
    protected function updateActualStep($step)
    {
        if (! array_key_exists($step, $this->configuration['steps'])) {
            throw new LaraflowException(__('laraflow::exception.missing_step', ['step' => $step]));
        }

        $this->object->setAttribute($this->configuration['property_path'], $step)->update();

        return $this;
    }

    /**
     * Fire all of the pre events before the status has been changed.
     *
     * @param $event
     * @return Laraflow
     */
    protected function firePreEvents($event)
    {
        event(LaraflowEvents::PRE_TRANSITION, $event);

        if (! $this->getValidators($event)) {
            throw LaraflowValidatorException::withMessages($this->validatorErrors);
        }
        $this->callCallbacks($event, 'pre');

        return $this;
    }

    /**
     * Fire all of the post events after the status has been changed.
     *
     * @param $event
     * @return Laraflow
     */
    protected function firePostEvents($event)
    {
        event(LaraflowEvents::POST_TRANSITION, $event);

        $this->callCallbacks($event, 'post');

        return $this;
    }

    /**
     * Function for the custom callback calls.
     *
     * @param $event
     * @param $position
     * @return bool
     */
    protected function callCallbacks($event, $position)
    {
        if (! isset($event->getConfig()['callbacks'][$position])) {
            report(new LaraflowException(__('laraflow::exception.missing_callback', ['callback' => $position])));

            return false;
        }

        foreach ($event->getConfig()['callbacks'][$position] as $key => &$callback) {
            if ((! class_exists($callback)) && (! $callback instanceof LaraflowCallbackInterface)) {
                report(new LaraflowException(__('laraflow::exception.missing_callback', ['callback' => $callback])));
                continue;
            }

            $app = new $callback();
            $app->handle($event);
        }
    }

    /**
     * Get the attribute validators before the status change and
     * validate the attribtutes.
     *
     * @param $event
     * @return bool
     */
    protected function getValidators($event)
    {
        if (! isset($event->getConfig()['validators'])) {
            return false;
        }

        foreach ($event->getConfig()['validators'] as $key => $rules) {
            $class = is_numeric($key) ? LaraflowValidator::class : $key;

            if ((! class_exists($class)) && (! $class instanceof LaraflowValidatorInterface)) {
                array_push($this->validatorErrors, [[__('laraflow::validation.missing_validator_class', ['class' => $class])]]);
                continue;
            }

            $result = $this->callValidators($class, $event->getStateMachine()->getObject()->getAttributes(), $rules);

            if ($result !== true) {
                array_push($this->validatorErrors, $result);
            }
        }

        return empty($this->validatorErrors);
    }

    /**
     * Decide to call the original Laravel validation,
     * or use the custom validator class by the user.
     *
     * @param $class
     * @param $attributes
     * @param $rules
     * @return mixed
     */
    protected function callValidators($class, $attributes, $rules)
    {
        foreach ($rules as $attribute => $rule) {
            if (array_key_exists($rule, config('laraflow')['custom_validators'])) {
                $class = config('laraflow')['custom_validators'][$rule]['validator'];
                break;
            }
        }

        $app = new $class();
        $result = $app->validate($attributes, $rules);

        return $result;
    }

    /**
     * Reutrn an event object for the custom events. The users
     * can reach the workflow attributes with this.
     *
     * @param $transition
     * @return LaraflowTransitionEvents
     */
    protected function setLaraflowEvent($transition)
    {
        return new LaraflowTransitionEvents($transition, $this->getActualStep(), $this->configuration['transitions'][$transition], $this);
    }
}