<?php

namespace IbraheemGhazi\Stager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Artisan;

class StagerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {

        foreach (config('state-machine') as $model=>$props) {

            foreach (array_get($props,'schedules',[]) as $state=>$scheduleData){
                $objects = $model::where('state',$state)->StateChangeWithin($scheduleData['trigger-delay']['time-modifier'],$scheduleData['trigger-delay']['interval'])->get();
                //todo: validate query if it correct or not by syntax with relation
//
                foreach ($objects as $object){
                    $callName = 'do'.studly_case(array_get($scheduleData,'transition'));
                    $object->{$callName}();
                }
                $commands = array_get($scheduleData,'commands',[]);
                foreach ($commands as $command=>$params){
                    Artisan::call($command, $params ?: []);
                }


            }

        }

    }
}
