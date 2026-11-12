<?php
declare(strict_types=1);
$repo=$argv[1];chdir($repo);require $repo.'/vendor/autoload.php';
$app=require $repo.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$project=basename($repo);$out=['project'=>$project,'environment'=>app()->environment(),'scope'=>'local database; aggregate read-only; not production invoices','since'=>'2026-08-14'];
try{
 $db=Illuminate\Support\Facades\DB::connection();$db->beginTransaction();if($db->getDriverName()==='pgsql')$db->statement('SET TRANSACTION READ ONLY');
 if($project==='wearframeMain'){
  $out['models']=$db->select('SELECT codename,openrouter_model_id,direct_provider,cost_per_call_usd,is_active,bypass_openrouter FROM ai_models WHERE is_active=1 ORDER BY sort_order LIMIT 25');
  $out['media']=$db->select("SELECT service,provider,ai_model_codename,COUNT(*) AS outputs,COUNT(cost_actual) AS priced,SUM(CASE WHEN cost_actual>0 THEN 1 ELSE 0 END) AS positive_cost,ROUND(AVG(NULLIF(cost_actual,0)),6) AS avg_positive_usd,ROUND(MAX(cost_actual),6) AS max_usd,SUM(CASE WHEN is_hallucinated=1 THEN 1 ELSE 0 END) AS flagged FROM media WHERE created_at >= '2026-08-14' AND type='generated' GROUP BY service,provider,ai_model_codename ORDER BY outputs DESC LIMIT 20");
  $out['api_aggregates']=$db->select("SELECT provider,ai_model,SUM(request_count) AS requests,ROUND(SUM(estimated_cost),6) AS recorded_usd,ROUND(SUM(estimated_cost)/NULLIF(SUM(request_count),0),6) AS avg_recorded_usd FROM user_token_usage WHERE usage_date >= '2026-08-14' GROUP BY provider,ai_model ORDER BY requests DESC LIMIT 12");
  $out['config']=[];foreach(['features.hallucination_detection','features.hallucination_max_retries','wfcoins.euro_to_wfcoins_rate','wfcoins.markup_percentage'] as $k)$out['config'][$k]=config($k);
 }elseif($project==='AskMyDocsDev'){
  $out['ledger']=$db->select("SELECT provider,model,currency,cost_method,status,COUNT(*) AS calls,SUM(tokens_input) AS input_tokens,SUM(tokens_output) AS output_tokens,ROUND(SUM(cost_total)::numeric,6) AS recorded_cost,ROUND(AVG(cost_total)::numeric,6) AS avg_call,ROUND(MAX(cost_total)::numeric,6) AS max_call FROM ai_finops_usage_ledger WHERE created_at >= '2026-08-14' GROUP BY provider,model,currency,cost_method,status ORDER BY calls DESC LIMIT 15");
  $out['trace_distribution']=$db->select("SELECT COUNT(*) AS traces,ROUND(AVG(n)::numeric,2) AS avg_calls,percentile_cont(0.5) WITHIN GROUP (ORDER BY n) AS median_calls,percentile_cont(0.95) WITHIN GROUP (ORDER BY n) AS p95_calls,MAX(n) AS max_calls,ROUND(AVG(c)::numeric,6) AS avg_trace_recorded_usd,ROUND(MAX(c)::numeric,6) AS max_trace_recorded_usd FROM (SELECT trace_id,COUNT(*) n,SUM(cost_total) c FROM ai_finops_usage_ledger WHERE created_at >= '2026-08-14' AND currency='USD' AND trace_id IS NOT NULL GROUP BY trace_id) s");
  $out['purposes']=$db->select("SELECT purpose_tag,COUNT(*) AS calls FROM ai_finops_usage_ledger WHERE created_at >= '2026-08-14' GROUP BY purpose_tag ORDER BY calls DESC LIMIT 15");
 }elseif($project==='oraculi'){
  $out['usage']=$db->select("SELECT provider,model,estimated,usage_source,billing_operation,COUNT(*) AS calls,SUM(prompt_tokens) AS input_tokens,SUM(completion_tokens) AS output_tokens,ROUND(SUM(cost_usd)::numeric,6) AS recorded_usd,ROUND(AVG(cost_usd)::numeric,6) AS avg_call,ROUND(MAX(cost_usd)::numeric,6) AS max_call FROM ai_usage_logs WHERE created_at >= '2026-08-14' GROUP BY provider,model,estimated,usage_source,billing_operation ORDER BY calls DESC LIMIT 15");
  $out['pricing']=$db->select("SELECT pricing_status,category,meter,COUNT(*) AS entries,ROUND(SUM(cost_eur)::numeric,6) AS cost_eur FROM cost_ledger_entries WHERE observed_at >= '2026-08-14' GROUP BY pricing_status,category,meter ORDER BY entries DESC LIMIT 15");
  $out['model_overrides']=$db->select("SELECT value FROM settings WHERE key='ai_models'");
 }
 $db->rollBack();
}catch(Throwable $e){$out['error_class']=get_class($e);$out['error_code']=$e->getCode();}
echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
