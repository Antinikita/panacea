<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Document</title>
    @vite(['resources/js/app.js','resources/css/app.css'])
</head>
<body>
    <div>
        <nav class="flex justify-between items-center py-1 bg-red-400  border-b border-white/10">
            <div>
                <a href="/">
                    <img class="max-w-20" src="{{ Vite::asset('resources/images/logo.jpg') }}" alt="">
                </a>
            </div>

            <div>
                <a href="">Link</a>
                <a href="">Link</a>
                <a href="">Link</a>
            </div>

            <div>
                <a href="">Sign Up</a>
                <a href="">Log In</a>
            </div>
        </nav>

        <main>
            {{ $slot }}
        </main>
    </div>
</body>
</html>