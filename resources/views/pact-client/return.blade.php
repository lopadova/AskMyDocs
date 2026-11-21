@extends('pact::layout')
@section('title', 'Torna al coordinatore PACT')
@section('context', 'Ritorno al coordinatore')
@section('content')
<div class="pact-grid">
    <section aria-labelledby="pact-title">
        <h1 id="pact-title">Torna al <em>coordinatore.</em></h1>
        @include('pact::partials.steps', ['step' => 3])
    </section>
    <section class="pact-panel" aria-labelledby="pact-return-title">
        <h2 id="pact-return-title">Continua sul coordinatore</h2>
        <div class="pact-actions">
            <a id="pact-return" class="pact-button" href="{{ $callback }}" rel="noreferrer">Torna al coordinatore @include('pact::partials.icon', ['name' => 'arrow-right'])</a>
        </div>
    </section>
</div>
<script nonce="{{ Illuminate\Support\Facades\Vite::cspNonce() }}">
    window.location.replace(document.getElementById('pact-return').href);
</script>
@endsection
