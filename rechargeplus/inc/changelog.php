<?php
declare(strict_types=1);

/*
 * Notes de version, de la plus récente à la plus ancienne.
 * Source unique : affichées dans l'application après une mise à jour et reprises
 * dans le fichier latest.json par outils/publier.php.
 */
return [
    '1.0.0' => [
        'date'  => '2026-11-21',
        'titre' => 'RechargePlus : première version',
        'notes' => [
            'Suite de « Recharge » (version 2.x) : accès mobile par code PIN et/ou biométrie, appareils enrôlés par QR code, verrouillage automatique, modes identifiant unique / utilisateurs distincts.',
            'Reprise de vos données depuis Recharge : « Export complet » dans Recharge 2.7, puis Administration > Importer depuis Recharge.',
            'Saisie hors ligne, statistiques, odomètre, recharges hors budget, géolocalisation désactivable, mise à jour en un clic : tout ce que faisait Recharge.',
        ],
    ],
];
