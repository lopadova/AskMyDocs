<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>KITT Widget — Demo</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; color: #1f2937; }
        header { background: #111827; color: #fff; padding: 18px 24px; }
        main { max-width: 720px; margin: 32px auto; padding: 0 20px; }
        .card { border: 1px solid #e5e7eb; border-radius: 12px; padding: 20px; margin-bottom: 20px; }
        label { display: block; font-weight: 600; margin: 12px 0 4px; }
        input, select { width: 100%; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 8px; }
        button { margin-top: 16px; padding: 10px 16px; border: none; border-radius: 8px; background: #2563eb; color: #fff; font-weight: 600; cursor: pointer; }
    </style>
</head>
<body>
    <header>
        <h1 style="margin:0;font-size:18px">KITT Widget — pagina demo (non-SPA)</h1>
    </header>

    <main>
        <p>Pagina ospite di prova: il widget legge questo DOM (euristica + <code>data-kitt-*</code>)
           e risponde dalla knowledge base, oppure agisce sul form sottostante.</p>

        <section class="card" data-kitt-region="account-form" data-kitt-active="true"
                 data-kitt-help="Form profilo utente della demo.">
            <h2>Profilo</h2>
            <form id="demo-form" onsubmit="event.preventDefault(); document.getElementById('demo-result').textContent = 'Form inviato';">
                <div data-kitt-field="full-name">
                    <label for="full-name">Nome completo</label>
                    <input id="full-name" name="full-name" type="text" data-kitt-input>
                </div>
                <div data-kitt-field="plan">
                    <label for="plan">Piano</label>
                    <select id="plan" name="plan" data-kitt-input>
                        <option value="free">Free</option>
                        <option value="pro">Pro</option>
                        <option value="enterprise">Enterprise</option>
                    </select>
                </div>
                <button type="submit" data-kitt-action="submit"
                        data-kitt-help="Salva il profilo. Richiede conferma.">Salva profilo</button>
            </form>
            <p id="demo-result" data-testid="demo-result" style="color:#16a34a;font-weight:600"></p>
        </section>
    </main>

    <script>
        window.AskMyDocsWidget = {
            key: @json($publicKey),
            apiBase: '',
            title: 'Assistente Demo',
            launcherLabel: 'Chiedi',
            autoOpen: false,
        };
    </script>
    <script src="/widget/askmydocs-widget.js" defer></script>
</body>
</html>
