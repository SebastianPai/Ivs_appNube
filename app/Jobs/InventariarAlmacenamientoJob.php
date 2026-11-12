<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Documentos\Recuperacion\InventarioAlmacenamiento;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class InventariarAlmacenamientoJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries = 1;

    public function __construct(
        public string $disco,
        public ?string $prefijo,
        public ?int $userId,
        public bool $reanudar = true,
    ) {}

    // Un solo inventario por disco a la vez
    public function uniqueId(): string
    {
        return $this->disco.':'.$this->prefijo;
    }

    public function handle(InventarioAlmacenamiento $inventario): void
    {
        $user = $this->userId ? User::find($this->userId) : null;
        if ($user) {
            auth()->setUser($user);
        }

        $inventario->ejecutar($this->disco, $this->prefijo, $user, $this->reanudar);
    }
}
