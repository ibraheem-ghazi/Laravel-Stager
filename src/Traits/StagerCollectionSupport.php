<?php
/**
 * Created by PhpStorm.
 * User: ibraheem
 * Date: 4/10/19
 * Time: 11:34 AM
 */

namespace IbraheemGhazi\Stager\Traits;

use IbraheemGhazi\Stager\Events\afterTransition;
use IbraheemGhazi\Stager\Events\beforeTransition;

trait StagerCollectionSupport
{

    public static function canStagerTransitionCollection($collection,$trans_name,$return_array=false)
    {
        $invalid_models = [];
        $collection->each(function($item)use($trans_name,&$invalid_models){
            $item->canStagerTransition($trans_name) || ($invalid_models[] = $item->id);
        });
        if (count($invalid_models) && config('state-machine.config.fail-throw-exception') === true) {
            trigger_error("models of [".implode(', ',$invalid_models)."] statuses is not valid");
        }
        return $return_array ? $invalid_models : empty($invalid_models);
    }

    /**
     * @param $collection
     * @param $trans_name
     * @param mixed ...$args
     * @return bool
     * @throws \Exception
     */
    public static function doStagerTransitionCollection($collection,$trans_name, ...$args)
    {
        if(!$collection->count()) return false;

        if(count(array_get(static::$stateMachine, 'transitions.' . kebab_case($trans_name) . '.affect', []))){
            throw new \Exception('Stager does not support transitions affect on collections');
        }

        $is_collection = array_get(static::$stateMachine, 'transitions.' . kebab_case($trans_name) . '.collection');
        if(!$is_collection){
            if (config('state-machine.config.fail-throw-exception') === true) {
                trigger_error("current transition [" . kebab_case($trans_name) . "] does not support collection ");
            } else {
                return false;
            }
        }

        if (static::canStagerTransitionCollection($collection,$trans_name)) {
            // get first item in collection as master model
            // to check for things that are shared through
            // model itself not instance (row)
            $model = $collection->first();
            $collection_callable_name = 'pre' . studly_case($trans_name) . 'TransitionCollection';
            $has_collection_method = method_exists($model, $collection_callable_name) ;
            $model_callable_name = 'pre' . studly_case($trans_name) . 'Transition';
            $has_model_method = method_exists($model, $model_callable_name) ;
            if(
               (!$has_collection_method || $model->{$collection_callable_name}($collection, ...$args))
            && (!$has_model_method || $collection->map->{$model_callable_name}(...$args)->filter(function($item){return $item;})->count())
            ){
                $new_state = array_get(static::$stateMachine, 'transitions.' . kebab_case($trans_name) . '.to');
                if (!$new_state) {
                    trigger_error('state [' . kebab_case($trans_name) . '] not exists at [' . get_class($model) . ']');
                }
                $old_statuses = $collection->pluck('status','id');
                 event(new beforeTransition(null,$collection, $trans_name, $old_statuses, $new_state));
                static::updateStagerState($new_state,$collection->pluck('id'));
                 event(new afterTransition(null,$collection, $trans_name, $old_statuses, $new_state));


                return $collection;
            }
        }
        if (config('state-machine.config.fail-throw-exception') === true) {
            trigger_error("can not perform collection transition [" . kebab_case($trans_name) . "] from current state [" . $model->getCurrentStateName() . "] for [" . __CLASS__ . "=>id's: [" . $collection->pluck('id')->implode(', ') . "] ]");
        } else {
            return false;
        }
    }



}