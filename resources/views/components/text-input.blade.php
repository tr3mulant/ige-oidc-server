@props(['disabled' => false])

{{-- `tools.*` gets its input padding and borders from @tailwindcss/forms. That plugin
     is not a dependency here, so the same look is spelled out in utilities instead. --}}
<input @disabled($disabled) {{ $attributes->merge(['class' => 'px-3 py-2 border border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm']) }}>
