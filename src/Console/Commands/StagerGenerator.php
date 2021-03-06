<?php

namespace IbraheemGhazi\Stager\Commands;

use IbraheemGhazi\Stager\Doc;
use IbraheemGhazi\Stager\Traits\Stager;
use Illuminate\Console\Command;

class StagerGenerator extends Command
{

    const EOL_WIN = "\n    ";
    const PREFIX = "/**** AUTO-GENERATED STAGER DATA ****/" . self::EOL_WIN;
    const SUFFIX = self::EOL_WIN . "/**** END OF AUTO-GENERATED STAGER DATA ****/\n";


    private $constants_prefix = "STATE_";

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stager:generate {--C|clean : clean auto generated from registered models} {--M|model= : clean auto generated from models either it registered or not , must used with clean option}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate stager data automatically';

    /**
     * Create a new command instance.
     *
     */
    public function __construct()
    {
        parent::__construct();
        $this->constants_prefix = config('state-machine.config.constants-prefix', 'STATE_');
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {

        $doc = (new Doc())->start();

        $stateMachine = config('state-machine');

        if(($model = $this->option('model')) && ($path = $this->getModelPath($model))){
            $this->cleanAutoGeneratedCode($model);
            //TODO: remove ide helper generated code when clean specific model
            return;
        }

        foreach ($stateMachine as $model => $machine) {
            if (!in_array($model,['config','schedules'])) {
                $this->cleanAutoGeneratedCode($model);
                if($this->option('clean')) continue;
                $was_written = $this->writeModelNewContent($model, $machine);

                $was_written && $this->generateIdeHelperFile($doc, $model, $machine);
            }

        }
        $path = config('state-machine.config.ide-helper-path','stager-methods-ide-helper.php');
        $doc->exportFile($path);

        //TODO: clear auto generated fields from all models if not listed in config file state-machine

        $this->info("\nDone!");
    }

    private function getTraitNameSpace($param = 'full_path')
    {
        $full_path = '\\' . Stager::class;

        $class = "Stager";

        $namespace = str_replace('\\' . $class, '', $full_path);

        if (isset($$param)) {
            return $$param;
        }

        return (object)compact('full_path', 'class', 'namespace');
    }

    private function getUseForInclude($content)
    {
        ///////////////////////////
        ///  start use outside the class
        $name_space = $this->getTraitNameSpace();
        $full_trait_ns_use = "\nuse {$name_space};\n";
        $name_space = ltrim($name_space,"\\");
        $full_trait_ns_use_2 = "\nuse {$name_space};\n";

        $content = str_replace($full_trait_ns_use, '', $content);
        $content = str_replace($full_trait_ns_use_2, "\n", $content);

        $index = strpos($content, 'use');

        $content = substr($content, 0, $index) . $full_trait_ns_use . substr($content, $index, strlen($content));
        ////// end of start use outside the class
        /////////////////////////

        return $content;
    }

    private function getUse()
    {
        $use_code = "use " . $this->getTraitNameSpace('class') . ";" . self::EOL_WIN;;
        $override_traits = config('state-machine.config.shared-trait', []);
        $override_traits_use = '';
        foreach ($override_traits as $trait) {
            $override_traits_use = "use \\{$trait} { " . self::EOL_WIN;
            $sub_override = "";
            $methods_in_trait = get_class_methods($trait) ?: [];


            if (in_array('scopeStateChangeWithin', $methods_in_trait)) {
                $sub_override .= "    \\{$trait}::scopeStateChangeWithin insteadof " . $this->getTraitNameSpace('class') . ';' . self::EOL_WIN;
            }
            if (in_array('getStateChangedAt', $methods_in_trait)) {
                $sub_override .= "    \\{$trait}::getStateChangedAt insteadof " . $this->getTraitNameSpace('class') . ';' . self::EOL_WIN;
            }
            //if no insteadof function then remove bracket and add semicolun
            if (!strlen(trim($override_traits_use))) {
                $override_traits_use = rtrim(rtrim($override_traits_use), '{ ') . ' ;';
            } else {
                $override_traits_use .= $sub_override . '}';
            }
        }


        return $use_code . $override_traits_use;
    }

    private function getConstants($model, $machine)
    {
        $states = array_get($machine, 'states');


        $code = "";

        if (is_array($states)) {
            foreach ($states as $name => $state) {
                $constant_name = strtoupper($this->constants_prefix . str_replace('-', '_', $name));
                is_string($state) && $state = '"' . addslashes($state) . '"';
                $code .= "const {$constant_name} = {$state};" . self::EOL_WIN;
            }

        } else {
            //$code .= "/*** NO STATES FOUND FOR THIS MODEL ***/";
            $this->warn(PHP_EOL . "*** no valid states found for model [{$model}]" . self::EOL_WIN);
            return false;
        }

        return $code;
    }

    private function generateCode($model, $machine)
    {
        $constCode = $this->getConstants($model, $machine);

        /**
         * break code if no Constants
         */
        if (!$constCode) {
            return false;
        }

        $code = self::EOL_WIN;

        $code .= self::PREFIX . self::EOL_WIN;

        $code .= $this->getUse() . self::EOL_WIN;


        $code .= $constCode;

        $code .= self::SUFFIX . self::EOL_WIN;


        return $code;
    }

    private function getModelPath($model)
    {
        try {
            $reflector = new \ReflectionClass($model);
            return $reflector->getFileName();
        } catch (\Exception $e) {
            return false;
        }
    }

    private function isModelExists($model)
    {

        $model_path = $this->getModelPath($model);

        return $model_path && file_exists($model_path);

    }

    private function getModelContent($model)
    {


        if (!$this->isModelExists($model)) {
            $this->error("*** model [{$model}] not exists.\n");
            return false;
        }

        $content = @file_get_contents($this->getModelPath($model));

        return $content ? str_replace("\r\n", "\n", $content) : false;

    }

    private function getAutoGeneratedIndices($content)
    {
        $start = strpos($content, trim(self::PREFIX));
        $end = strpos($content, (self::SUFFIX));

        if ($start > -1 && $end > -1) {

            $start -= 4;
            $end += strlen(self::SUFFIX) + 2;

            return compact('start', 'end');
        }
        return false;
    }

    private function getNewClassIndices($content)
    {
        $class_index = strpos($content, 'class');
        $bracket_index = strpos($content, '{', $class_index) + 2;

        return [
            'start' => $bracket_index,
            'end' => $bracket_index
        ];
    }

    private function getSeparatedContent($content)
    {

        $indcies = $this->getAutoGeneratedIndices($content) ?: $this->getNewClassIndices($content);//
        return [
            substr($content, 0, $indcies['start']),
            substr($content, $indcies['end'], strlen($content))
        ];

    }

    private function isBooterDefined($model){
        $content = $this->getModelContent($model);
        return str_contains($content,' bootIfNotBooted');
//        $reflectionClass = new \ReflectionClass($model);
//        return $reflectionClass->getMethod('bootIfNotBooted')->class === $model;
    }

    private function getBooterDefinitionIndices($content)
    {
        $booter_index = strpos($content, 'bootIfNotBooted');
        $bracket_index = strpos($content, '{', $booter_index) + 2;

        $booter_parent_index = strpos($content, 'asasbootIfNotBooted',$bracket_index);
        $bracket_parent_index = strpos($content, ';', $booter_parent_index) + 2;


        return [
            'start' => $booter_parent_index ? $bracket_parent_index : $bracket_index,
            'end' => $booter_parent_index ? $bracket_parent_index : $bracket_index
        ];
    }

    private function defineInitStagerBooter($model,$content){
        if($this->isBooterDefined($model)){
            $indcies = $this->getBooterDefinitionIndices($content);
            $start_part = substr($content, 0, $indcies['start']);
            $end_part = substr($content, $indcies['end'], strlen($content));
            return $start_part ."        ".'$this->initStager();//auto generated from stager'."\n".$end_part;
        }
        return $content;
    }

    private function writeModelNewContent($model, $machine)
    {
        $code = $this->generateCode($model, $machine);

        /**
         * break exec. if no code
         */
        if (!$code) {
            return false;
        }


        $content = $this->getModelContent($model);

        if (!$content) {
            return false;
        }

        $content = $this->getUseForInclude($content);

        $content = $this->defineInitStagerBooter($model,$content);

        $content_part = $this->getSeparatedContent($content);

//        dd(rtrim($content_part[0]) . $code . ltrim($content_part[1]));

        if ($this->isModelExists($model)) {
            $model_path = $this->getModelPath($model);
            if (@file_put_contents($model_path, rtrim($content_part[0]) . $code . ltrim($content_part[1]))) {
                $this->info("+++ {$model} has been prepared for State Machine");
                return true;
            }
        }
        return false;
    }

    private function cleanAutoGeneratedCode($model){
        $clean_content =  $this->getModelContent($model);
        $clean_content =  str_replace([
            "\nuse {$this->getTraitNameSpace()};\n",
            '$this->initStager();'.self::EOL_WIN,
            '$this->initStager();',
            '//auto generated from stager'.self::EOL_WIN
        ],'',$clean_content);

        $pattern = '/'.preg_quote(trim(self::PREFIX),'/').'([\w\W]+)'.preg_quote(trim(self::SUFFIX),'/').'/';
//        dump(preg_match($pattern,$clean_content,$m));
//        dd($pattern,$m);
        $clean_content = preg_replace($pattern,'',$clean_content);
        if ($this->isModelExists($model)) {
            $model_path = $this->getModelPath($model);
            if (@file_put_contents($model_path, $clean_content)) {
                $this->info("+++ {$model} has been cleaned");
                return true;
            }
        }
    }

    private function generateIdeHelperFile(&$doc, $model, $machine)
    {
        $doc->openClass($model, function () use ($machine) {
            $inClassGen = new Doc;

            foreach (array_get($machine, 'states', []) as $stateName => $value) {
                $inClassGen = $inClassGen->addMethod('is' . studly_case($stateName), [], 'public', false);
            }

            foreach (array_get($machine, 'transitions', []) as $transName => $data) {
                $inClassGen = $inClassGen->addMethod('do' . studly_case($transName), ['...$args'], 'public', false);
                $inClassGen = $inClassGen->addMethod('can' . studly_case($transName), [], 'public', false);
            }

            return $inClassGen;
        });
    }

}
