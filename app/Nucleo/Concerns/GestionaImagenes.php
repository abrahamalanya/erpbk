<?php

namespace App\Nucleo\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Ciclo de vida de las imágenes de un modelo que expone fotos en su CRUD y las
 * guarda en el disco `public` bajo la columna `{campo}_path`.
 *
 * Centraliza el guardado con reemplazo y el borrado del disco para que los
 * módulos que tienen fotos (Empresa, Cliente, Usuario) no repitan el loop.
 */
trait GestionaImagenes
{
    /**
     * Guarda en `public` las imágenes de `$campos` que vengan en el request,
     * bajo `{carpeta}/{modelo->id}`. Solo toca el disco para los campos que
     * llegan con archivo nuevo; los demás conservan su imagen actual.
     *
     * @param  list<string>  $campos
     */
    protected function storeImagenes(Request $request, Model $modelo, array $campos, string $carpeta): void
    {
        foreach ($campos as $campo) {
            if (! $request->hasFile($campo)) {
                continue;
            }

            $column = "{$campo}_path";

            $this->eliminarImagen($modelo->{$column});

            $modelo->update([$column => $request->file($campo)->store("{$carpeta}/{$modelo->id}", 'public')]);
        }
    }

    /**
     * Borra del disco `public` los archivos de las columnas `{campo}_path`.
     * Pensado para el `destroy()` del CRUD: los modelos que usan este trait no
     * tienen SoftDeletes, así que el registro no vuelve y las imágenes no
     * deben quedar huérfanas.
     *
     * @param  list<string>  $campos
     */
    protected function eliminarImagenes(Model $modelo, array $campos): void
    {
        foreach ($campos as $campo) {
            $column = "{$campo}_path";

            $this->eliminarImagen($modelo->{$column});
        }
    }

    protected function eliminarImagen(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }
}
