<?php
declare(strict_types=1);
$mode=$argv[1]??'';
if(!in_array($mode,['minimum','current'],true)){throw new InvalidArgumentException('Choose minimum or current.');}
$manifest=json_decode(file_get_contents(__DIR__.'/../composer.json'),true,flags:JSON_THROW_ON_ERROR);
if($mode==='minimum'){
 $manifest['require']['laravel/framework']='13.40.0';
 if(isset($manifest['require']['filament/filament'])){
  $catalog=['actions','filament','forms','infolists','notifications','query-builder','schemas','support','tables','widgets'];
  foreach($catalog as $package){$manifest['require']['filament/'.$package]='5.11.0';}
  $manifest['require']['livewire/livewire']='4.4.8';
 }
}
file_put_contents(__DIR__.'/../.composer-stage.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL);
putenv('COMPOSER=.composer-stage.json');
$command=['composer','update','--prefer-dist','--no-interaction','--no-scripts','--no-plugins'];
$process=proc_open($command,[STDIN,STDOUT,STDERR],$pipes,dirname(__DIR__));
exit(proc_close($process));
