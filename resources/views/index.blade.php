<?php 
    $complaints=auth()->user()->complaints;
   
?>
<x-layout>

    {{-- @if ($complaints->isNotEmpty())      
    your compliants:
    @foreach ($complaints as $complaint)
        <div>
            <button type="button" class="complaint-btn">
                {{ $complaint['complaint'] }}
            </button>
        </div>
    @endforeach

    <form action="/send-to-py" method="post" id="complaintForm">
        @csrf
        <input type="text" name="text" id="complaintInput" value="">
        <button type="submit">send</button>
    </form>

    @else

    <div>
        <p>add a complaint</p>
        <form action="/complaint" method="post">
            @csrf
            <label for="complaint">Your complaint</label>
            <input type="text" name ="complaint">
            <button>submit</button>
        </form>
    </div>

    @endif

       

    <script>
    // Находим все кнопки жалоб
        const buttons = document.querySelectorAll('.complaint-btn');
        const input = document.getElementById('complaintInput');

        buttons.forEach(btn => {
            btn.addEventListener('click', () => {
                // Берём текст кнопки и вставляем в инпут формы
                input.value = btn.textContent.trim();
            });
        });
    </script>
    
    @if ($result->isnotempty())
        
    <div>
        <p>{{$result['upper']}}</p>
    </div>
    @endif --}}
{{-- 
    <div>
        <h2>Add a ?</h2>
        f
    </div> --}}
   
</x-layout>