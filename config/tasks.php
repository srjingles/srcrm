<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Estados terminales de una tarea
    |--------------------------------------------------------------------------
    |
    | Etiquetas del campo personalizado `status` que significan "esta tarea ya no
    | está viva". Se usan para EXCLUIR tareas de los sitios donde solo interesa lo
    | pendiente: el resumen diario por correo (DigestService) y "mis tareas" del
    | chat (MyTasksService).
    |
    | El juego de estados de un equipo es configurable —un addon puede redefinirlo,
    | traducirlo o ampliarlo—, así que comparar contra la cadena "Done" incrustada
    | en el código deja de funcionar en silencio en cuanto alguien lo cambia: los
    | servicios siguen corriendo, pero dejan de excluir nada y los correos empiezan
    | a listar tareas terminadas. Por eso vive aquí y admite varias etiquetas (p. ej.
    | completada Y cancelada).
    |
    | El valor por defecto es el estado que siembra la propia app
    | (App\Enums\CustomFields\TaskField::STATUS).
    |
    */

    'closed_status_labels' => ['Done'],

];
