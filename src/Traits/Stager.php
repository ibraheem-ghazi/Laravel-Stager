<?php
/**
 * Created by PhpStorm.
 * User: HP
 * Date: 1/30/2018
 * Time: 2:13 PM
 */

namespace IbraheemGhazi\Stager\Traits;

use \Illuminate\Database\Eloquent\Builder;
use IbraheemGhazi\Stager\Events\afterTransition;
use IbraheemGhazi\Stager\Events\beforeTransition;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

trait Stager
{

    use StagerCollectionSupport;
    
    /**
     *
     * current state machine defined for this model
     *
     * @var \Illuminate\Config\Repository|mixed
     */
    private static $stateMachine;

    /**
     * the name of column/attribute that store the current state of current model object
     * @var string
     */
    private static $stateAttrName;

    public static function bootStagerTrait()
    {
        static::initStager();
    }

     /**
     * initial stager functionality which will be called from bootIfNotBooted function
     */
    public static function initStager()
    {
        if (!static::$stateMachine) {
            static::$stateMachine = config('state-machine.' . get_class());
            if (static::hasValidStateMachine()) {
                static::$stateAttrName = array_get(static::$stateMachine, 'state-column', 'state');
                /**
                 * set the default column and attribute name for state
                 */
                static::creating(function($model){
                    /**
                     * set Default state
                     */
                    if (!$model->{static::$stateAttrName}) {
                        $ini_state = array_get(static::$stateMachine, 'init-state');
                        $first_state_key = array_get(array_keys(array_get(static::$stateMachine, 'states',[])),'0');
                        $ini_state_val = array_get(static::$stateMachine, 'states.' . $ini_state, array_get(static::$stateMachine, 'states.'.$first_state_key));
                        $model->{static::$stateAttrName} = $ini_state_val;
                    }
                });
            } else {
                trigger_error(__CLASS__ . ' does not have a valid defination for state-machine');
            }
        }
    }

    public static function getStateMachine()
    {
        return static::$stateMachine;
    }

    public static function getStateAttributeName()
    {
        return static::$stateAttrName;
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
        return $this->{static::$stateAttrName};
    }


