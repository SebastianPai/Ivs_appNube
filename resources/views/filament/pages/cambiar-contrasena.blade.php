<x-filament-panels::page>
    <div class="max-w-md">
        <p class="mb-6 text-sm text-gray-600 dark:text-gray-300">
            Por seguridad, antes de continuar debes reemplazar la contraseña inicial por una personal de al menos 8 caracteres.
        </p>

        <form wire:submit="guardar" class="space-y-6">
            {{ $this->form }}

            <x-filament::button type="submit">
                Guardar y continuar
            </x-filament::button>
        </form>
    </div>
</x-filament-panels::page>
