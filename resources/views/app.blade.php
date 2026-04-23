<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>AskMyDocs — Enterprise RAG</title>
    @viteReactRefresh
    @vite(['frontend/src/main.tsx'])
</head>
<body>
    <div id="root"></div>
</body>
</html>
