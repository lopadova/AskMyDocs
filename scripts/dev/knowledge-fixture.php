<?php

// Local-only, isolated fixtures for playwright.knowledge.config.ts. No AI calls.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment(['local', 'testing'])) throw new RuntimeException('Knowledge fixtures are restricted to local/testing environments.');
$options = getopt('', ['create', 'cleanup', 'file:']);
$fixtureFile = $options['file'] ?? null;
if (! is_string($fixtureFile) || ! str_starts_with($fixtureFile, '/') || str_starts_with($fixtureFile, public_path().'/')) throw new RuntimeException('Use an absolute private fixture path, outside public/.');
if (isset($options['create']) === isset($options['cleanup'])) throw new RuntimeException('Choose exactly one of --create or --cleanup.');
if (isset($options['create'])) {
    if (file_exists($fixtureFile)) throw new RuntimeException('Fixture file already exists; clean it up before creating another.');
$tenant = 'knowledge-e2e-'.bin2hex(random_bytes(5));
app(App\Support\TenantContext::class)->set($tenant);
$password = bin2hex(random_bytes(16));
$user = App\Models\User::create(['name'=>'Knowledge acceptance','email'=>$tenant.'@example.test','password'=>Illuminate\Support\Facades\Hash::make($password),'email_verified_at'=>now(),'is_active'=>true,'locale'=>'en']);
$user->assignRole('admin');
App\Models\ProjectMembership::create(['tenant_id'=>$tenant,'project_key'=>'orders','user_id'=>$user->id,'role'=>'member']);
$specs = app(App\Services\Kb\Knowledge\KnowledgeSpecificationService::class);
$revision = $specs->draft($tenant, 'orders', 'Organizzare ordini e assistenza', app(App\Services\Kb\Knowledge\KnowledgeContract::class)->template(), (string)$user->id);
$specs->activate($tenant, 'orders', $revision->id);
config(['kb.autowiki.enabled'=>true,'knowledge.enabled'=>true,'knowledge.sections_per_job'=>100]);
$app->instance(App\Services\Kb\Knowledge\KnowledgeExtractor::class, new class extends App\Services\Kb\Knowledge\KnowledgeExtractor {
 public function propose(string $text, array $contract): array { return ['contributions'=>[['type'=>'order','namespace'=>'order','identifier'=>'00123','rule'=>'history','property'=>'event','value'=>str_contains($text,'consegnato')?'Consegnato':'Spedito','quote'=>$text]]]; }
 public function judge(array $candidate,array $contract): array {return ['status'=>'accepted','support'=>'documented'];}
});
$ids=[];
foreach (['Ordine 00123 spedito con corriere.','Ordine 00123 consegnato e ricevuto dal cliente.'] as $i=>$text) {
 $doc=App\Models\KnowledgeDocument::create(['tenant_id'=>$tenant,'project_key'=>'orders','source_type'=>'text','source_path'=>$tenant.'/email-'.$i.'.txt','title'=>'Original email '.($i+1),'mime_type'=>'text/plain','status'=>'active','document_hash'=>hash('sha256',$text),'version_hash'=>hash('sha256',$text),'source_updated_at'=>now()->subDays(2-$i)]);
 App\Models\KnowledgeChunk::create(['tenant_id'=>$tenant,'project_key'=>'orders','knowledge_document_id'=>$doc->id,'chunk_order'=>0,'chunk_hash'=>hash('sha256',$text),'chunk_text'=>$text]);
 app(App\Services\Kb\Knowledge\KnowledgeEnrichment::class)->process($doc);
 $ids[]=$doc->id;
}
$fixture=['tenant'=>$tenant,'email'=>$user->email,'password'=>$password,'user_id'=>$user->id,'team_hash'=>App\Support\TeamHash::for($tenant),'document_ids'=>$ids];
file_put_contents($fixtureFile,json_encode($fixture));chmod($fixtureFile,0600);
    echo 'Created isolated knowledge fixture at '.$fixtureFile.PHP_EOL;
} else {
$f=json_decode(file_get_contents($fixtureFile),true);$tenant=$f['tenant'];
$user=App\Models\User::find($f['user_id']);
if(!preg_match('/^knowledge-e2e-[a-f0-9]{10}$/D',$tenant)||!$user||$user->email!==$tenant.'@example.test')throw new RuntimeException('Fixture ownership check failed.');
app(App\Support\TenantContext::class)->set($tenant);
Illuminate\Support\Facades\DB::transaction(function()use($tenant,$user,$f){
 App\Models\KbEdge::withoutGlobalScope('document_category')->forTenant($tenant)->delete();
 App\Models\KbNode::withoutGlobalScope('document_category')->forTenant($tenant)->delete();
 App\Models\KnowledgeObservation::forTenant($tenant)->delete();
 App\Models\KnowledgeEntity::forTenant($tenant)->delete();
 foreach(App\Models\KnowledgeDocument::withTrashed()->forTenant($tenant)->whereIn('id',$f['document_ids'])->get() as $doc)$doc->forceDelete();
 App\Models\KnowledgeSpecificationScope::forTenant($tenant)->delete();
 App\Models\KnowledgeSpecification::forTenant($tenant)->delete();
 App\Models\KbAnalysisSetting::forTenant($tenant)->delete();
 App\Models\ProjectMembership::forTenant($tenant)->where('user_id',$user->id)->delete();
 App\Models\Project::forTenant($tenant)->delete();
 $user->forceDelete();
});
    unlink($fixtureFile);
    echo 'Removed the isolated fixture and credentials.'.PHP_EOL;
}
