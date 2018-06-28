<?php
/**
 * Created by PhpStorm.
 * User: HP
 * Date: 1/30/2018
 * Time: 2:13 PM
 */

namespace IbraheemGhazi\Stager\Traits;


use IbraheemGhazi\Stager\Events\afterTransition;
use IbraheemGhazi\Stager\Events\beforeTransition;

trait Stager
{

    /**
     *
     * current state machine defined for this model
     *
     * @var \Illuminate\Config\Repository|mixed
     */
    private $stateMachine;

    /**
     * the name of column/attribute that store the current state of current model object
     * @var string
     */
    private $stateAttrName;


   /**
     * auto boot stager trait.
     *
     * @return void
     */
    public static function bootStager()
    {
        static::addGlobalScope('stager',function(Builder $builder){
            $builder->initStager();
        });
    }

     /**
     * initial stager functionality which will be called from bootIfNotBooted function
     */
    public function scopeInitStager(Builder $builder)
    {

        if (!$this->stateMachine) {

            $this->stateMachine = config('state-machine.' . get_class());
            if ($this->hasValidStateMachine()) {


                $this->stateAttrName = array_get($this->stateMachine, 'state-column', 'state');

                /**
                 * set the default column and attribute name for state
                 */
                $this->attributes[] = $this->stateAttrName;

                /**
                 * set Default state
                 */
                if (!$this->{$this->stateAttrName}) {
                    $ini_state = array_get($this->stateMachine, 'init-state');

                    $ini_state_val = array_get($this->stateMachine, 'states.' . $ini_state, array_get($this->stateMachine, 'states.0'));

                    $this->{$this->stateAttrName} = $ini_state_val;
                }

            } else {
                trigger_error(__CLASS__ . ' does not have a valid defination for state-machine');
            }
        }
    }


    /**
     * @param $query
     * @param $modifier sql modifier = DAY,,
     * @param $interval
     */
    public function scopeStateChangeWithin($query, $modifier, $interval)
    {
        $query->whereRaw("TIMESTAMPDIFF(" . strtoupper($modifier) . ",NOW(),`state_changed_at`) <= -" . abs(intval($interval)));
    }

    /**
     * return the date where last state changed
     * @return mixed
     */
    public function getStateChangedAt()
    {
        return $this->state_changed_at;
    }


    /**
     *
     * return current state value
     *
     * @return mixed
     */
    public function getCurrentStateValue()
    {
        return $this->{$this->stateAttrName};
    }


    /**
     * return the name of current state <br><b><i>(be careful, don't set multiple states with same value)</i></b>
     * @return mixed
     */
    public function getCurrentStateName()
    {
        $states = array_flip(array_get($this->stateMachine, 'states'));

        return array_get($states, $this->getCurrentStateValue());
    }


    /**
     *
     * check if this model has valid state machine or not<br>
     * it must be defined at config/state-machine.php : <br>
     *
     * <code>
     *  \App\Model::class =>[
     *          'states' => [
     *              'pending' =>1,
     *              'in-progress'=>2,
     *              'finished' => 3,
     *          ],
     *      'transitions' => [
     *          ...
     *      ],
     *      'schedules' => [
     *          ...
     *      ]
     *  ]
     * </code>
     *
     *
     * @return bool
     */
    public function hasValidStateMachine()
    {
        return is_array($this->stateMachine);
    }

    /**
     *
     * execute magic callable functions
     *
     * @param $name magic callable name
     * @param $arguments arguments passed it magic callable
     * @return bool|void
     */
    public function __call($name, $arguments)
    {
        if ($this->hasValidStateMachine()) {

            foreach (['is' => true, 'can' => false, 'do' => false] as $prefix => $isState) {
                if ($target_name = $this->checkValidMagicCall($name, $prefix, $isState)) {
                    switch ($prefix) {
                        case 'is':
                            return $this->isStagerState($target_name);
                        case 'can':
                            return $this->canStagerTransition($target_name);
                        case 'do':
                            return $this->doStagerTransition($target_name, ...$arguments);
                    }
                }
            }
        }
        return parent::__call($name, $arguments);
    }

