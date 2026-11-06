<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="dark">
    <title>{{ config('app.name', 'Digital Theater Posters') }}</title>
    <link rel="stylesheet" href="{{ asset('display/display.css') }}?v={{ $appVersion }}">
    <style>
        :root { --dtp-bg: {{ $config['background'] }}; --dtp-fit: {{ $config['fit'] }}; }
    </style>
</head>
<body>
    <div id="stage" aria-hidden="true">
        <img class="layer" alt="" draggable="false">
        <img class="layer" alt="" draggable="false">
    </div>
    <div id="title" class="title" hidden></div>
    {{-- Brightness overlay: pure black, opacity = 1 - brightness/100. Starts opaque so nothing flashes on boot. --}}
    <div id="dim" class="dim"></div>

    <script>window.DTP_CONFIG = @json($config);</script>
    <script src="{{ asset('display/display.js') }}?v={{ $appVersion }}" defer></script>
</body>
</html>
