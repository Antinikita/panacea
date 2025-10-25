<x-layout>
    <form action="/register" method="post" class="max-w-2xl mx-auto space-y-6 bg-red-50">
        @csrf
        <label for="name">Name</label>
        <input type="text" label="Name" name="name" class="bg-blue-50">
        <label for="email">Email</label>
        <input type="email" label="Email" name="email" class="bg-blue-50">
        <label for="password">Password</label>
        <input type="password" label="Password" name="password" class="bg-blue-50">
        <label for="password_confirmation">Password Confirmation</label>
        <input type="password" label="Password Confirm" name="password_confirmation" class="bg-blue-50">
        <button>Submit</button>
    </form>
</x-layout>