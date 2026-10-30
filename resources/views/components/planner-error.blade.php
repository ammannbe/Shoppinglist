@props(['key'])

{{-- Error of a week planner request, shown below the button that started it --}}
<p x-show="error && errorKey === @js($key)" class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800" role="alert" x-text="error"></p>
