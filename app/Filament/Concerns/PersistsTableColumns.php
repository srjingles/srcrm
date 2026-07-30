<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Actions\User\SaveUserPreference;
use App\Models\User;
use App\Models\UserPreference;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

/**
 * Hace que la configuración de columnas visibles de una tabla sobreviva a la sesión.
 *
 * Filament ya persiste ese estado, pero en la sesión (`persistsColumnsInSession`, activo
 * por defecto): al caducar la cookie, cerrar sesión o cambiar de navegador se pierde.
 * Aquí solo se le cambia el almacén, sobrescribiendo los dos únicos puntos que tocan la
 * sesión en `Filament\Tables\Concerns\HasColumnManager`.
 *
 * El ámbito es usuario + equipo porque las tablas incluyen columnas de campos
 * personalizados, que son por tenant (`InteractsWithCustomFields::table()` las añade con
 * `pushColumns()`): el mismo listado no ofrece las mismas columnas en dos equipos.
 *
 * Cada clase Livewire tiene su propia clave, así que el listado de Tareas y el relation
 * manager de Tareas de una ficha se configuran por separado.
 *
 * Sin usuario autenticado del CRM (p. ej. el panel sysadmin, que usa otro guard) se
 * delega en el comportamiento original de sesión.
 */
trait PersistsTableColumns
{
    /**
     * Último estado conocido en base de datos, para no escribir en cada petición.
     *
     * @var array<int, array<string, mixed>>|null
     */
    protected ?array $storedTableColumnsState = null;

    protected bool $hasLoadedStoredTableColumnsState = false;

    public function getTableColumnsPreferenceKey(): string
    {
        return 'table_columns:'.static::class;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function loadTableColumnsFromSession(): array
    {
        $user = $this->getTableColumnsPreferenceUser();

        if (! $user instanceof User) {
            return parent::loadTableColumnsFromSession();
        }

        return $this->getStoredTableColumnsState($user) ?? $this->getDefaultTableColumnState();
    }

    protected function persistTableColumns(): void
    {
        if (! $this->getTable()->persistsColumnsInSession()) {
            return;
        }

        $user = $this->getTableColumnsPreferenceUser();

        if (! $user instanceof User) {
            parent::persistTableColumns();

            return;
        }

        // `applyTableColumnManager()` corre en cada petición Livewire de la tabla (ordenar,
        // buscar, paginar), no solo al cambiar columnas. Sin esta comparación habría un
        // UPDATE por cada interacción con el listado.
        if ($this->getStoredTableColumnsState($user) === $this->tableColumns) {
            return;
        }

        resolve(SaveUserPreference::class)->execute(
            $user,
            $this->getTableColumnsPreferenceTeamId(),
            $this->getTableColumnsPreferenceKey(),
            $this->tableColumns,
        );

        $this->storedTableColumnsState = $this->tableColumns;
        $this->hasLoadedStoredTableColumnsState = true;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    protected function getStoredTableColumnsState(User $user): ?array
    {
        if ($this->hasLoadedStoredTableColumnsState) {
            return $this->storedTableColumnsState;
        }

        $this->hasLoadedStoredTableColumnsState = true;

        /** @var array<int, array<string, mixed>>|null $state */
        $state = UserPreference::lookup(
            $user,
            $this->getTableColumnsPreferenceTeamId(),
            $this->getTableColumnsPreferenceKey(),
        );

        return $this->storedTableColumnsState = $state;
    }

    protected function getTableColumnsPreferenceUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    protected function getTableColumnsPreferenceTeamId(): ?string
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Model) {
            return null;
        }

        $key = $tenant->getKey();

        return is_string($key) ? $key : null;
    }
}
