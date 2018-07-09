<?php
/**
 * On each modification happened to this config file you must run artisan command stager:generate to update files and ide helpers
 * NOTE: any key must be in kebab-case only
 */
return [

    'config'=>[
        'ide-helper-path'=>'stager-methods-ide-helper.php',
        'unauthorized-gaurd-exception'=>true,//should throw unauthorized exception if guard requesting this transition is not in guard array , if false then can method only return false without exception
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
                'relation-state-condition'=>[
                   //relation class must be defined here in state-machine config
                    'some-realtion'=>'status'
                ],
                'guard'=>['web'],//should be array of guards or string equal '*', [default = '*']
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
