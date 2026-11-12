<?php
declare(strict_types=1);
$repo=$argv[1];
chdir($repo);
require $repo.'/vendor/autoload.php';
$app=require $repo.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$out=['project'=>basename($repo),'environment'=>app()->environment()];
try {
    $db=Illuminate\Support\Facades\DB::connection();
    $driver=$db->getDriverName();
    $db->beginTransaction();
    if($driver==='pgsql')$db->statement('SET TRANSACTION READ ONLY');
    $tables=$driver==='pgsql'
      ?$db->select("SELECT table_name,column_name,data_type FROM information_schema.columns WHERE table_schema='public' AND (table_name IN ('ai_models','ai_usage_logs','cost_ledger_entries','media','user_token_usage','user_token_usages','ai_finops_usage_ledger','agent_runs','learning_sessions','settings','site_settings')) ORDER BY table_name,ordinal_position")
      :$db->select("SELECT table_name,column_name,data_type FROM information_schema.columns WHERE table_schema=DATABASE() AND (table_name IN ('ai_models','ai_usage_logs','cost_ledger_entries','media','user_token_usage','user_token_usages','ai_finops_usage_ledger','agent_runs','learning_sessions','settings','site_settings')) ORDER BY table_name,ordinal_position");
    $out['schema']=[];
    foreach($tables as $c){$c=(array)$c;$out['schema'][$c['table_name']??$c['TABLE_NAME']][]=$c['column_name']??$c['COLUMN_NAME'];}
    if(basename($repo)==='AskMyDocsDev'){
        $out['config']=[];
        foreach(['ai.default','ai.providers.openai.models.text.default','ai.providers.openrouter.models.text.default','ai.embeddings_provider','agent.planner.mode','agent.limits.physical_hard','kb.auto_wiki.enabled'] as $key)$out['config'][$key]=config($key);
    }
    $db->rollBack();
}catch(Throwable $e){$out['error_class']=get_class($e);$out['error_code']=$e->getCode();$out['network_permission_denied']=str_contains($e->getMessage(),'Operation not permitted');$out['connection_refused']=str_contains($e->getMessage(),'Connection refused');}
echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
