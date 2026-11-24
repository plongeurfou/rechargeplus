<?php
declare(strict_types=1);

/*
 * Notes de version, de la plus récente à la plus ancienne.
 * Source unique : affichées dans l'application après une mise à jour et reprises
 * dans le fichier latest.json par outils/publier.php.
 */
return [
    '1.0.1' => [
        'date'  => '2026-11-23',
        'titre' => 'Compatibilité iPhone et enrôlement par code',
        'notes' => [
            'La biométrie ne se lance plus toute seule : elle démarre quand on touche le bouton (obligatoire sur Safari / iOS).',
            'Nouvel enrôlement par code à 8 chiffres, affiché sous le QR code (valable 5 minutes, 10 essais maximum par 15 minutes) : utile pour une application installée sur l\'écran d\'accueil, où le QR code ne peut pas s\'ouvrir.',
        ],
    ],
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
