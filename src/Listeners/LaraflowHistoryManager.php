<?php

namespace laraflow\Listeners;

use laraflow\Events\LaraflowTransitionEvents;
use laraflow\LaraflowCallbackInterface;

class LaraflowHistoryManager implements LaraflowCallbackInterface
{
    /**
     * Handle the event.
     *
     * @param LaraflowTransitionEvents $event
     * @return void
     */
    public function handle(LaraflowTransitionEvents $event)
    {
        $sm = $event->getStateMachine();
        $model = $sm->getObject();

        $model->addHistoryLine([
            'field' => $sm->getStateField(),
            'transition' => $event->getTransition(),
            'to' => $sm->getActualStep()
        ]);
    }
}
