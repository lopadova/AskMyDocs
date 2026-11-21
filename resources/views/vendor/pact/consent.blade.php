@extends('pact::layout')
@section('title', 'Autorizza collegamento')
@section('context', 'Collegamento in entrata')
@section('content')
<div class="pact-grid">
    <section aria-labelledby="pact-title">
        <span class="pact-eyebrow">02 / Dai il tuo consenso</span>
        <h1 id="pact-title">Una squadra.<br><em>Il tuo consenso.</em></h1>
        <p class="pact-lead">Un coordinatore PACT vuole collegare l’agente del tuo account. Sei nell’applicazione che ospita l’agente: qui decidi se autorizzare l’accesso.</p>
        @include('pact::partials.steps', ['step' => 2])
    </section>
    <section class="pact-panel" aria-labelledby="pact-consent-title">
        <h2 id="pact-consent-title">Autorizza il collegamento</h2>
        <div class="pact-origin"><span class="pact-hint">Coordinatore richiedente</span><span class="pact-domain">{{ $client->master_url }}</span></div>
        @if($pactSubject ?? null)
            <div class="pact-origin"><span class="pact-hint">Progetto AskMyDocs autorizzato</span><span class="pact-domain">{{ $pactSubject->tenant_id }} / {{ $pactSubject->project_key }}</span></div>
        @endif
        <ul class="pact-permissions">
            <li><strong>Il coordinatore invia gli incarichi.</strong>Potrà chiedere all’agente di cercare informazioni e rispondere con i tuoi permessi nel progetto scelto.</li>
            <li><strong>L’agente collabora con la squadra.</strong>Potrà consultare i membri e usare gli strumenti di coordinamento consentiti nella squadra scelta.</li>
        </ul>
        <p class="pact-hint">Dopo l’autorizzazione tornerai al coordinatore per completare il collegamento. L’agente inizierà a lavorare dopo la conferma dell’attivazione.</p>
        <div class="pact-actions">
            <form method="post" action="{{ route('pact.oauth.approve') }}">@csrf<input type="hidden" name="auth_token" value="{{ $authToken }}"><button class="pact-button" type="submit">Autorizza collegamento @include('pact::partials.icon', ['name' => 'arrow-right'])</button></form>
            <form method="post" action="{{ route('pact.oauth.deny') }}">@csrf<input type="hidden" name="auth_token" value="{{ $authToken }}"><button class="pact-button pact-button-secondary" type="submit">Annulla</button></form>
        </div>
    </section>
</div>
@endsection
