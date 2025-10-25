<?php 
    $complaints=auth()->user()->complaints;
?>
<x-layout>
    your compliants:
    @foreach ($complaints as $complaint)
        <div>
            <p>{{$complaint['complaint']}}</p>
        </div>
    @endforeach
</x-layout>