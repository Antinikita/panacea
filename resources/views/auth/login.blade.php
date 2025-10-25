<x-layout>
    <form action="/login" method="post" class="max-w-2xl mx-auto space-y-6 bg-red-50">
        @csrf
        <label for="email">Email</label>
        <input type="email" label="Email" name="email" class="bg-blue-50">
        <label for="password">Password</label>
        <input type="password" label="Password" name="password" class="bg-blue-50">
        <button>Log in</button>
    </form>
</x-layout>