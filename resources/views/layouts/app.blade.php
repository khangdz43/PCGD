<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title' , 'PCGD-XMC')</title>
</head>


<body>
    @include('partials.header')
    @include('partials.navbar')
    @yield('content')



</body>

</html>