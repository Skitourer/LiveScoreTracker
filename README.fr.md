# LiveScoreTracker

*[English version](README.md)*

LiveScoreTracker gère une compétition multisports d'une journée pour les enfants : inscriptions, accueil, saisie des résultats à chaque atelier depuis le téléphone des arbitres (même sans réseau), calcul automatique des points et des classements par catégorie, contrôles, écrans d'avancement et remise des prix.

C'est une application web pensée d'abord pour le téléphone, en PHP (Slim 4) avec MySQL, prévue pour un hébergement mutualisé bon marché et des bénévoles équipés de téléphones ordinaires.

> État : en développement. Première utilisation réelle prévue au printemps 2027. Tout ce dépôt utilise uniquement des **données fictives**.

## Fonctions

- **Inscriptions** : formulaire public en ligne en 10 langues avec vérification par email, liste d'attente et lien de désistement ; saisie par l'admin, import et export Excel, dossards, emails de confirmation (dans la langue de la famille et en français).
- **Accueil** : liste d'accueil imprimable (PDF), arrivée et départ sur un téléphone.
- **Saisie des résultats** sur le téléphone des arbitres : essais, nuls, modificateurs, chrono intégré pour 1 ou 2 coureurs, mode compteur, corrections avec historique complet.
- **Hors ligne** : le téléphone construit lui-même ses écrans de saisie, le suivi et l'aide à partir du « paquet de l'atelier », garde les saisies et les envoie au retour du réseau.
- **Points et classements** : points par épreuve selon des règles configurables, totaux et départage (premières places, places dans le top 3, plus jeune, puis à la main).
- **Contrôles** : résultats « à vérifier », valeurs loin de la médiane, mauvaise distance probable, égalités à trancher, saisies arrivées après la clôture.
- **Documents** : feuilles de secours pour la saisie papier (par épreuve, triées par dossard), fiches des épreuves, podiums et classements complets (PDF), classements et résultats détaillés (Excel).
- **Affichages** : page d'accueil publique, espace participant (dossard + date de naissance), écran d'avancement pour un vidéoprojecteur, écran de cérémonie piloté depuis un téléphone.
- **Sécurité et vie privée** : rôles et permissions, double authentification des admins, limitation des tentatives de connexion, protection CSRF, politique de sécurité du contenu stricte, journal d'audit, prénom + initiale affichés partout où c'est possible.
- Interface en **français et en anglais** ; tout ce qui est propre à une édition (ateliers, barèmes, écoles, textes) est dans des fichiers de configuration.

## Démarrage rapide

Prérequis : PHP 8.2 ou plus avec `pdo_sqlite` (ou `pdo_mysql`), `mbstring` et `intl` ; Composer. Node.js ne sert qu'aux vérifications du JavaScript et du CSS.

```bash
git clone https://github.com/Skitourer/LiveScoreTracker.git
cd LiveScoreTracker
composer install
cp .env.example .env          # SQLite dans data/ par défaut ; mettre une longue chaîne aléatoire dans DEPLOY_TOKEN
php bin/console.php migrate
php -S 127.0.0.1:8000 -t public public/index.php
```

Ouvrir http://127.0.0.1:8000/setup pour créer le premier compte admin (le jeton de déploiement du `.env` est demandé une fois). Ensuite, la page **Données de test** (depuis la page de phase de la compétition) remplit la base avec 100 enfants fictifs et, si on le souhaite, des résultats réalistes.

La compétition d'exemple se trouve dans [`config.example/`](config.example/) : `competition.toml` (édition, niveaux, ateliers, barèmes), traductions, pages d'aide et pages publiques. Une installation les remplace par ses propres fichiers dans le dossier de données.

## Vérifications

```bash
composer qa      # style (PHP-CS-Fixer), analyse statique (PHPStan niveau 9), tests (PHPUnit)
npm ci && npm run lint   # ESLint, Stylelint, Prettier pour public/assets
```

L'intégration continue lance les mêmes vérifications, plus les contrôles de confidentialité, à chaque push et pull request.

## Documentation

- [Cahier des charges](CAHIER_DES_CHARGES.md) (miroir anglais : [Specification](SPECIFICATION.md)) : ce que fait l'application et pourquoi.
- [Documentation pour les développeurs](docs/development/README.md) (en anglais) : architecture, organisation du code, cœur métier, saisie hors ligne.
- [Contribuer](CONTRIBUTING.md), [Sécurité](SECURITY.md), [Code de conduite](CODE_OF_CONDUCT.md), [Historique des versions](CHANGELOG.md) (en anglais).

Les guides complets d'utilisation et d'installation (français et anglais) viendront une fois l'application stabilisée.

## Licence

[MIT](LICENSE)
