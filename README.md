Stager
==========


This is a Laravel 5.6 package for eloquent models. Its purpose is to add state machien functionalty to models


Features
========

* all state-machines in one config file
* states defined as name,value
* transitions from multiple status
* transition affect related model
* schedules run every X times you defined
* schedules transitions depending on state and last time state changed
* schedules support run command depending on state
* support shared trait for shared props and attribute or override {some} of stager methods
* support events before and after transitions 
* support additional executions or extra conditions before access transition
* auto generate ide helper file for magic functions

Installation
==============

```
composer require ibraheem-ghazi/stager
php artisan vendor:publish --provider="IbraheemGhazi\Stager\StagerServiceProvider"
```


Configuration
================

* in order to add state machine you have to define it in `config/state-machine.php`

#####Notes:

* each state define a constant by generator
* each state must have unique value for current model so it does not overlap others when get current state name.
* state-column and init-state attributes are optional which by default are state-column = state and init-state is by default first state
* each attribute key must be in kebab-case

```php
<?php
/**
 * On each modification happened to this config file you must run artisan command stager:generate to update files and ide helpers
 * NOTE: any key must be in kebab-case only
 */
return [

    'config'=>[
        'ide-helper-path'=>'stager-methods-ide-helper.php',//where to save ide helper file (currently in root)
        'fail-throw-exception'=>true, //useful for debug , for production its better to turn it off
        'schedule-cronjob'=>'0 * * * *', //run cron job every hour once to handle schedule

        //generator config
        'constants-prefix'=>'STATE_', //
        'shared-trait'=>[
            // you can use this a trait to add shared functionality to all models that use stager
            // or to override scopeStateChangeWithin , getStateChangedAt functions

          //  \App\Traits\SharedTrait::class,
        ],
    ],


    ////////////////////////////////////////////


    \App\Payment::class => [

        'state-column'=>'state', //(optional, default: state)

        'init-state'=>'pending',//(optional, default: the first defined state

        'states' => [
            //state-name => numeric value
            'pending' =>1,
            'payment-accepted'=>2,
            'in-progress'=>3,
            'ended' => 4,
        ],


        'schedules'=>[

            //state-name
            'pending'=>[

                //if last state change time has passed trigger-delay then it will be included in transition run
                // for example:  run transition for all payments that has state 'pending' and changed from 2 days or more
                'trigger-delay' =>[
                    'time-modifier'=>"DAY",
                    'interval'=>2
                ],

                // the transition to be run on each row apply this schedule requirement (state , last state change time)
                'transition'=>'payment-success',

                // run commands after transition excuted
                'commands'=>[
                    'command:subcommand'=>['param1'=>'val1'],
                ]
            ],
        ],
        'transitions' => [
            'payment-success' => [
                'from' => 'pending',
                'to' => 'payment-accepted',

                //todo: affection class
                'affect'=>[
                    //relation => transiojn_of_relation
                      'order' => 'waiting-seller'
                ],

            ],
            'seller-accept' => [
                'from' => 'payment-accept',
                'to' => 'in-progress',
            ],
            'finish' => [
                'from' => 'in-progress',
                'to' => 'ended',
            ],
            'canceled' => [
                'from' => 'pending',
                'to' => 'ended',
            ],

        ],
    ],
];

```

* any time config file updated you must run 

```
$ php artisan stager:generate
-C or --clean clean auto generated code from all registered models 
-M or -model \App\MyModel clean auto generated code from sepcified model
```
this command will auto modify actual model file and add needed `use` statements and write needed constants from states

    //an example of auto-generated data to model
    
    <?php
    
    namespace App;
    
    
    use \IbraheemGhazi\Stager\Traits\Stager;
    use Illuminate\Database\Eloquent\Model;
    
    class Payment extends Model
    {
        /**** AUTO-GENERATED STAGER DATA ****/
        
        use Stager;

        const STATE_PENDING = 1;
        const STATE_PAYMENT_ACCEPTED = 2;
        const STATE_IN_PROGRESS = 3;
        const STATE_ENDED = 4;
        
        /**** END OF AUTO-GENERATED STAGER DATA ****/
    }
    
#### Note: you must not edit anything between these comments (or comments it self):

    /**** AUTO-GENERATED STAGER DATA ****/
    
    ...
    
    /**** END OF AUTO-GENERATED STAGER DATA ****/
the generator will read model file if it has these comments it will replace it's content with new auto generated code otherwise if it can not find these comment it will assume the class does not contains that data
    

Shared Trait
============

shared trait used to define a shared properties for all models that use stager, for example if you want to add a relation ,
it's mainly defined so you can easily override 2 Stager methods for last state changed time :

**getStateChangedAt()**
get last time state has changed
```
paramters :  -
```




**scopeStateChangeWithin($query, $modifier, $interval)**
a scope to get only rows that changed Within X Days for example 
```
paramters :  
    * $query = default query paramter for scope from laravel
    * $modifier = sql modifiter [YEAR, MONTH, DAY, HOUR, MINUTE, SECOND]
    * $interval = integer value define the interval based on modifier
```



preTransitionNameTransition Function
====================================
if a function defined in the model with studly case of transition has prefix `pre` and suffix `Transition` it will be executed before transition action
if it return `TRUE` it will complete the transition otherwise it will abort it 

**example:**
assume you have a transition named : cancel-order then
```
function preCancelOrderTransition(...$args){

}
```
where `...$args` is array of parameters passed from doCancelOrder

Functions Available
===================

|func                    | params        | return        | description|
|------------------------|---------------|---------------|----------------------|
|getCurrentStateValue    | -             | mixed         | return value of current state/
|getCurrentStateName     | -             | string        | return name of current state/
|hasValidStateMachine    | -             | bool        | check if this model has valid state machine or not/

Magic Functions Available
==========================

each magic function has prefix [is, can, do] with studly case of target either state or transition

```
isStateName()
* no parameters required
* return is current model row state equal the state name
```
```
canTransitionName()
* no parameters required
* return is current model able to move to given transition from current state or not
```

```
doTransitionName()
* accept multiple parameters as you required ( these parameters will be pass to preTransitionNameTransition() )
* execute the transition 
* return model or false on failed (note that if `fail-throw-exception` is enabled it will throw an exception instead )
```

Transition Life Cycle
========================

1. check if can transition from current state
2. if model define preTransitionNameTransition then it will execute , if it return true the transition continue otherwise it will abort (fail)
3. trigger beforeTransition event 
4. update the state
5. execute affection if exists
6. trigger afterTransition event
