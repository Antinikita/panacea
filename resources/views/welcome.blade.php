<x-layout>
    @auth
        {{ auth()->user()}}
    @endauth
    hello!
</x-layout>