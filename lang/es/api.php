<?php

return [
    'create' => 'Crear acceso a la API',
    'create_help' => 'Elige los datos a los que puede acceder esta aplicación. Un acceso a la API nunca concede más permisos que tu cuenta.',
    'unavailable' => 'Para crear un acceso, la API debe estar habilitada y tu cuenta debe permitir el inicio de sesión local. Puedes seguir revocando los accesos existentes.',
    'name' => 'Nombre de la aplicación',
    'lifetime' => 'Duración de validez en minutos',
    'lifetime_help' => 'La configuración del sitio limita la duración a un máximo de 30 días.',
    'rights' => 'Datos y acciones permitidos',
    'rights_help' => 'Los permisos de la cuenta y la disponibilidad de las extensiones se comprueban en cada solicitud.',
    'password' => 'Contraseña actual',
    'code' => 'Código de autenticación de dos factores',
    'created' => 'Tu acceso a la API está listo',
    'copy_once' => 'Copia este secreto ahora y guárdalo en la aplicación correspondiente. No volverá a mostrarse. Cualquier persona que lo tenga puede utilizar los permisos seleccionados.',
    'secret' => 'Token Bearer',
    'back' => 'Volver a mis accesos a la API',
    'scopes' => [
        'site_news' => ['label' => 'Leer las noticias públicas', 'description' => 'Consultar las noticias publicadas y su contenido público. Los borradores y el contenido restringido quedan excluidos.'],
        'site_servers' => ['label' => 'Leer el estado público de los servidores', 'description' => 'Consultar la disponibilidad y el número de jugadores de los servidores públicos. Sin acceso a direcciones internas, secretos ni comandos.'],
        'profile' => [
            'label' => 'Leer mi perfil',
            'description' => 'Identificador, nombre de usuario, rangos y fecha de creación de tu cuenta.',
        ],
        'games' => [
            'label' => 'Leer mis identidades de juego',
            'description' => 'Identificadores, nombres de usuario y estado de verificación de tus perfiles de juego.',
        ],
        'email' => [
            'label' => 'Leer mi dirección de correo electrónico',
            'description' => 'La dirección de correo electrónico asociada a tu cuenta.',
        ],
    ],
];
