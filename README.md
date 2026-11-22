# RechargePlus

Application web personnelle (PHP 8 + MySQL/MariaDB) de suivi du budget de recharge d'un véhicule électrique : saisie des kWh (AC/DC), tarifs, TVA, budget mensuel avec report, estimation des kWh restants. Interface en français, montants en EUR, conçue pour Android (Chrome) avec une page d'administration sur PC.

## Installation rapide (hébergement PHP, ex. Alwaysdata)
1. Créez une base MySQL/MariaDB et un utilisateur.
2. Téléchargez `installer.php` depuis la [dernière version](../../releases/latest), déposez-le à la racine de votre site avec un fichier `installer_code.txt` (un code de votre choix, 8 caractères minimum).
3. Ouvrez `https://votre-site/installer.php`, saisissez le code : l'application est téléchargée, vérifiée (signature) et installée, puis l'assistant de configuration démarre.

## Installation manuelle (sans l'installeur)
1. Téléchargez le zip de la [dernière version](../../releases/latest) et décompressez-le.
2. Créez une base MySQL/MariaDB et un utilisateur sur votre hébergement.
3. Copiez le dossier `rechargeplus/` par FTP.
4. Créez par FTP le fichier `inc/code_installation.txt` contenant un code de votre choix (8 caractères minimum).
5. Ouvrez `https://votre-site/rechargeplus/` : l'assistant demande le code, la base, le budget, les tarifs et le mot de passe.
6. Contrôlez la page **Diagnostic**.

## Mise à jour
L'application vérifie chaque jour la présence d'une nouvelle version (fichier `latest.json` signé) et affiche les nouveautés. Un clic sur **Installer** (page *Mises à jour*) suffit : paquet vérifié, code actuel sauvegardé, retour arrière possible, base mise à jour automatiquement. À défaut, copie manuelle par FTP (sans toucher à `inc/local.php`).

## Pour le mainteneur
Voir `GUIDE_GITHUB.md` (clés de signature, publication d'une version).
