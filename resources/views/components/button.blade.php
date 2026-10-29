<button {{ $attributes->merge(['type' => 'submit', 'class' => 'button w-full']) }}>
    {{ $slot }}
</button>
