<!doctype html>
<html lang="en" data-design-theme="{{ app(\App\Services\Appearance\InstallationAppearance::class)->theme() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>AskMyDocs — Enterprise RAG</title>
    <link rel="icon" href="/brand/favicon.png" type="image/png">
    <link rel="stylesheet" href="/brand/wordmark.css">
    @viteReactRefresh
    @vite(['frontend/src/main.tsx'])
</head>
<body>
    <div id="root"></div>
</body>
</html>
