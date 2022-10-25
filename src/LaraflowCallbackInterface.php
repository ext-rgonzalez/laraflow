<?php

namespace laraflow;

use laraflow\Events\LaraflowTransitionEvents;

interface LaraflowCallbackInterface
{
    public function handle(LaraflowTransitionEvents $event);
}