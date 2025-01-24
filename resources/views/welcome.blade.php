<!doctype html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>{{ config('app.name') }}</title>
</head>
<body>

<h1>{{ config('app.url') }}</h1>

@if (App::environment('production'))
    <img src="{{ config('app.forge_url') }}" loading="lazy" class="" alt="Laravel Forage Badge">
@endif

</body>
</html>