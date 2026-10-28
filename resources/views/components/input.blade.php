@props(['label', 'name', 'type' => 'text'])

<label class="mb-4 block">
    <span class="label">{{ $label }}</span>
    <input type="{{ $type }}" name="{{ $name }}" value="{{ $type === 'password' ? '' : old($name, $attributes->get('value')) }}"
        {{ $attributes->except('value')->merge(['class' => 'field w-full']) }}>
</label>
