<?php

return [
    'title' => 'Conservación de datos',
    'description' => 'Elige cuánto tiempo conservar las versiones antiguas de tus contenidos.',
    'saved' => 'Se han guardado los ajustes de limpieza.',
    'command_description' => 'Limpiar las versiones antiguas y los datos temporales según la política de conservación configurada',
    'schedule_help' => 'Estos plazos se aplicarán en la próxima limpieza. Con las tareas automáticas activas, el mantenimiento se ejecuta cada hora. También puedes realizar una limpieza manual.',
    'history_title' => 'Historial del contenido',
    'other_cleanups' => 'Otras limpiezas',
    'fields' => [
        'history' => [
            'label' => 'Duración del historial, en días',
            'help' => 'Las versiones más antiguas se eliminan automáticamente.',
        ],
        'temporary' => [
            'label' => 'Archivos sin usar, en días',
            'help' => 'Imágenes retiradas de tus contenidos y de su historial, accesos de invitados caducados.',
        ],
        'technical' => [
            'label' => 'Envíos y tareas antiguos, en días',
            'help' => 'Envíos de notificaciones antiguos y tareas finalizadas. Se conservan las tareas que necesitan otro intento.',
        ],
    ],
    'history_limit' => 'Número de versiones que conservar por contenido',
    'history_limit_help' => 'Cuando se supera este límite, se eliminan las versiones más antiguas.',
    'preserved_help' => 'Se conservan tus borradores, tus contenidos actuales y las imágenes que aún se utilizan.',
    'audit_link' => 'Configurar la conservación del registro de actividad',
];
