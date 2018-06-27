<?php

namespace IbraheemGhazi\Stager;

use IbraheemGhazi\Stager\Commands\StagerGenerator;
use IbraheemGhazi\Stager\Jobs\StagerJob;
use \Illuminate\Support\ServiceProvider;

use \Illuminate\Console\Scheduling\Schedule;
use \Illuminate\Support\Facades\Event;

use Artisan;

class StagerServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {

        //register config to be published
        $this->publishes([
            __DIR__.'/config/state-machine.php' => config_path('state-machine.php'),
        ]);

        $stateMachineConfig = config('state-machine');

        if(!$stateMachineConfig){
//             \Log::error('config/state-machine.php not found, please publish the config file and configure it.');
//             return;
            
            Artisan::call('vendor:publish',['--provider'=>__CLASS__]);
            
            //trigger_error('config/state-machine.php not found, please publish the config file and configure it.');
        }

        $this->app->booted(function ()use($stateMachineConfig) {

            $cronJobTime = array_get($stateMachineConfig,'config.schedule-cronjob','0 * * * *');

            if(!$this->isValidCronExpression($cronJobTime)) {
                trigger_error("invalid cron job expression [{$cronJobTime}] from state machine.");
            }

            $schedule = app(Schedule::class);

            $schedule->job(new StagerJob)->cron($cronJobTime);


        });


    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->commands([
            StagerGenerator::class
        ]);

    }

    private function isValidCronExpression($input){

        $result = preg_match('/^(\*|([0-9]|1[0-9]|2[0-9]|3[0-9]|4[0-9]|5[0-9])|\*\/([0-9]|1[0-9]|2[0-9]|3[0-9]|4[0-9]|5[0-9])) (\*|([0-9]|1[0-9]|2[0-3])|\*\/([0-9]|1[0-9]|2[0-3])) (\*|([1-9]|1[0-9]|2[0-9]|3[0-1])|\*\/([1-9]|1[0-9]|2[0-9]|3[0-1])) (\*|([1-9]|1[0-2])|\*\/([1-9]|1[0-2])) (\*|([0-6])|\*\/([0-6]))$/',$input);

        return $result > 0;
    }
}
