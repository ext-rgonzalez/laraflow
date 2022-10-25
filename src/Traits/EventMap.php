<?php

namespace laraflow\Traits;

use laraflow\Events\LaraflowEvents;
use laraflow\Listeners\LaraflowHistoryManager;

trait EventMap
{
    /**
     * All of the Laraflow event / listener mappings.
     *
     * @var array
     */
    protected $events = [
        LaraflowEvents::POST_TRANSITION => [
            LaraflowHistoryManager::class,
        ],

        LaraflowEvents::PRE_TRANSITION => [],

        LaraflowEvents::CAN_TRANSITION => []
    ];
}