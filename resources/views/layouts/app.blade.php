<!DOCTYPE html>
<html lang="it" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Enterprise KB')</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3/dist/cdn.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3/dist/cdn.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: { extend: {} }
        }
    </script>
    <style>
        [x-cloak] { display: none !important; }
        /* Prose */
        .prose pre { background: #1e293b; color: #e2e8f0; padding: 1rem; border-radius: 0.5rem; overflow-x: auto; position: relative; }
        .prose code { font-size: 0.875rem; }
        .prose p { margin-bottom: 0.5rem; }
        .prose ul, .prose ol { margin-left: 1.5rem; margin-bottom: 0.5rem; }
        .prose li { margin-bottom: 0.25rem; }
        /* Enhanced tables */
        .prose table { width: 100%; border-collapse: collapse; font-size: 0.8125rem; margin: 0.75rem 0; }
        .prose th { background: #f1f5f9; font-weight: 600; text-align: left; padding: 0.5rem 0.75rem; border-bottom: 2px solid #e2e8f0; }
        .prose td { padding: 0.5rem 0.75rem; border-bottom: 1px solid #f1f5f9; }
        .prose tr:hover td { background: #f8fafc; }
        /* Copy button on code blocks */
        .code-copy-btn { position: absolute; top: 0.5rem; right: 0.5rem; background: rgba(255,255,255,0.1); color: #94a3b8; border: none; border-radius: 0.25rem; padding: 0.25rem 0.5rem; font-size: 0.75rem; cursor: pointer; }
        .code-copy-btn:hover { background: rgba(255,255,255,0.2); color: #fff; }
    </style>
</head>
<body class="h-full bg-gray-50 dark:bg-gray-900">
    @yield('body')
</body>
</html>
