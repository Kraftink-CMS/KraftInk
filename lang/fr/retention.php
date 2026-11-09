<?php

return [
    'title' => 'Conservation des données',
    'description' => 'Choisissez combien de temps garder les anciennes versions de vos contenus.',
    'saved' => 'Les réglages de nettoyage ont été enregistrés.',
    'command_description' => 'Nettoyer les anciennes versions et les données temporaires selon la conservation configurée',
    'schedule_help' => 'Ces durées s’appliqueront au prochain nettoyage. Avec les tâches automatiques actives, la maintenance passe chaque heure. Un nettoyage manuel reste possible.',
    'history_title' => 'Historique des contenus',
    'other_cleanups' => 'Autres nettoyages',
    'fields' => [
        'history' => [
            'label' => 'Durée de l’historique, en jours',
            'help' => 'Les versions plus anciennes sont supprimées automatiquement.',
        ],
        'temporary' => [
            'label' => 'Fichiers inutilisés, en jours',
            'help' => 'Images retirées de vos contenus et de leur historique, accès invités expirés.',
        ],
        'technical' => [
            'label' => 'Anciens envois et tâches, en jours',
            'help' => 'Anciens envois de notifications et tâches terminées. Les tâches à reprendre restent conservées.',
        ],
    ],
    'history_limit' => 'Nombre de versions à garder par contenu',
    'history_limit_help' => 'Au-delà de cette limite, les versions les plus anciennes sont supprimées.',
    'preserved_help' => 'Vos brouillons, vos contenus actuels et les images encore utilisées sont conservés.',
    'audit_link' => 'Régler la conservation du journal des actions',
];
