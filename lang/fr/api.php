<?php

return [
    'create' => 'Créer un accès API',
    'create_help' => 'Choisissez les données accessibles à cette application. Un accès API ne donne jamais plus de droits que votre compte.',
    'unavailable' => 'La création d’un accès nécessite une API activée et un compte avec connexion locale autorisée. Vous pouvez toujours révoquer vos accès existants.',
    'name' => 'Nom de l’application',
    'lifetime' => 'Durée de validité en minutes',
    'lifetime_help' => 'La durée est limitée par le réglage du site, avec un maximum de 30 jours.',
    'rights' => 'Données et actions autorisées',
    'rights_help' => 'Les permissions du compte et la disponibilité des extensions sont vérifiées à chaque requête.',
    'password' => 'Mot de passe actuel',
    'code' => 'Code de double authentification',
    'created' => 'Votre accès API est prêt',
    'copy_once' => 'Copiez ce secret maintenant et conservez-le dans l’application concernée. Il ne sera plus affiché. Toute personne qui le possède peut utiliser les droits sélectionnés.',
    'secret' => 'Jeton Bearer',
    'back' => 'Retour à mes accès API',
    'scopes' => [
        'profile' => ['label' => 'Lire mon profil', 'description' => 'Identifiant, pseudo, rangs et date de création de votre compte.'],
        'games' => ['label' => 'Lire mes identités de jeu', 'description' => 'Identifiants, pseudos et état de vérification de vos profils de jeu.'],
        'email' => ['label' => 'Lire mon adresse e-mail', 'description' => 'Adresse e-mail associée à votre compte.'],
        'site_news' => ['label' => 'Lire les actualités publiques', 'description' => 'Consulter les actualités publiées et leur contenu public. Les brouillons et contenus réservés restent exclus.'],
        'site_servers' => ['label' => 'Lire l’état public des serveurs', 'description' => 'Consulter la disponibilité et le nombre de joueurs des serveurs publics. Aucun accès aux adresses internes, aux secrets ou aux commandes.'],
    ],
];