    /**
     * return the name of current state <br><b><i>(be careful, don't set multiple states with same value)</i></b>
     * @return mixed
     */
    public function getCurrentStateName()
    {
        $states = array_flip(array_get(static::$stateMachine, 'states'));

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
    public static function hasValidStateMachine()
    {
        return is_array(static::$stateMachine);
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
        if (static::hasValidStateMachine()) {
            if($target_name = $this->checkValidMagicCall($name, 'is', true)){
                return $this->isStagerState($target_name);
            }elseif(($target_name = $this->checkValidMagicCall($name, 'can', false))){
                return $this->canStagerTransition($target_name);
            }elseif(($target_name = $this->checkValidMagicCall($name, 'do', false))){
                return $this->doStagerTransition($target_name, ...$arguments);
            }
        }else{

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
                    $isState && !is_null(array_get(static::$stateMachine, 'states.' . $target)) ||
                    //transition prefix (not state) and exist transition
                    !$isState && is_array(array_get(static::$stateMachine, 'transitions.' . $target))


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
    public function canStagerTransition(string $trans_name)
    {
        $trans = array_get(static::$stateMachine, 'transitions.' . kebab_case($trans_name));
        if (!is_array($trans) ||  !$this->checkGuardCanTransit($trans)) {
            return false;
        }

        $current_state = $this->getCurrentStateName();

        $valid_from = is_array(array_get($trans, 'from')) && in_array($current_state, array_get($trans, 'from')) || $current_state === array_get($trans, 'from');

        //relation status condition
        $relation_state_cond = array_get($trans,'relation-state-condition');

        if(is_array($relation_state_cond) && $valid_from){
            $this->loadMissing(array_keys($relation_state_cond));//allow load all required relation at once
            foreach ($relation_state_cond as $relation => $status){
                $can_trigger_error = config('state-machine.config.fail-throw-exception') === true;
                !method_exists($this,$relation) && $can_trigger_error && trigger_error("relation {$relation} not exists");
                !$this->relationLoaded($relation) && $this->load($relation);
                $related_class = get_class($this->$relation()->getRelated());
                $relation_state_machine = config('state-machine.'.$related_class);
                if(!is_array($relation_state_machine)){
                    $can_trigger_error && trigger_error("relation {$relation} of class {$related_class} does not have valid machine");
                    return false;
                }
                $relation_state_column = array_get($relation_state_machine, 'state-column');
                if($this->$relation->$relation_state_column!==$status){
                    return false;
                }
            }
            return true;
        }

        return $valid_from;

    }

    private function checkGuardCanTransit($transition_array){
        $guards = array_get($transition_array,'guard') ?: '*';
        if(is_array($guards)){
            foreach ($guards as $guard){
                if(auth($guard)->check()) return true;
            }
        }
        $value = $guards === '*';
        if(!$value && config('state-machine.config.unauthorized-gaurd-exception',true) === true) {
            throw new UnauthorizedHttpException('','you are not authorized to perform this action');
        }
        return $value;
    }

    /**
     *
     * execute the target transition
     *
     * @param string $trans_name the target transition name
     * @param array ...$args arguments you want to pass to Perform***Transition method
     * @return Object|bool
     */
    public function doStagerTransition($trans_name, ...$args)
    {

        $is_collection = array_get(static::$stateMachine, 'transitions.' . kebab_case($trans_name) . '.collection');
        if($is_collection){
            if (config('state-machine.config.fail-throw-exception') === true) {
                trigger_error("current transition [" . kebab_case($trans_name) . "] does not support single element update.");
            } else {
                return false;
            }
        }

        if ($this->canStagerTransition($trans_name)) {
            $callable_name = 'pre' . studly_case($trans_name) . 'Transition';

            if (!method_exists($this, $callable_name) || $this->{$callable_name}(...$args)) {
                $new_state = array_get(static::$stateMachine, 'transitions.' . kebab_case($trans_name) . '.to');
                if (!$new_state) {
                    trigger_error('state [' . kebab_case($trans_name) . '] not exists at [' . __CLASS__ . ']');
                }

                $old_state = $this->getCurrentStateName();

                event(new beforeTransition($this,null, $trans_name, $old_state, $new_state));

                $target_key = $this->getKeyName();

                static::updateStagerState($new_state,$this->{$target_key});

                $affections = array_get(static::$stateMachine, 'transitions.' . kebab_case($trans_name) . '.affect', []);

                foreach ($affections as $relation => $transition) {
                    $callable_name = 'do' . studly_case($transition);
                    if($this->relationLoaded($relation) || $this->load($relation)) {
                       is_null($this->{$relation}->each) ?
                           $this->{$relation}->{$callable_name}() :
                           $this->{$relation}->each->{$callable_name}();
                    }else{
                        if (config('state-machine.config.fail-throw-exception') === true) {
                            trigger_error("relation \"{$relation}\" does not exists or not loaded at [".__CLASS__."]");
                        }
                    }
                }
                event(new afterTransition($this, null, $trans_name, $old_state, $new_state));

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
    public function isStagerState($state)
    {
        return array_get(static::$stateMachine, 'states.' . kebab_case($state)) === $this->getCurrentStateValue();
    }

    /**
     *
     * update the state column/attribute to new state value by state name
     *
     * @param string $state the new state to be updated
     * @param array|int $target_ids the target model id's which will be updated
     */
    private static function updateStagerState($state,$target_ids)
    {
        $state_value = array_get(static::$stateMachine, 'states.' . kebab_case($state));
        if (!$state_value) {
            if (config('state-machine.config.fail-throw-exception') === true) {
                trigger_error('state is not allowed as NULL [' . __CLASS__ . ' => states => ' . kebab_case($state) . ']');
            }else{
                return false;
            }
        }
        static::unguarded(function()use($state_value,$target_ids){
            $target_ids instanceof \Illuminate\Support\Collection && $target_ids = $target_ids->toArray();
            if(is_array($target_ids)){
                static::whereIn((new static)->getKeyName(),$target_ids)->update([static::$stateAttrName=>$state_value]);
            }else{
                static::where((new static)->getKeyName(),$target_ids)->update([static::$stateAttrName=>$state_value]);
            }
        });
    }

}
