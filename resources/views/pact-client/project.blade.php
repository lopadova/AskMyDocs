@extends('pact::layout')
@section('title', 'Scegli il progetto AskMyDocs')
@section('context', 'Collegamento in entrata')
@section('content')
<div class="pact-grid">
    <section aria-labelledby="pact-title">
        <span class="pact-eyebrow">AskMyDocs / Progetto</span>
        <h1 id="pact-title">Scegli il <em>progetto.</em></h1>
        <p class="pact-lead">Il coordinatore richiede un agente AskMyDocs. Scegli il progetto a cui vuoi dare accesso prima di autorizzare il collegamento.</p>
        @include('pact::partials.steps', ['step' => 2])
    </section>
    <section class="pact-panel" aria-labelledby="pact-project-title">
        <h2 id="pact-project-title">Progetto autorizzato</h2>
        <div class="pact-origin"><span class="pact-hint">Coordinatore richiedente</span><span class="pact-domain">{{ $client->master_url }}</span></div>
        @if(session('pact.client.reselect'))
            <p class="pact-notice" role="status">Scegli di nuovo il progetto e conferma il consenso per continuare.</p>
        @endif
        @if($teams === [])
            <p class="pact-notice" role="status">Il tuo account non ha progetti disponibili per il collegamento.</p>
        @else
            <form method="post" action="{{ route('pact.oauth.select-project') }}">
                @csrf
                <input type="hidden" name="pact_consent" value="{{ $consentId }}">
                <label for="pact-project">Organizzazione e progetto</label>
                <select id="pact-project" name="project" required @error('project') aria-invalid="true" aria-describedby="pact-project-error" @enderror>
                    <option value="">Scegli un progetto</option>
                    @foreach($teams as $team)
                        <optgroup label="{{ $team['name'] }}">
                            @foreach($team['projects'] as $project)
                                <option value="{{ $project['selection'] }}">{{ $project['project_key'] }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                @error('project')<p id="pact-project-error" class="pact-field-error" role="alert">{{ $message }}</p>@enderror
                <div class="pact-actions">
                    <button type="submit" class="pact-button">Continua al consenso @include('pact::partials.icon', ['name' => 'arrow-right'])</button>
                </div>
            </form>
        @endif
    </section>
</div>
@endsection
