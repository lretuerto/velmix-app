@php
    use Illuminate\Support\Js;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name', 'VELMiX ERP') }} | Frontend App</title>
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="velmix-request-id" content="{{ data_get($boot, 'app.request_id') }}">
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet" />
        @vite(['resources/css/app.css', 'resources/js/app.tsx'])
        <script>
            window.__VELMIX_BOOT__ = {{ Js::from($boot) }};
        </script>
    </head>
    <body>
        <div id="app"></div>
    </body>
</html>
