<?php

namespace laraflow\Events;

abstract class LaraflowEvents
{
    const PRE_TRANSITION = 'laraflow.pre_transition';

    const POST_TRANSITION = 'laraflow.post_transition';

    const CAN_TRANSITION = 'laraflow.can_transition';
}
