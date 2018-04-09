<?php

namespace IbraheemGhazi\Stager\Events;

use \Illuminate\Database\Eloquent\Model;

class afterTransition
{

    public $previousState;

    public $currentState;

    public $fromTransition;

    public $model;

    public function __construct(Model $model, $fromTransition, $previousState, $currentState)
    {
        $this->currentState = $currentState;
        $this->previousState = $previousState;
        $this->fromTransition = $fromTransition;
        $this->model = $model;
    }
}