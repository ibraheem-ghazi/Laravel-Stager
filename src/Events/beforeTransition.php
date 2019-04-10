<?php

namespace IbraheemGhazi\Stager\Events;

use Illuminate\Database\Eloquent\Collection;
use \Illuminate\Database\Eloquent\Model;

class beforeTransition
{

    public $previousState;

    public $currentState;

    public $fromTransition;

    public $model;

    public $collection;

    public function __construct(?Model $model,?Collection $collection, $fromTransition, $previousState, $currentState)
    {
        $this->currentState = $currentState;
        $this->previousState = $previousState;
        $this->fromTransition = $fromTransition;
        $this->model = $model;
        $this->collection = $collection;
    }
}