    /**
     * check if magic callable is valid and return actual name or false when failed
     *
     * @param $name name of magic callable from  __call
     * @param $prefix the prefix of magic function which will determin it's functionality
     * @param bool $isState is this function directly related to state or transition
     * @return bool|mixed return name of tranist or state in kebab-state or FALSE when failed
     */
    private function checkValidMagicCall($name, $prefix, $isState = false)
    {

        if (starts_with(kebab_case($name), strtolower($prefix))) {

            $target = str_replace(strtolower($prefix) . '-', '', kebab_case($name));

            $callPattern = strtolower($prefix) . studly_case($target);

            if ($callPattern === $name) {
                if (
                    //state prefix and exist state
                    $isState && !is_null(array_get($this->stateMachine, 'states.' . $target)) ||
                    //transition prefix (not state) and exist transition
                    !$isState && is_array(array_get($this->stateMachine, 'transitions.' . $target))


                ) {
                    return $target;
                }
            }

        }

        return false;

    }

    /**
     * check if this model can do target transition from current state or not
     *
     * @param string $trans_name the target transition name
     * @return bool return TRUE when model can execute target transition or FALSE when can not
     */
    private function canStagerTransition(string $trans_name)
    {
        $trans = array_get($this->stateMachine, 'transitions.' . kebab_case($trans_name));
        if (!is_array($trans)) {
            return false;
        }

        $current_state = $this->getCurrentStateName();


        $valid_from = is_array(array_get($trans, 'from')) && in_array($current_state, array_get($trans, 'from')) || $current_state === array_get($trans, 'from');

        return $valid_from;

    }

    /**
     *
     * execute the target transition
     *
     * @param $trans_name the target transition name
     * @param array ...$args arguments you want to pass to Perform***Transition method
     * @return void
     */
    private function doStagerTransition($trans_name, ...$args)
    {
        if ($this->canStagerTransition($trans_name)) {
            $callable_name = 'pre' . studly_case($trans_name) . 'Transition';

            if (!method_exists($this, $callable_name) || $this->{$callable_name}(...$args)) {
                $new_state = array_get($this->stateMachine, 'transitions.' . kebab_case($trans_name) . '.to');
                if (!$new_state) {
                    trigger_error('state [' . kebab_case($trans_name) . '] not exists at [' . __CLASS__ . ']');
                }

                $old_state = $this->getCurrentStateName();

                event(new beforeTransition($this, $trans_name, $old_state, $new_state));

                $this->updateStagerState($new_state);

                $affections = array_get($this->stateMachine, 'transitions.' . kebab_case($trans_name) . '.affect', []);

                foreach ($affections as $relation => $transition) {
                    $callable_name = 'do' . studly_case($transition);
                    if($this->relationLoaded($relation)) {
                        $this->{$relation}->each->{$callable_name}();
                    }else{
                        if (config('state-machine.config.fail-throw-exception') === true) {
                            trigger_error("relation \"{$relation}\" does not exists or not loaded at [".__CLASS__."]");
                        }
                    }
                }
                event(new afterTransition($this, $trans_name, $old_state, $new_state));

                return $this;
            }
        }
        if (config('state-machine.config.fail-throw-exception') === true) {
            trigger_error("can not perform transition [" . kebab_case($trans_name) . "] from current state [" . $this->getCurrentStateName() . "] for [" . __CLASS__ . "=>id:" . $this->id . "]");
        } else {
            return false;
        }
    }


    /**
     *
     * check if current state is equal to given $state
     *
     * @param $state the state you want to check
     * @return bool
     */
    private function isStagerState($state)
    {
        return array_get($this->stateMachine, 'states.' . kebab_case($state)) === $this->getCurrentStateValue();
    }

    /**
     *
     * update the state column/attribute to new state value by state name
     *
     * @param $state the new state to be updated
     */
    private function updateStagerState($state)
    {

        $state_value = array_get($this->stateMachine, 'states.' . kebab_case($state));
        if (!$state_value) {
            if (config('state-machine.config.fail-throw-exception') === true) {
                trigger_error('state is not allowed as NULL [' . __CLASS__ . ' => states => ' . kebab_case($state) . ']');
            }else{
                return false;
            }
        }
        $this->{$this->stateAttrName} = $state_value;
        $this->save();
//         $this->update($this->stateAttrName,$state_value);

    }

}
