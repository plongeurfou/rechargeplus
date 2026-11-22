# Guide GitHub — mise en place (à faire une fois)

## 1. Compte et dépôt
1. Créez un compte sur github.com et **activez l'authentification à deux facteurs** (Settings → Password and authentication). Votre compte est ce qui protège vos collègues.
2. *New repository* → nom par ex. `rechargeplus` → **Public** → créez-le (sans README).
3. Envoyez le contenu de ce dossier (`rechargeplus/`, `outils/`, `.gitignore`, `README.md`, `GUIDE_GITHUB.md`) : page du dépôt → *uploading an existing file* → glissez les fichiers → *Commit changes*.
   Vérifiez que `inc/local.php`, `inc/config.php` et tout fichier `.key` n'apparaissent pas.

## 2. Clés de signature (une seule fois, sur votre PC, PHP en ligne de commande avec sodium)
```
php outils/generer_cles.php cle_privee_rechargeplus.key
```
- Le fichier `.key` est votre **clé privée** : sauvegardez-le (coffre de mots de passe, clé USB), ne le mettez **jamais** sur GitHub. Si vous le perdez, vos collègues devront réinstaller une version avec une nouvelle clé publique.
- La commande affiche la **clé publique**.

## 3. Deux constantes à renseigner dans `rechargeplus/inc/version.php`
```php
const UPDATE_MANIFEST_URL = 'https://github.com/VOTRE_COMPTE/VOTRE_DEPOT/releases/latest/download/latest.json';
const UPDATE_PUBLIC_KEY   = 'la clé publique affichée à l\'étape 2';
```
Modifiez le fichier sur GitHub (icône crayon) ou en local, puis recommencez l'envoi.

## 4. Publier la version 2.1.0
```
php outils/publier.php --cle=cle_privee_rechargeplus.key
```
Le dossier `dist/` contient `rechargeplus-2.1.0.zip` et `latest.json`. Sur GitHub : *Releases → Draft a new release* → tag `v2.1.0` → titre indiqué par l'outil → glissez **les deux fichiers** → *Publish release* (cochée « latest »).

## 5. Votre propre installation
Installez d'abord la 2.1.0 chez vous à la main (copie FTP par-dessus la V2). Elle contient les constantes de l'étape 3.

## 6. Tester la détection
1. Dans `changelog.php`, ajoutez une entrée `2.1.1`, passez `APP_VERSION` à `2.1.1`, puis `php outils/publier.php --cle=...` et publiez la release `v2.1.1`.
2. Sur votre installation 2.1.0 : page *Mises à jour* → *Vérifier maintenant* → la 2.1.1 apparaît avec ses nouveautés.
3. Copiez les fichiers 2.1.1 : le bandeau de nouveautés s'affiche.

## Pour chaque future version
Mettre à jour `APP_VERSION` et `changelog.php`, lancer `publier.php`, créer la release avec les deux fichiers. Tant que la release n'est pas publiée comme « latest », personne n'est notifié.

## Bon à savoir
- Les collègues n'ont besoin d'aucun compte GitHub.
- Aucune donnée n'est envoyée à GitHub à part la requête HTTPS (version de l'app dans le User-Agent).
- Pas d'automatisation GitHub Actions pour signer : la clé privée resterait chez GitHub et affaiblirait la protection.


---

# Depuis la version 2.4 : publication avec installeur et mise à jour en un clic

`php outils/publier.php --cle=cle_privee_rechargeplus.key` produit maintenant **trois** fichiers dans `dist/` :

| Fichier | Rôle |
|---|---|
| `rechargeplus-X.Y.Z.zip` | le paquet de l'application |
| `latest.json` | manifeste signé (version, nouveautés, adresse et empreinte du zip) |
| `installer.php` | installeur autonome pour les nouveaux utilisateurs |

Joignez les trois à la release GitHub (tag `vX.Y.Z`, « latest »).

## Ce que font vos collègues (sans FTP de dizaines de fichiers)
**Première installation** : télécharger `installer.php` depuis la page de la release, le déposer à la racine de leur site avec un fichier `installer_code.txt` (un code de leur choix, 8 caractères minimum), ouvrir `https://leur-site/installer.php`, saisir le code. L'installeur télécharge la dernière version, vérifie la signature et l'empreinte, installe le dossier `rechargeplus/`, puis se supprime et mène à l'assistant de configuration.

**Mises à jour** : page *Mises à jour* → **Installer la version X maintenant**. L'application vérifie la signature et l'empreinte, sauvegarde le code actuel, remplace les fichiers (jamais `inc/local.php`) et la base se met à jour toute seule. Un bouton **Revenir à la version précédente** reste disponible.

## Points d'attention
- Passage de la 2.3 à la 2.4 : à faire **une dernière fois par FTP** (la 2.3 ne sait pas encore s'installer toute seule). Ensuite, tout se fait depuis l'application.
- La mise à jour en un clic nécessite que PHP puisse écrire dans les dossiers de l'application (c'est le cas chez Alwaysdata) ; la page *Diagnostic* l'indique. Sinon, l'installation manuelle reste proposée.
- Seule une release **signée avec votre clé privée** peut être installée : gardez la clé privée en lieu sûr et activez l'authentification à deux facteurs sur GitHub.
- Les fichiers supprimés d'une version à l'autre ne sont pas effacés chez l'utilisateur (ils restent inutilisés).
