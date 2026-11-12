# LiveScoreTracker — Cahier des charges

> Version 1.14 — 12 novembre 2026
> Ce document sert de référence pour le développement, réalisé étape par étape sous la supervision du porteur du projet.
> Version anglaise : [SPECIFICATION.md](SPECIFICATION.md), tenue à jour en miroir.

---

## 1. Contexte et objectifs

Une association sportive scolaire (ci-après « l'organisateur ») organise chaque année une **compétition multisports individuelle** pour environ 100 enfants d'écoles primaires (CP à CM2), sur une matinée (environ 3 h).

Jusqu'ici, la gestion repose sur un formulaire en ligne, des tableurs et des feuilles papier. **LiveScoreTracker** doit remplacer ce dispositif par une application web unique qui couvre :

- les **inscriptions** en ligne ;
- l'**accueil** des enfants le jour J (émargement, dossards, médailles) ;
- la **saisie des résultats** sur le terrain, depuis les téléphones des arbitres, y compris sans réseau ;
- le **calcul automatique des scores** et des classements ;
- les **affichages** (avancement, cérémonie, page de chaque enfant) ;
- les **documents** à imprimer (listes, feuilles de secours, podiums).

Le logiciel est **open source** (licence MIT). Tout ce qui est propre à l'organisateur (nom, épreuves, barèmes, textes, langue) doit être **configurable**, pour qu'un autre organisateur puisse le réutiliser sans toucher au code.

---

## 2. Glossaire

| Terme | Définition |
|---|---|
| **Édition** | Une compétition d'une année donnée (ex. « Édition 2027 »). |
| **Niveau** | Niveau scolaire : CP, CE1, CE2, CM1, CM2. |
| **Groupe de niveaux** | Ensemble de niveaux qui font les mêmes épreuves (ex. « CP-CE1 », « CE2-CM2 »). |
| **Genre** | Fille ou Garçon. |
| **Catégorie** | Combinaison niveau + genre (ex. « Filles CM1 »). 10 catégories par défaut. C'est l'unité de calcul des scores et des classements. |
| **Atelier** | Un poste physique de la compétition (ex. « Sprint »). Un atelier peut servir plusieurs groupes de niveaux. |
| **Variante** | La déclinaison d'un atelier pour un groupe de niveaux (ex. « Sprint 30 m » pour CP-CE1, « Sprint 40 m » pour CE2-CM2). C'est l'**épreuve** au sens du classement. |
| **Essai** | Une tentative d'un enfant sur une épreuve. Une épreuve peut autoriser plusieurs essais. |
| **Résultat brut** | La performance mesurée : temps, distance ou score. |
| **Score** | Les points (0 à 1000) attribués à un résultat brut par le barème. |
| **Essai nul** | Essai sans performance valide (ligne franchie, chute…). |
| **DNF** | *Did Not Finish* : épreuve non réalisée par un enfant présent au moment de la clôture. |
| **Dossard** | Numéro unique attribué à un enfant pour l'édition. |
| **Arbitre de table** | Bénévole qui saisit les résultats sur un atelier. |

---

## 3. Rôles et droits

### 3.1 Les rôles

| Rôle | Authentification | Appareil principal |
|---|---|---|
| **Admin** (arbitre principal) | Compte individuel + mot de passe + **2FA obligatoire** | Ordinateur et téléphone |
| **Organisateur** | Compte individuel + mot de passe | Téléphone |
| **Arbitre de table** | **Compte partagé** unique (identifiant + mot de passe communs) | Téléphone |
| **Participant** | Numéro de dossard + date de naissance | Téléphone (enfant ou parent) |
| **Public** | Aucune (liens dédiés sans données personnelles) | Écran ou vidéoprojecteur |

Il est recommandé d'avoir **au moins 2 admins**, pour permettre la réinitialisation mutuelle de la 2FA.

### 3.2 Matrice des droits

| Fonction | Admin | Organisateur | Arbitre de table | Participant |
|---|:-:|:-:|:-:|:-:|
| Configuration (épreuves, barèmes, textes, aide) | ✅ | | | |
| Changement de phase (préparation, en cours, clôture, réouverture) | ✅ | | | |
| Gestion des comptes | ✅ | | | |
| Ouvrir/fermer les inscriptions manuellement | ✅ | | | |
| Valider la liste d'attente, dépasser le quota | ✅ | | | |
| Import Excel (inscrits, résultats de secours) | ✅ | | | |
| Restauration de sauvegarde, purge | ✅ | | | |
| Email général aux inscrits | ✅ | | | |
| Consulter les inscriptions | ✅ | ✅ | | |
| Corriger une inscription (faute de frappe) | ✅ | ✅ | | |
| Ajouter un enfant sur place (jour J) | ✅ | ✅ | | |
| Désinscrire un enfant | ✅ | | | |
| Émargement entrée et sortie | ✅ | ✅ | | |
| Saisie/correction de résultats — toutes épreuves | ✅ | ✅ | | |
| Saisie/correction de résultats — épreuve choisie | ✅ | ✅ | ✅ | |
| Liste des dossards restants (épreuve) | ✅ | ✅ | ✅ | |
| Page d'aide de l'atelier | ✅ | ✅ | ✅ | |
| Écrans de suivi (avancement, épreuves manquantes, contrôles) | ✅ | ✅ | | |
| Résultats et scores par épreuve/catégorie, classements | ✅ | ✅ | | |
| Impression (listes, feuilles papier, podiums) | ✅ | ✅ | | |
| Pilotage de l'écran de cérémonie | ✅ | ✅ | | |
| Validation des actions proposées par Claude | ✅ | ✅ | | |
| Journal d'audit | ✅ | | | |
| Page personnelle de l'enfant | | | | ✅ (la sienne) |

Le compte **arbitre de table** n'est actif que pendant la phase « compétition en cours ».

---

## 4. Cycle de vie d'une édition

```
 Préparation ──► Compétition en cours ──► Compétition terminée ──► Purge RGPD (J+30)
      ▲                  ▲     │                    │
      │                  └─────┼── réouverture ◄────┘
      └── retour possible ─────┘   (admin, tracée)
```

| Phase | Saisie des résultats | Configuration | Vue participant |
|---|---|---|---|
| **Préparation** | Fermée (sauf tests) | Libre | Liste des épreuves à faire |
| **Compétition en cours** | Ouverte | Modifiable avec garde-fous (§ 10.4) | Résultats bruts + épreuves faites/à faire |
| **Compétition terminée** | **Bloquée** | Bloquée | Résultats, scores, rangs, classement de sa catégorie |

- Le passage à « terminée » est une **validation explicite d'un admin**. Il est précédé d'une **alerte bloquante** qui liste les enfants présents ayant des épreuves manquantes et les égalités non départagées. L'admin confirme en connaissance de cause.
- La **réouverture** (retour à « en cours ») est possible en cas d'oubli. Elle est tracée dans le journal.
- Les inscriptions en ligne ont leur propre état (§ 5.2), indépendant de ces phases.

---

## 5. Inscriptions

### 5.1 Formulaire public d'inscription

Accessible sans compte, sur le site de l'application. Champs :

**Enfant**
- Nom *(obligatoire)*
- Prénom *(obligatoire)*
- Date de naissance *(obligatoire)* — sert aussi de code d'accès à la page participant et de critère de départage.
- Genre *(obligatoire, liste)*
- École *(obligatoire, liste configurable)*
- Classe *(obligatoire, liste : CP, CE1, CE2, CM1, CM2)*

**Adulte responsable présent le jour J**
- Prénom et nom *(obligatoire)*
- Téléphone *(obligatoire)*
- Email *(obligatoire)*

**Cases à cocher**
- *(obligatoire)* « J'ai compris que l'enfant est placé sous l'entière responsabilité de l'adulte l'accompagnant pendant tout l'évènement. »
- *(obligatoire)* « J'ai lu et j'accepte le règlement » (lien vers le règlement).
- *(facultatif)* « Je suis volontaire pour aider à tenir un stand. »

**Notice d'information RGPD** affichée sous le formulaire (cf. § 15).

Contrôles : champs obligatoires, format de l'email (adresse complète avec domaine) et du téléphone (10 chiffres commençant par 0, ou format international « +33 6… » ; un zéro initial perdu par un tableur est rétabli), vérifiés dans le navigateur et sur le serveur, cohérence date de naissance / classe (avertissement), doublon probable (même nom + prénom + date de naissance : refus avec message).

Protection anti-robots **sans service tiers** : champ piège invisible, délai minimal de remplissage, limitation du nombre d'envois par adresse IP.

Un parent peut inscrire **plusieurs enfants** (fratrie) : après une inscription, proposer « inscrire un autre enfant » en conservant les informations de l'adulte.

### 5.2 Ouverture, fermeture, quota

- **Date et heure d'ouverture et de fermeture** configurables. Hors période, le formulaire affiche un message (« ouverture le … » / « inscriptions closes »).
- L'admin peut **forcer l'état** à la main (ouvrir/fermer) indépendamment des dates.
- **Quota** configurable (100 par défaut).

### 5.3 Parcours d'inscription

1. Le parent remplit le formulaire.
2. L'application envoie un **email de vérification** (lien valable **48 h**).
3. Le parent clique : l'inscription est **vérifiée**.
   - Si le quota n'est pas atteint : statut **inscrit**, dossard attribué, **email de confirmation**.
   - Sinon : statut **en liste d'attente**, email « vous êtes en liste d'attente », notification à l'admin.
4. Une inscription non vérifiée sous 48 h **expire**. Seules les inscriptions vérifiées comptent dans le quota. L'admin peut valider manuellement une inscription en suspens.

Statuts d'une inscription : `pending_verification` → `registered` | `waitlisted` → `registered` | `withdrawn` | `expired`. Un enfant désinscrit peut être **réinscrit** par l'admin (ou remis en liste d'attente).

### 5.4 Liste d'attente

- Gérée **manuellement par l'admin** : il fait passer un enfant de la liste d'attente à « inscrit », y compris au-delà du quota.
- Le passage déclenche l'email « votre inscription est validée ».
- **Email de confirmation** : le même email est envoyé quelle que soit l'origine de l'inscription (formulaire public, admin ou organisateur ; case cochée par défaut pour une saisie manuelle, décochée par défaut pour un import). Il rappelle le dossard, la date, le lieu, l'heure d'accueil et la responsabilité de l'adulte, et **remercie les volontaires** de s'être proposés pour tenir un stand. L'admin peut le renvoyer depuis la fiche de l'enfant.

### 5.5 Désistement

Deux voies :
- **Lien de désinscription** dans l'email de confirmation (avec confirmation) ;
- **Désinscription par l'admin** (sur demande du parent).

Dans les deux cas, l'admin est notifié, pour éventuellement valider un enfant de la liste d'attente.

### 5.6 Dossards

- Attribués **dans l'ordre de passage au statut « inscrit »**, à partir de 1 (ou d'un numéro de départ configurable) : chaque nouvel inscrit reçoit le **plus petit numéro libre**.
- **En phase de préparation**, un enfant désinscrit **libère son dossard**, qui est donné au prochain inscrit : le stock de dossards papier est limité. S'il est réinscrit, il reçoit le premier numéro libre.
- **Une fois la compétition commencée**, un enfant désinscrit **garde son dossard** : il n'est plus jamais donné à un autre enfant. S'il est réinscrit, il retrouve son dossard.
- Les ajouts sur place (§ 5.8) reçoivent le premier numéro libre.

### 5.7 Import Excel (secours et migration)

Pour les cas où les inscriptions ont été recueillies ailleurs (formulaire externe, HelloAsso…) :
- Formats acceptés : **.xlsx** et **.csv**.
- **Correspondance des colonnes configurable** dans la configuration (ex. « la colonne genre s'appelle *Catégorie* »).
- **Nettoyage** : suppression des espaces superflus, normalisation de la casse des noms, reconnaissance des variantes de valeurs (« Garçon », « garcon », « G »).
- **Import incrémental** : seuls les **nouveaux** enfants sont ajoutés (identifiés par nom + prénom + classe, et date de naissance si présente). Les dossards existants ne bougent pas.
- **Aperçu avant validation** : lignes nouvelles, lignes déjà connues, lignes en erreur (champ manquant, classe inconnue…), doublons probables.

> **Point ouvert** : origine exacte du fichier utilisé en 2026 (formulaire Google ou HelloAsso) — à vérifier pour tester l'import.

### 5.8 Ajout sur place le jour J

Le règlement interdit officiellement les inscriptions sur place, mais l'application permet à un **admin ou un organisateur** d'ajouter un enfant à tout moment, **sans limite de quota**, avec les mêmes champs que le formulaire (date de naissance facultative dans ce cas).

### 5.9 Écran « Inscriptions » (admin, organisateur)

- Liste complète avec toutes les informations (nom complet, contacts, volontariat…), recherche, filtres (statut, école, classe, genre, volontaires).
- Fiche enfant : consultation, correction, historique des modifications.
- Compteurs : inscrits / quota, liste d'attente, en attente de vérification.
- Export Excel de la liste.

---

## 6. Accueil et émargement

### 6.1 Liste d'accueil imprimable

PDF contenant, pour chaque enfant inscrit : **prénom, nom complet, numéro de dossard** (et classe). **Triée par prénom** (ordre alphabétique, sans tenir compte des accents), avec un intertitre par lettre et le dossard en gros à droite. Affichée à l'entrée pour que les enfants trouvent leur numéro. Son téléchargement est tracé dans le journal (elle contient les noms complets).

### 6.2 Vue mobile d'émargement (admin, organisateur)

1. Saisie du **numéro de dossard** (clavier numérique) ou recherche par nom/prénom.
2. Affichage en grand du **prénom, nom, classe** pour contrôle.
3. **Entrée** : case « dossard remis » (vaut présence) — horodatée.
4. **Sortie** : cases « dossard rendu » et « cadeau + médaille remis » — horodatées.

Compteurs en tête d'écran : présents / inscrits, dossards rendus, cadeaux remis.

Précisions :
- La recherche par nom ne porte que sur le prénom et le nom de l'enfant.
- Après la remise du dossard, ou quand les deux cases de sortie sont cochées, l'écran revient au champ « dossard » pour l'enfant suivant.
- Une case cochée par erreur peut être décochée (avec confirmation). L'arrivée ne peut pas être annulée tant qu'une case de sortie est cochée. Chaque changement est horodaté et enregistré dans l'historique de la fiche, avec le nom de la personne.
- Les cases de sortie ne sont proposées qu'à un enfant pointé à l'arrivée ; seul un enfant inscrit peut être pointé.
- L'émargement s'ouvre en phase « en cours » et reste possible après la clôture (remise des médailles) ; en préparation, l'écran est en lecture seule.
- Des listes, avec leur effectif sur chaque bouton : **tous les dossards** (triés par numéro, avec l'état de chacun, y compris les désinscrits qui gardent leur dossard), **présents**, **absents** (inscrits sans dossard remis), **pas encore partis** (dossard non rendu ou cadeau non remis, pour récupérer les dossards en fin de journée) et **partis**.

### 6.3 Statuts de présence

- **Absent** : inscrit mais dossard jamais remis. Il est exclu de tout (listes de passage, barèmes, classements) et n'apparaît que dans un récapitulatif « absents ».
- **Présent** : dossard remis. Il apparaît dans les listes « dossards restants » des ateliers.

---

## 7. Épreuves : modèle de configuration

### 7.1 Ateliers et variantes

- Un **atelier** (ex. Sprint) a un nom, une page d'aide et une ou plusieurs **variantes**.
- Chaque **variante** est associée à un **groupe de niveaux** et porte ses propres paramètres (distance, plage plausible, etc.). C'est la variante qui constitue une épreuve pour le classement.
- Un atelier peut n'avoir qu'une variante (ex. Football, CP-CE1 uniquement).
- Les **groupes de niveaux** sont configurables (par défaut : CP-CE1 et CE2-CM2).

### 7.2 Paramètres d'une variante

| Paramètre | Valeurs | Exemple |
|---|---|---|
| `measure` | `time`, `distance`, `score`, `counter` | `time` |
| `better` | `lower`, `higher` | `lower` |
| `time_format` | `ss.cc` ou `m:ss.cc` | `ss.cc` |
| `attempts` | entier ≥ 1 | 2 |
| `foul_mode` | `strict` ou `lenient` (bienveillant, § 7.4) | `strict` |
| `plausible_range` | valeurs plausibles (avertissement si hors plage) | `[5.00, 12.00]` |
| `max_value` | (score/compteur) maximum possible | 30 |
| `modifiers` | liste de bonus/pénalités (§ 7.5) | but marqué : −3 s |
| `bibs_per_timer` | 1 ou 2 (§ 8.4) | 2 |
| `label` | texte affiché à la saisie (clé de traduction ou texte) | « 30 m » |

### 7.3 Types de mesure et stockage

| Type | Saisie | Stockage | Affichage |
|---|---|---|---|
| **Temps** | chiffres seuls : `1225` → 12,25 s ; `12324` → 1 min 23,24 s | entier en **centièmes de seconde** | `12,25 s` ou `1 min 23,24 s` |
| **Distance** | chiffres seuls : `1234` → 12,34 m | entier en **centimètres** | `12,34 m` |
| **Score** | entier | entier | `22 pts` |
| **Compteur** | boutons (§ 8.5) | détail de chaque action + total | `3 paniers, 2 cercles, 1 cadre = 22 pts` |

La valeur interprétée est affichée **en grand** avant validation, pour limiter les fautes de frappe.

### 7.4 Essais et essais nuls

- **Plusieurs essais** : tous sont enregistrés. Le **meilleur** est retenu selon le sens de l'épreuve.
- Les essais **ne sont pas forcément consécutifs** : à la saisie d'un dossard, l'application affiche « essai n° 2 sur 3 » et l'historique des essais déjà faits.
- **Essai nul** : un bouton dédié à la saisie. Deux modes, configurables **par variante** :
  - **strict** : l'essai nul consomme un essai ;
  - **bienveillant** : l'essai nul est enregistré (traçabilité) mais ne consomme pas d'essai ; l'application redemande le même numéro d'essai.
- Un enfant dont **tous les essais sont nuls** reçoit le **score du nul** (§ 9.2).

### 7.5 Modificateurs (bonus et pénalités)

- Liste configurable par variante : libellé, valeur, unité de l'épreuve (ex. « But marqué : −3,00 s », « Quille renversée : +2,00 s »).
- À la saisie, chaque modificateur apparaît comme **case à cocher** (ou compteur si applicable plusieurs fois).
- On stocke **le résultat mesuré et les modificateurs séparément**. Le résultat retenu = mesuré + somme des modificateurs.

### 7.6 Mode compteur

Pour les épreuves d'adresse (ex. basket) :
- **Actions** configurables avec leurs points (ex. panier 3, cercle 2, cadre 1).
- **Limite** : soit une **durée** (ex. 1 min 30) avec chrono intégré, soit un **nombre de tirs** (ex. 10) avec un bouton « raté » supplémentaire pour compter les tirs sans point.
- **Un seul essai** en mode compteur.

---

## 8. Saisie des résultats (arbitre de table)

### 8.1 Principes de l'interface

- **Mobile d'abord** : gros boutons, clavier numérique, contrastes forts (utilisation en plein soleil), une main suffit.
- L'arbitre se connecte avec le compte partagé, puis **choisit son atelier**. Le choix est mémorisé sur le téléphone.
- L'**écran reste allumé** pendant la saisie et les chronos.
- Indicateur permanent : **en ligne / hors ligne** et **nombre de saisies en attente d'envoi**.

### 8.2 Flux standard de saisie

1. Saisie du **dossard**.
2. L'application affiche en grand : **prénom + initiale, niveau, variante** (ex. « Léa M. — CE1 → **30 m** »), l'**essai en cours** et l'historique.
3. Contrôles immédiats (§ 10) : dossard inconnu, enfant non pointé à l'accueil, épreuve hors de son groupe, essais épuisés.
4. Saisie du **résultat** (ou bouton **Essai nul**), cases des **modificateurs**.
5. **Validation**. Les avertissements éventuels demandent une confirmation, sans bloquer.

Précisions :
- Après la validation, l'écran revient au champ « dossard » pour l'enfant suivant.
- La saisie n'est ouverte qu'en phase « en cours » (en préparation, les écrans sont consultables mais le formulaire est masqué).
- Un résultat enregistré avec un avertissement confirmé est marqué **« à vérifier »** (tableau de bord des contrôles, étape 8).
- Chaque saisie porte un identifiant unique créé avec le formulaire : un double envoi (double appui, réseau lent) n'enregistre qu'une fois.
- Un enfant non pointé peut être **marqué présent** depuis l'écran de saisie, après confirmation.
- Les épreuves à temps se saisissent par **course** (§ 8.4) et les épreuves en mode compteur avec le **compteur** (§ 8.5). L'écran d'un seul enfant reste disponible pour consulter et corriger ses essais.

### 8.3 Correction

- L'arbitre peut **corriger un résultat déjà saisi** sur son atelier (recherche par dossard).
- Une correction **ne remplace pas** la saisie : elle crée une nouvelle version. L'historique complet (qui, quand, ancienne valeur) est conservé et visible par l'admin.
- Suppression possible d'un essai saisi par erreur (même principe : marquage, pas d'effacement physique).
- Sous le formulaire, la liste des essais montre pour chacun le résultat, les modificateurs, l'heure et l'auteur (prénom de l'arbitre), les versions avant correction (barrées) et les essais supprimés.

### 8.4 Chrono intégré (1 ou 2 dossards)

Pour les épreuves à départ arrêté (sprint, haies, hockey, parcours, football…), selon la configuration de la variante :

**Mode chrono intégré**
1. L'arbitre saisit **1 ou 2 dossards** (selon `bibs_per_timer`). Contrôle visuel des prénoms et des variantes.
2. Bouton **Départ** (au signal) → il devient **Arrivée**.
3. Un appui sur **Arrivée** à chaque passage de ligne. Avec 2 dossards, l'application enregistre deux temps.
4. L'arbitre indique **l'ordre d'arrivée** : l'application propose l'ordre de saisie, avec un bouton **Inverser**.
5. **Écran de contrôle** : temps de chaque dossard, modificateurs, puis **validation**. Les temps restent modifiables avant validation.

Boutons complémentaires :
- **« N'a pas fini »** (par dossard) : chute, arrêt → essai nul ;
- **« Annuler la course »** : faux départ, tout est remis à zéro sans rien enregistrer.

Le chrono tourne **dans le téléphone** et fonctionne hors ligne. Le bouton Arrivée n'est actif qu'après le Départ.

**Mode chrono classique** : l'arbitre saisit 1 ou 2 dossards puis le temps de chacun, mesuré avec un chronomètre externe.

Les deux modes sont toujours disponibles. Le **chrono intégré est proposé par défaut** sur tous les ateliers à temps (l'ancien réglage `built_in_timer` est ignoré).

Précisions :
- Sur un atelier à temps, l'écran « dossard » propose les champs de la course (le 2ᵉ dossard est facultatif) et deux boutons : **Chrono intégré** (en premier, bouton principal) et **Chrono classique**. Un champ à part ouvre les essais d'un dossard pour les consulter ou les corriger.
- Contrôles avant le départ : dossard inconnu, pas du bon niveau ou saisi deux fois → refusé. Deux enfants de variantes différentes (ex. un CP sur 30 m et un CM1 sur 40 m) → possible, avec un message bien visible.
- Le temps est pris **au moment du toucher** sur le bouton (et non au relâchement), puis arrondi au centième inférieur, comme sur un chronomètre.
- Le premier temps va au premier dossard saisi ; **Inverser** échange les deux temps. Avec un « N'a pas fini », le seul temps va à l'autre enfant.
- La course est validée **en bloc** : rien n'est enregistré tant que les deux temps ne sont pas lisibles et les avertissements (§ 10.1) confirmés.
- Chaque temps garde la trace de son origine : **chrono intégré**, ou **chrono intégré, temps modifié à la main** si l'arbitre l'a changé avant de valider (visible dans l'historique des essais).
- Quitter la page pendant une course demande une confirmation.
- Sans JavaScript, l'écran fonctionne comme le mode chrono classique.

### 8.5 Saisie en mode compteur

1. Saisie du dossard, contrôle.
2. **Démarrer** : compte à rebours (limite par durée) ou compteur de tirs « tir 7/10 » (limite par nombre).
3. Un **gros bouton par action** (+ « raté » en limite par tirs). Total affiché en direct. Bouton **Annuler la dernière action**.
4. Fin automatique (temps écoulé ou dernier tir) avec **signal sonore et vibration**. Les boutons se bloquent.
5. Récapitulatif détaillé, puis validation.

Saisie directe du total possible en secours.

Précisions :
- Un bouton **Terminer maintenant** arrête le comptage avant la fin (incident).
- Le récapitulatif montre le nombre de chaque action dans des champs **modifiables**, avec le total recalculé ; c'est aussi l'écran de correction.
- Le résultat enregistré est le total des points, avec le **détail des actions** (ex. « Panier ×3, Cercle ×2, Cadre ×1 »), affiché dans l'historique des essais.
- Plus de tirs que le nombre autorisé → avertissement (§ 10.1).
- Le son et la vibration dépendent du téléphone (pas de vibration sur iPhone ; pas de son si le téléphone est en silencieux).
- Sans JavaScript, l'arbitre tape directement le nombre de chaque action.

### 8.6 Dossards restants

Écran listant, pour l'atelier choisi, les **enfants présents** des niveaux concernés qui **n'ont pas terminé** l'épreuve (aucun essai, ou essais restants), avec le nombre d'essais déjà faits. Tri par dossard, recherche.

### 8.7 Page d'aide de l'atelier

Accessible à tout moment depuis l'écran de saisie (§ 12.6).

### 8.8 Fonctionnement hors ligne

- L'application est une **PWA** (installable sur l'écran d'accueil, iOS et Android).
- Les données nécessaires à la saisie (liste des enfants : dossard, prénom, initiale, niveau, genre, présence ; configuration des épreuves) sont **mises en cache** sur le téléphone et rafraîchies régulièrement.
- Chaque saisie reçoit un **identifiant unique généré sur le téléphone**. Elle est stockée localement puis **envoyée dès que le réseau revient**. Le serveur ignore un envoi déjà reçu (pas de doublon en cas de renvoi).
- Les conflits (ex. un autre téléphone a déjà saisi le dernier essai autorisé) sont **acceptés et signalés** à l'admin, jamais perdus.
- Les saisies hors ligne arrivées **après la clôture** sont signalées à l'admin et non intégrées automatiquement.

### 8.9 Secours

Trois niveaux de secours en cas de problème :
1. **Hors ligne** sur le téléphone (§ 8.8).
2. **Saisie par l'admin ou un organisateur** depuis leur interface, pour n'importe quelle épreuve et n'importe quel enfant.
3. **Papier, puis import Excel** :
   - **feuilles papier imprimables** (PDF) par atelier et par catégorie : dossard, prénom, nom, colonnes d'essais et de modificateurs ;
   - **modèle Excel** téléchargeable à l'avance (une feuille par variante, colonnes dossard, essais, modificateurs) ;
   - **import** du modèle rempli, avec contrôle et affichage des conflits avec les résultats existants avant validation.

---

## 9. Barème et classements

### 9.1 Population de calcul

Pour chaque **variante** et chaque **catégorie**, le barème est calculé sur les **meilleures performances valides** des enfants **présents** de la catégorie. Les absents, les essais nuls et les DNF ne servent **jamais** de borne.

### 9.2 Calcul du score d'une épreuve

Paramètres (configurables, globalement ou par variante) :
- `max_score` = 1000
- `floor` = score de la moins bonne performance valide (**défaut proposé : 100**)
- `foul_score` = score d'un enfant dont tous les essais sont nuls (**défaut : 1**)
- `dnf_score` = score d'une épreuve non réalisée (**0**)

Avec `best` et `worst` la meilleure et la moins bonne performance valide de la catégorie, et `v` la performance de l'enfant :

```
progression = (v − worst) / (best − worst)        # entre 0 et 1, quel que soit le sens
score       = floor + progression × (max_score − floor)
```

Le score est **arrondi à l'entier le plus proche**. Pour une épreuve « plus petit = meilleur », la formule s'applique telle quelle : `best` < `worst`, et le rapport reste entre 0 et 1.

**Cas limites**
- Un seul enfant avec une performance valide, ou toutes les performances identiques : **1000 points** pour tous.
- Aucun enfant avec une performance valide dans la catégorie : pas de barème. Les nuls reçoivent `foul_score`.

**Remarque** : avec `floor = 1`, on retrouve le barème historique (1 à 1000). Il faut alors fixer `foul_score = 0` pour distinguer le nul de la moins bonne performance. La configuration est **refusée** si `foul_score` n'est pas strictement inférieur à `floor`, ou si `dnf_score` dépasse `foul_score`.

**Exemple** (plancher 100, plus grand = meilleur) : best = 68 m, worst = 8 m, v = 45 m
→ progression = 37/60 = 0,6167 → score = 100 + 0,6167 × 900 = **655 points**.

### 9.3 Statuts par épreuve

| Situation | Score |
|---|---|
| Au moins un essai valide | barème (§ 9.2) |
| Uniquement des essais nuls | `foul_score` |
| Aucun essai à la clôture (enfant présent) | **DNF** → `dnf_score` (0) |
| Enfant absent | exclu du classement |

### 9.4 Rang par épreuve

Dans chaque catégorie, les enfants sont classés par score décroissant sur chaque épreuve. En cas d'égalité de score sur une épreuve, les enfants partagent le même rang.

### 9.5 Classement général

- **Total** = somme des scores de toutes les épreuves du groupe de niveaux.
- **10 classements**, un par catégorie.
- Pas de classement par école.

### 9.6 Départage des égalités au total

Critères appliqués **dans l'ordre**, jusqu'à lever l'égalité :
1. Le plus grand **nombre de 1res places** en épreuve ;
2. Le plus grand **nombre de places dans le top 3** en épreuve ;
3. Le **plus jeune** (date de naissance la plus récente).

Seules les épreuves avec une **performance valide** comptent comme places (un nul ou un DNF n'est jamais une 1re place). Le critère « plus jeune » n'est appliqué que si tous les enfants à départager ont une date de naissance.

Si l'égalité persiste (jumeaux, date de naissance absente), l'application le signale à l'admin, qui **tranche manuellement** avant la clôture.

L'ordre et le choix des critères sont configurables. Le classement indique discrètement le critère qui a départagé (ex. « départagé : 1res places »).

**Il ne peut pas y avoir d'ex æquo sur le podium.**

### 9.7 Recalcul

Les scores et classements sont **toujours recalculés à partir des résultats bruts**. Aucune valeur calculée n'est figée avant la clôture.

---

## 10. Vérifications automatiques

Les vérifications sont des **avertissements non bloquants**, sauf mention contraire. Les résultats concernés sont marqués **« à vérifier »** et regroupés dans un **tableau de bord des contrôles** (admin, organisateur), avec accès direct à la correction et possibilité de marquer « vérifié ».

### 10.1 À la saisie

| Contrôle | Effet |
|---|---|
| Dossard inconnu | **Bloquant** |
| Épreuve hors du groupe de niveaux de l'enfant | **Bloquant** |
| Saisie en phase « terminée » | **Bloquant** |
| Enfant non pointé à l'accueil | Avertissement + proposition de le marquer présent |
| Nombre d'essais déjà atteint | Avertissement (saisie possible, signalée) |
| Valeur hors de la plage plausible de la variante | Avertissement |
| Valeur très éloignée de la médiane de la catégorie (une fois quelques résultats disponibles) | Avertissement |
| **Mauvaise distance probable** : temps plus proche de la distribution de l'autre variante (ex. temps d'un CE1 typique du 40 m) | Avertissement « Distance correcte ? » |
| Doublon probable (même dossard, même épreuve, même valeur à quelques secondes d'intervalle) | Avertissement ; dédoublonnage à la synchronisation |
| Mode compteur : plus de tirs que le nombre autorisé | Avertissement |
| Course : même dossard saisi deux fois | **Bloquant** |

### 10.2 Contrôles globaux

- Enfants présents avec des **épreuves manquantes** (§ 11.3).
- **Égalités non départagées** dans les classements.
- **Conflits de synchronisation** et saisies arrivées après la clôture.
- Enfants ayant **plus d'essais que permis**.

### 10.3 À l'import

Doublons probables, classe incohérente avec la date de naissance, champs obligatoires manquants, valeurs non reconnues.

### 10.4 Modification de la configuration pendant la compétition

Les réglages restent modifiables en phase « en cours », avec trois niveaux de garde-fous :

**Sans effet de bord, recalcul automatique**
- plancher, score du nul, plages plausibles, libellés.

**Avec impact : aperçu puis confirmation**
L'application affiche les enfants concernés avant validation (ex. « 12 enfants vont devoir refaire un essai ») et peut alerter les ateliers concernés.
- **Augmentation du nombre d'essais** : les enfants concernés retrouvent des essais à faire.
- **Diminution du nombre d'essais** : seuls les N **premiers essais** (ordre chronologique) sont retenus. Les autres sont conservés mais exclus.
- **Mode nul strict → bienveillant** : les nuls ne comptent plus, des essais redeviennent disponibles.
- **Mode nul bienveillant → strict** : les nuls comptent, d'où un éventuel dépassement (même règle des N premiers).
- **Ajout d'une épreuve** : tous les enfants concernés l'ont à faire.
- **Changement de classe ou de genre d'un enfant** : ses résultats le suivent, et les catégories concernées sont recalculées.

**Interdit dès qu'il existe des résultats**
- Changement du type de mesure ou de l'unité d'une variante.
- Suppression d'une épreuve : on la **désactive** à la place (résultats conservés mais exclus du calcul).

Toute modification est inscrite au **journal d'audit** (qui, quoi, quand, ancienne et nouvelle valeur).

---

## 11. Écrans de l'admin et des organisateurs

### 11.1 Tableau de bord

- Phase en cours, boutons de changement de phase (admin).
- Compteurs : inscrits, présents, sortis, résultats saisis, résultats à vérifier, saisies en attente de synchronisation.
- Accès rapide à toutes les fonctions.

### 11.2 Résultats par épreuve et catégorie

Pour chaque variante × catégorie : liste des enfants avec **tous les essais**, modificateurs, **résultat retenu**, **score**, **rang**, statut (valide, nul, DNF, à vérifier). Correction possible depuis cet écran.

### 11.3 Épreuves manquantes

- Pour chaque atelier : la liste des **enfants présents qui ne l'ont pas faite** (dossard, prénom, nom, catégorie), en gros caractères, lisible pour un **appel à la sono**.
- Vue inverse : pour chaque enfant, les épreuves qui lui manquent.
- Filtres par atelier et par catégorie.

### 11.4 Classements

Classement général de chaque catégorie : rang, prénom, nom, total, score par épreuve, critère de départage éventuel.

### 11.5 Avancement

Version détaillée de l'écran public (§ 13.1) : par atelier et par catégorie.

### 11.6 Journal d'audit (admin)

Toutes les actions sensibles : connexions, saisies et corrections (avec le téléphone d'origine pour le compte partagé), changements de configuration et de phase, imports, restaurations, propositions de Claude et leur validation.

---

## 12. Configuration

### 12.1 Fichiers

Le dépôt GitHub ne contient que des **exemples fictifs** et les **textes génériques**. La configuration réelle d'une installation vit dans un **dossier de données** propre à chaque serveur, hors du dépôt (cf. § 25).

```
Dépôt GitHub                         Serveur (dossier de données, hors dépôt)
config.example/                      data/
  competition.toml   (fictif)          competition.toml   (réel : nom, date, lieu, écoles, contacts…)
  i18n/                                i18n/              (surcharges éventuelles des textes)
    fr.toml                            help/              (pages d'aide réelles)
    en.toml                            media/             (plans d'ateliers)
  help/                                backups/
    fr/sprint.md …                     logs/
    en/…                             .env                 (secrets)
```

- Tant que le dossier de données ne contient pas de `competition.toml`, l'application utilise l'**exemple fictif** de `config.example/` (affiché comme tel sur l'accueil). L'écran de configuration l'y **copie** au premier enregistrement, puis l'admin l'adapte. Ainsi, le site d'essai suit toujours l'exemple à jour.
- Les **traductions génériques** (`i18n/`) sont versionnées dans le dépôt. Le dossier de données peut les **surcharger** clé par clé (ex. nom de l'organisateur dans un email).
- Les **secrets** (accès base de données, SMTP, clés API, accès Google Drive) sont dans un fichier `.env` propre à chaque installation, jamais versionné.

### 12.2 `competition.toml`

Les **clés** sont en anglais (comme le code) ; les **libellés** affichés passent par les traductions. Contenu principal :
- informations de l'édition : nom, date, lieu, horaires, organisateur, contact ;
- niveaux, genres, **groupes de niveaux**, ordre d'affichage des catégories ;
- liste des **écoles** proposées ;
- inscriptions : dates d'ouverture et de fermeture, quota, numéro du premier dossard ;
- barème : score maximal, plancher, score du nul, score DNF, critères de départage ;
- **ateliers et variantes** (§ 7.2) ;
- correspondance des colonnes pour l'import Excel ;
- contacts affichés dans les pages d'aide ;
- paramètres de sauvegarde et de purge.

Un exemple complet, **avec des données fictives**, figure en **annexe A**.

### 12.3 Écran de configuration (admin)

- Formulaires pour modifier tous les réglages sans éditer le fichier à la main. L'écran **lit et écrit** `competition.toml`.
- Validation de la configuration avant enregistrement (erreurs explicites).
- Garde-fous de la § 10.4 en phase « en cours ».
- Export et import du fichier de configuration (pour repartir d'une édition précédente ou partager avec un autre organisateur).

### 12.4 Traductions (i18n)

- **Tous** les textes affichés (interface, messages d'erreur, emails, PDF) passent par des **clés** définies dans `i18n/<langue>.toml`.
- Langue par défaut configurable. Aucun texte en dur dans le code.

### 12.5 Textes des emails

Sujets et corps de tous les emails dans les fichiers de traduction, avec des variables (`{prenom_enfant}`, `{date}`, `{lien}`…).

### 12.6 Pages d'aide des ateliers

- Un fichier **Markdown** par atelier et par langue, modifiable depuis l'écran de configuration (éditeur avec aperçu).
- Contenu type : matériel, encadrement, déroulé, règles, essais, cas de l'essai nul.
- **Images** possibles (plan de l'atelier).
- **Bloc « Contacts »** global (ex. « Saisie informatique : … », « Autres problèmes : … ») avec **numéros cliquables** pour appeler. Visible uniquement des comptes connectés (arbitres, organisateurs, admins).

---

## 13. Affichages

### 13.1 Écran d'avancement (public)

- Lien dédié, **sans connexion**, pour un vidéoprojecteur ou une télé. **Aucune donnée personnelle.**
- Pour chaque atelier : nombre d'enfants passés / attendus, barre de progression.
- **Ateliers les moins avancés mis en évidence**, pour orienter les enfants.
- Avancement global. Rafraîchissement automatique (quelques secondes).

### 13.2 Écran de cérémonie

- Disponible **uniquement après la clôture**.
- Affiché sur un grand écran, **piloté depuis le téléphone** d'un admin ou d'un organisateur : « catégorie suivante », « révéler la place suivante ».
- Pour chaque catégorie : révélation du **3e, puis du 2e, puis du 1er**, en **prénom + initiale** avec le total de points.
- Ordre des catégories configurable.

### 13.3 Page participant

Connexion par **dossard + date de naissance**, avec blocage temporaire après plusieurs échecs.

| Phase | Contenu affiché |
|---|---|
| Préparation | Liste des épreuves de son groupe |
| En cours | Épreuves faites avec **résultats bruts** (essais), épreuves **restant à faire**. Pas de scores ni de rangs. |
| Terminée | Résultats bruts, **scores**, **rang par épreuve**, **rang au total**, et **classement de sa catégorie** sous forme de liste (prénom + initiale, scores, rangs). |

Les autres enfants sont toujours affichés en **prénom + initiale du nom**.

---

## 14. Documents générés (PDF et Excel)

| Document | Format | Contenu |
|---|---|---|
| Liste d'accueil | PDF | Prénom, nom complet, dossard, classe — **triée par prénom** |
| Feuilles de secours | PDF | Par atelier et catégorie : dossard, prénom, nom, colonnes d'essais et de modificateurs |
| Modèle de saisie de secours | Excel | Une feuille par variante |
| Podiums | PDF | Les **3 premiers de chaque catégorie** (prénom, nom, total) |
| Classements complets | PDF / Excel | Par catégorie |
| Résultats détaillés | Excel | Tous les essais, scores, rangs |
| Liste des inscrits | Excel | Toutes les informations d'inscription |
| Fiches des épreuves | PDF | Contenu des pages d'aide (impression pour les ateliers) |

---

## 15. Données personnelles (RGPD)

- **Minimisation** : seules les données utiles à l'organisation sont collectées (§ 5.1).
- **Affichage** : nom complet visible uniquement dans les écrans réservés (admin, organisateur, émargement) et sur la liste d'accueil imprimée. Partout ailleurs : **prénom + initiale**.
- **Hébergement** : en France (OVH). Aucune donnée transmise à un tiers.
  - Les copies de sauvegarde sur Google Drive sont **chiffrées** avant l'envoi (§ 16).
  - L'**API** n'expose que des **numéros de dossard** (§ 18).
- **Durée de conservation** : **suppression automatique un mois après la compétition** (§ 17). Seules des statistiques anonymes sont conservées.
- **Notice d'information** sur le formulaire d'inscription : responsable du traitement (l'organisateur), données collectées et finalité, publication des résultats en prénom + initiale, durée de conservation, droits d'accès, de rectification et d'effacement (adresse de contact configurable), hébergeur.
- **Droits des personnes** : l'admin peut exporter ou supprimer les données d'un enfant à la demande.
- **Sécurité** : cf. § 19.

> **Point ouvert** : mise à jour de l'article 12 du règlement, et mention de la publication des résultats.

---

## 16. Sauvegardes

- **Pendant la compétition** : sauvegarde **toutes les 5 minutes**, toutes conservées le jour J.
  - L'hébergement mutualisé limite les tâches planifiées : la sauvegarde est donc **déclenchée par l'activité**. À chaque saisie, si la dernière sauvegarde date de plus de 5 minutes, une nouvelle est faite.
- **Hors compétition** : une sauvegarde par jour (tâche planifiée OVH, ou déclenchement à la première visite).
- **Bouton « Sauvegarder maintenant »** (admin) avec **téléchargement** du fichier.
- **Copie automatique sur Google Drive**, **chiffrée** avec un mot de passe connu des seuls admins, dans un dossier dédié.
- **Restauration** depuis l'interface admin, avec confirmation, et sauvegarde automatique de l'état courant juste avant.
- **Export Excel lisible** de tous les résultats, en complément de la sauvegarde technique.
- Le **journal des saisies** permet de reconstituer toute saisie postérieure à la dernière sauvegarde.
- Les sauvegardes (locales et sur Drive) sont **soumises à la purge** à J+30.

---

## 17. Purge et nouvelle édition

### 17.1 Purge RGPD automatique (J+30)

- **J+23** : email d'avertissement aux admins.
- **J+30** : avant la suppression, archivage des **statistiques anonymes** :
  - nombre de participants par catégorie et par école ;
  - résultats par épreuve **sans nom** (distributions, meilleures performances, moyennes) ;
  - records par épreuve et catégorie.
- Puis **suppression** de toutes les données nominatives : enfants, adultes, emails, journaux nominatifs, sauvegardes locales et copies sur Drive.
- Déclenchement par tâche planifiée OVH, ou à la première visite après l'échéance.

### 17.2 Nouvelle édition

Assistant pour l'admin :
1. Repartir de la configuration de l'édition précédente (épreuves, barèmes, textes, aide).
2. Saisir la nouvelle date, le lieu, les dates d'inscription.
3. Changer le mot de passe du compte partagé des arbitres de table.
4. Remise à zéro : phase « préparation », dossards à partir du numéro de départ.

L'**historique anonyme** des éditions précédentes reste consultable (comparaison des années).

---

## 18. API et intégration de Claude (phase 2)

- **API REST en JSON**, protégée par une **clé** générée par l'admin (révocable).
- **Données exposées uniquement par numéro de dossard** (aucun nom).
- **Lecture** : phase, avancement par atelier et catégorie, résultats bruts et scores, résultats « à vérifier », épreuves manquantes, anomalies, état de synchronisation.
- **Propositions d'action** : l'API permet de **proposer** une action (ex. marquer un résultat « à vérifier », signaler une anomalie). Les propositions arrivent dans une **file d'attente** que l'admin ou un organisateur **valide ou refuse** d'un clic. Rien n'est appliqué sans validation humaine.
- Toutes les propositions et décisions sont tracées dans le journal.
- Phase 1 : l'API est conçue et documentée, et sert déjà en interne (écrans à rafraîchissement automatique). Phase 2 : branchement de Claude pour la surveillance en temps réel.

---

## 19. Sécurité

### 19.1 Authentification

- Mots de passe **hachés** (`password_hash`, Argon2id), **12 caractères minimum** pour les comptes individuels.
- **2FA obligatoire pour les admins** : codes TOTP (application d'authentification : Google Authenticator, Microsoft Authenticator…). Pas de SMS.
- **Appareil de confiance** : en saisissant le code, l'admin peut cocher « faire confiance à cet appareil ». Le code n'est alors plus demandé sur ce navigateur pendant **30 jours** (le mot de passe reste demandé). La confiance est liée au navigateur (cookie secret, haché en base) : un cookie copié sur un autre appareil ne suffit pas. Chaque admin voit ses appareils de confiance et peut les retirer ; un changement de mot de passe, une réinitialisation de la 2FA ou une désactivation du compte les retire tous.
- **10 codes de secours** fournis à l'activation de la 2FA. Un admin peut réinitialiser la 2FA d'un autre admin.
- **Mot de passe oublié** (admin, organisateur) : lien par email, valable **1 heure**, à usage unique.
- **Limitation des tentatives** sur tous les écrans de connexion (y compris dossard + date de naissance) : blocage temporaire progressif. Blocage d'une minute dès **5 échecs** pour un compte (comptés **par téléphone** pour le compte partagé des arbitres, pour qu'une fausse manipulation ne bloque pas tous les téléphones) ou **20 échecs** pour une même adresse IP ; la durée double à chaque nouvel échec, jusqu'à 1 h.
- **Compte partagé des arbitres** :
  - actif uniquement en phase « en cours » ;
  - mot de passe changé à chaque édition ;
  - après la connexion, chaque arbitre **indique son prénom**, enregistré avec chacune de ses actions et de ses saisies ;
  - chaque téléphone est **mémorisé avec le prénom** de son arbitre : après une déconnexion, l'arbitre qui se reconnecte sur le même téléphone retrouve son prénom sans le ressaisir ;
  - l'admin voit **les téléphones connus** (prénom, téléphone, dernière activité, connecté ou non) et peut **déconnecter un arbitre** en particulier, **bloquer définitivement un téléphone** (usage abusif, par exemple un enfant qui modifie des résultats), le débloquer, ou l'oublier ;
  - le blocage repose sur l'identifiant d'appareil (cookie) : effacer les cookies le contourne. Face à une personne déterminée qui connaît le mot de passe, la parade est de **changer le mot de passe des arbitres**, ce qui déconnecte tous les téléphones ;
  - bouton admin « **déconnecter tous les téléphones** » ;
  - chaque saisie enregistre un identifiant d'appareil ;
  - mot de passe de **8 caractères minimum** (il est saisi sur de nombreux téléphones).
- **Création des comptes** : l'admin crée les comptes admin et organisateur avec un **mot de passe provisoire**, à changer obligatoirement à la première connexion.
- **Premier admin** : sur une nouvelle installation, la page `/setup` permet de créer le premier compte admin, une seule fois, avec le jeton `DEPLOY_TOKEN` du fichier `.env` du serveur (l'hébergement mutualisé n'offre pas de ligne de commande).
- **Sessions** enregistrées en base de données : elles expirent après **4 h d'inactivité** (réglable) et au plus tard **16 h** après la connexion. L'admin voit les téléphones connectés au compte des arbitres.

### 19.2 Application

- **HTTPS obligatoire** (certificat gratuit fourni par OVH).
- Requêtes SQL **préparées** (protection contre l'injection).
- **Jetons anti-CSRF** sur tous les formulaires.
- Échappement systématique des sorties (protection XSS), en-têtes de sécurité (CSP, etc.).
- Sessions sécurisées (cookies `HttpOnly`, `Secure`, `SameSite`), expiration.
- Contrôle des droits **côté serveur** sur chaque action (§ 3.2).
- Fichiers de configuration, sauvegardes et `.env` **hors du dossier public** ou protégés.
- Journal des connexions et des actions sensibles.

---

## 20. Architecture technique

### 20.1 Serveur

| Élément | Choix | Raison |
|---|---|---|
| Langage | **PHP 8.2+** | Seul langage serveur de l'hébergement mutualisé OVH Perso |
| Framework | **Slim 4** (micro-framework) | Léger, lisible pour des contributeurs, adapté au mutualisé |
| Gabarits | **Twig** | Échappement automatique, séparation code/affichage |
| Base de données | **MySQL** (incluse dans OVH Perso) en production ; **SQLite** pour le développement et les tests | Le système de fichiers des mutualisés gère mal les écritures concurrentes dans SQLite |
| Accès aux données | PDO + requêtes préparées, migrations versionnées | Portabilité MySQL/SQLite |
| Excel | **PhpSpreadsheet** | Import/export .xlsx et .csv |
| PDF | **Dompdf** | PDF générés à partir de gabarits HTML |
| Emails | **PHPMailer** (SMTP de l'hébergement) | Envoi fiable, envoi par lots |
| TOML | bibliothèque compatible TOML 1.0, lecture **et** écriture | Écran de configuration |
| 2FA | bibliothèque TOTP éprouvée | |
| Google Drive | API Google Drive (compte autorisé une fois par l'admin) | Copie des sauvegardes |
| Markdown | convertisseur Markdown sécurisé | Pages d'aide |
| Dépendances | **Composer** | |

### 20.2 Client (navigateur)

- **HTML rendu par le serveur** + **Alpine.js** pour l'interactivité (chrono, compteur, saisie guidée). Pas de build JavaScript complexe.
- **PWA** : manifeste, *service worker*, cache des ressources ; **IndexedDB** pour les saisies hors ligne et le cache des données.
- **Rafraîchissement automatique** des écrans par interrogation de l'API toutes les quelques secondes (*polling*).
- API **Wake Lock** pour garder l'écran allumé ; vibration et son pour les fins de chrono.
- CSS léger, mobile d'abord, compatible thème clair/sombre, contrastes élevés, construit sur des jetons graphiques (§ 20.6).

### 20.3 Organisation du code

```
/public          point d'entrée web (index.php, assets, service worker)
/src             code PHP (domaine, services, contrôleurs)
  /Domain        modèle métier : barème, départage, essais… (sans dépendance au framework)
/templates       gabarits Twig
/config.example  configuration d'exemple fictive (TOML, traductions, aides)
/migrations      schéma de la base
/tests           tests automatisés (PHPUnit)
/docs            documentation (installation, configuration, contribution)
```

Le **cœur métier** (barème, essais nuls, modificateurs, départage, synchronisation) est isolé et **couvert par des tests automatiques**.

### 20.4 Compatibilité

- Navigateurs mobiles récents : **Safari iOS** et **Chrome Android** (deux dernières versions majeures), navigateurs de bureau récents.
- Interface utilisable sur un écran de 360 px de large.

### 20.5 Performance

- Échelle visée : ~150 enfants, ~15 téléphones simultanés, ~2 000 saisies sur 3 h.
- Temps de réponse d'une saisie < 1 s en conditions normales.
- Rafraîchissement des écrans de suivi : ≤ 10 s.

### 20.6 Habillage graphique

L'apparence reste **sobre** et doit pouvoir être modifiée facilement, sans toucher aux écrans. L'habillage définitif est choisi en fin de développement (étape 14).

- **Jetons graphiques** : toutes les valeurs visuelles (couleur d'accent, neutres, couleurs d'état succès/alerte/erreur, police, tailles de texte, espacements, arrondis) sont des **variables CSS** regroupées dans un seul fichier `tokens.css`, en version claire et sombre. Les styles des composants n'utilisent que ces variables.
- **Garde-fou automatique** : un contrôle **Stylelint** dans l'intégration continue refuse toute couleur ou taille écrite en dur hors du fichier de jetons.
- **Composants peu nombreux et nommés** (bouton, carte, tableau, formulaire, badge, message…), HTML sémantique, **aucun style écrit dans les pages** (la politique de sécurité CSP l'interdit de toute façon).
- **Guide de style** : une page de démonstration (absente en production) affiche tous les composants en mode clair et sombre ; des captures automatiques de cette page permettent de comparer l'avant et l'après d'un changement d'habillage, y compris sur téléphone.
- **Personnalisation par installation** : une section `[theme]` de `competition.toml` (couleur d'accent, logo) surcharge les jetons, comme les traductions surchargent les textes. L'écran de configuration la modifie (étape 12), avec une **vérification automatique du contraste** (niveau WCAG AA) : une couleur illisible est refusée.
- **Sobriété par construction** : une seule couleur d'accent, police du système, aucune police ni bibliothèque graphique externe (plus rapide, conforme au RGPD, compatible avec le mode hors ligne).

---

## 21. Hébergement et déploiement

- **Production** : sous-domaine dédié du site de l'organisateur (ex. `competition.example.org`), hébergement **OVH Perso** (PHP + MySQL), certificat SSL.
- **Site d'essai** : sous-domaine de test (ex. `test.competition.example.org`), avec **sa propre base de données**, pour tester chaque étape en conditions réelles (vrais téléphones, 4G) sans toucher à la production.
- **Déploiement automatisé** via **GitHub Actions** : installation des dépendances, exécution des tests, puis envoi par **SFTP** vers OVH (l'offre Perso propose le SFTP, pas l'accès SSH), et application des migrations de la base par un appel protégé par jeton. Branches d'étape et branche de test → site d'essai ; version validée → production.
- Le déploiement **n'envoie que le code** : le dossier de données, le `.env` et les sauvegardes du serveur ne sont jamais écrasés (§ 25). Les identifiants SFTP et les adresses des serveurs sont des **secrets GitHub**.
- **Documentation d'installation pas à pas** pour l'organisateur (configuration OVH, sous-domaines, base de données, PHP, secrets GitHub) et pour tout autre organisateur.
- Version de PHP fixée par le fichier de configuration OVH (`.ovhconfig`).

---

## 22. Documentation à fournir

La documentation est rédigée **dans un second temps**, une fois l'application stabilisée, **en français et en anglais**.

### 22.1 Fichiers du dépôt

- `README.md` (anglais) et `README.fr.md` (français), avec un lien croisé en tête de chacun : présentation, captures d'écran sur données fictives, démarrage rapide, lien vers la documentation complète.
- `CONTRIBUTING.md` : règles de contribution, conventions, lancement des tests.
- `CHANGELOG.md` : historique des versions.

### 22.2 Site de documentation

- Rédigé en **Markdown** dans le dossier `docs/` du dépôt, donc **versionné avec le code** et relu par *pull request*, ce que ne permet pas le wiki GitHub.
- Généré avec **MkDocs + thème Material** et le module de traduction **mkdocs-static-i18n** : recherche intégrée, navigation, sélecteur de langue FR/EN, mode sombre, lisible sur téléphone.
- Publié automatiquement sur **GitHub Pages** (gratuit) à chaque mise à jour.
- Sections :
  - **Utilisation** : guide admin, guide organisateur, guide arbitre de table (une page, imprimable), guide participant ;
  - **Installation et déploiement** : hébergement mutualisé (OVH en exemple), sous-domaines, base de données, secrets, mises à jour ;
  - **Configuration** : référence complète de `competition.toml`, traductions, pages d'aide ;
  - **Développement** : architecture, cœur métier, API, tests.

### 22.3 Cahier des charges

Le présent document est rédigé en français, avec une **version anglaise en miroir** (`SPECIFICATION.md`) destinée aux contributeurs. **Toute modification de l'un est reportée dans l'autre dans le même commit.** Les deux seront **archivés** (`docs/archive/`) une fois l'application livrée.

---

## 23. Plan de développement par étapes

Chaque étape est livrée sur une branche avec une *pull request*, déployée sur le site d'essai, **testée et validée par le porteur du projet** avant de passer à la suivante.

| Étape | Contenu | Résultat testable |
|---|---|---|
| **0. Socle** | Nettoyage du dépôt, structure, Slim, base de données, **conventions et outils qualité**, **garde-fous de confidentialité** (§ 25.3), déploiement automatique sur le site d'essai, page d'accueil | L'appli « vide » s'affiche sur le site d'essai |
| **1. Configuration et cœur métier** | Lecture du TOML, i18n, modèle des épreuves, **moteur de barème et de départage** + tests automatiques | Rapport de calcul sur des données fictives (page `/demo/report`, absente en production) |
| **2. Comptes et sécurité** | Connexion, rôles, 2FA admin, mot de passe oublié, compte partagé, journal d'audit | Connexion avec chaque rôle |
| **3. Inscriptions (admin)** | Fondations de l'habillage (jetons, Stylelint, guide de style, § 20.6), écran inscriptions, ajout manuel, import Excel, dossards | Import d'un fichier de test fictif |
| **4. Accueil** | Liste d'accueil PDF, émargement entrée/sortie | Émargement sur téléphone |
| **5. Saisie arbitre** | Choix d'atelier, saisie standard, essais, nuls, modificateurs, correction, dossards restants, aide | Saisie complète en ligne |
| **6. Chrono et compteur** | Chrono 1-2 dossards, mode compteur | Essai sur un vrai sprint et un vrai basket |
| **7. Hors ligne** | PWA, file d'attente locale, synchronisation, conflits | Test en mode avion |
| **8. Suivi et classements** | Résultats par épreuve, classements, épreuves manquantes, contrôles, phases et clôture | Simulation d'une compétition complète |
| **9. Participant et affichages** | Page participant, écran d'avancement, écran de cérémonie | |
| **10. Documents et secours** | PDF (podiums, feuilles de secours…), modèle et import Excel de secours, exports | |
| **11. Inscriptions en ligne** | Formulaire public, vérification email, liste d'attente, désistement, emails automatiques | Inscription test de bout en bout |
| **12. Configuration en ligne** | Écran de configuration, éditeur d'aide, personnalisation de l'habillage (couleur, logo), garde-fous en cours de compétition | |
| **13. Sauvegardes et cycle annuel** | Sauvegardes, Drive chiffré, restauration, purge J+30, statistiques, nouvelle édition | |
| **14. Répétition générale** | Choix de l'habillage final, simulation grandeur nature avec des bénévoles, corrections | Go/no-go pour la production |
| **15. Phase 2 — API et Claude** | API publique documentée, propositions d'action, branchement de Claude | |

L'ordre pourra être ajusté.

**Échéance : application complète en production vers la mi-mars 2027** (étapes 0 à 14), date d'ouverture prévue des inscriptions. La phase 2 (étape 15) et la documentation complète (§ 22) viennent ensuite.

---

## 24. Points ouverts

1. **Origine du fichier d'inscription 2026** (formulaire Google ou HelloAsso) : à vérifier pour l'import de reprise.
2. **Valeur par défaut du plancher** : 100 proposé, à valider.
3. **Plages plausibles** de chaque épreuve : à renseigner à partir des résultats 2026.
4. **Règlement 2027** : révision à faire (exemple de calcul de l'article 7 erroné, barème avec plancher, nuls, DNF, départage, article 2 sur les inscriptions, article 12 RGPD).
5. **Date de l'édition 2027** : à fixer. L'application doit être prête vers la mi-mars 2027.
6. **Tâches planifiées** sur l'offre OVH Perso : à confirmer lors de l'installation.
7. **Liste des épreuves 2027** : celles de 2026 servent d'exemple, elles pourront changer.

---

## 25. Confidentialité : le dépôt ne contient aucune donnée réelle

### 25.1 Règle

Le dépôt GitHub est **public**. Il ne doit contenir **aucune donnée réelle** permettant d'identifier une personne ou l'organisateur : noms, prénoms, dates de naissance, emails, téléphones, nom de l'association, des écoles, du lieu, nom de domaine de production, résultats réels, fichiers d'inscription, sauvegardes.

Seules des **données fictives** sont autorisées (exemples de configuration, jeux de tests, captures d'écran de démonstration).

### 25.2 Où vivent les données réelles

| Donnée | Emplacement | Jamais dans |
|---|---|---|
| Configuration réelle (nom, lieu, écoles, contacts, aides) | Dossier de données du **serveur de production** | Dépôt |
| Inscriptions, résultats, journaux | **Base de données de production** | Dépôt, site d'essai |
| Secrets (base, SMTP, API, Drive, SFTP) | `.env` du serveur ; **secrets chiffrés GitHub** pour le déploiement | Fichiers versionnés |
| Sauvegardes | Serveur de production + copie **chiffrée** sur Drive | Dépôt |
| Fichiers importés (Excel) | Traités en mémoire puis supprimés | Dépôt, disque après import |

- Le **site d'essai** fonctionne par défaut avec des **données fictives générées** (commande de génération d'un jeu de test : ~100 enfants fictifs, résultats aléatoires réalistes). Une page **Données de test** (admin, absente en production) remplace toutes les inscriptions et tous les résultats par 100 enfants fictifs prêts pour le début de la compétition : 95 avec un dossard, 5 en liste d'attente, personne pointé ; il faut taper « EFFACER » pour confirmer. Des données réelles n'y sont copiées qu'en cas de nécessité ponctuelle, puis effacées.
- Le **dossier de données** est situé **hors du dossier public web** et désigné par une variable d'environnement. Le déploiement **ne touche jamais** ce dossier (exclusion explicite dans le script de déploiement).

### 25.3 Garde-fous techniques

1. **`.gitignore` strict** : `.env`, dossier de données, `*.sqlite`, `*.xlsx`, `*.csv` (hors fixtures fictives explicitement autorisées), sauvegardes, journaux, uploads.
2. **Données de test générées** par un générateur de données fictives : aucun jeu de test ne reprend de vraies données.
3. **Analyse automatique à chaque *push*** (GitHub Actions) :
   - détection de secrets (clés, mots de passe, jetons) ;
   - détection de fichiers interdits (tableurs, bases, sauvegardes) ;
   - détection de **termes interdits** : une liste de mots sensibles (noms réels, nom de l'association, domaine) stockée **dans un secret GitHub**, jamais dans le dépôt. Le *push* échoue si l'un d'eux apparaît.
4. **Même contrôle en local** avant chaque commit (*hook* `pre-commit`), avec une liste de termes conservée hors du dépôt.
5. **Messages de commit et descriptions de *pull request*** : même règle, aucune donnée réelle.
6. **Captures d'écran** de la documentation : réalisées uniquement sur des données fictives.

---

## 26. Conventions de code

- **Langue** : tout le code est en **anglais** (noms de variables, fonctions, classes, fichiers, clés de configuration, tables et colonnes de la base) ainsi que les **commentaires**, les **messages de commit** et la documentation technique (`README.md`, `CONTRIBUTING.md`, `docs/api.md`, `docs/configuration.md`). Les guides d'utilisation existent en français, et l'interface est traduite via les fichiers i18n.
- **PHP** : norme **PER Coding Style 2.0** (successeur de PSR-12), `declare(strict_types=1)` partout, typage complet des paramètres et retours, chargement automatique **PSR-4**.
  - Mise en forme automatique et contrôle : **PHP-CS-Fixer** (ou PHP_CodeSniffer).
  - Analyse statique : **PHPStan** au niveau maximal raisonnable.
  - Docblocks pour les classes et méthodes publiques.
- **JavaScript** : ES2022 en modules, **ESLint** + **Prettier**.
- **CSS** : uniquement des jetons graphiques (variables CSS de `tokens.css`) pour les couleurs, tailles et espacements, vérifié par **Stylelint** (§ 20.6).
- **Twig** : pas de logique métier dans les gabarits.
- **SQL** : migrations versionnées, noms en `snake_case` anglais.
- **Tests** : PHPUnit ; cœur métier (barème, départage, essais, synchronisation) couvert à 100 % des branches significatives.
- **Intégration continue** : à chaque *push*, mise en forme, analyse statique, tests et contrôles de confidentialité (§ 25.3). Une *pull request* ne peut être fusionnée que si tout est vert.
- **Commits** : messages en anglais au format *Conventional Commits* (`feat:`, `fix:`, `docs:`…).

---

## Annexe A — Exemple de configuration (données fictives)

Cet exemple reprend la structure des épreuves d'une édition réelle, mais **toutes les données d'identification sont fictives**. C'est le contenu prévu pour `config.example/competition.toml`.

```toml
[edition]
name = "Multisport Games 2027"
organizer = "Springfield School Sports Club"
date = 2027-06-06
start = 09:00:00
end = 12:00:00
venue = "Springfield Municipal Stadium"
contact_email = "contact@example.org"
default_locale = "fr"

[participants]
levels = ["CP", "CE1", "CE2", "CM1", "CM2"]
genders = ["girl", "boy"]
schools = ["Springfield Elementary", "Shelbyville Elementary"]

[level_groups]
junior = ["CP", "CE1"]
senior = ["CE2", "CM1", "CM2"]

[registration]
opens_at = 2027-03-15T08:00:00
closes_at = 2027-05-30T23:59:00
quota = 100
first_bib = 1

[scoring]
max_score = 1000
floor = 100
foul_score = 1
dnf_score = 0
tie_breakers = ["first_places", "top3_places", "youngest"]

# ---------- Workshops ----------

[[workshops]]
id = "sprint"
help = "sprint.md"

  [[workshops.variants]]
  group = "junior"
  label = "30 m"
  measure = "time"
  time_format = "ss.cc"
  better = "lower"
  attempts = 1
  foul_mode = "strict"
  plausible_range = [4.00, 12.00]
  bibs_per_timer = 2

  [[workshops.variants]]
  group = "senior"
  label = "40 m"
  measure = "time"
  time_format = "ss.cc"
  better = "lower"
  attempts = 1
  foul_mode = "strict"
  plausible_range = [5.00, 14.00]
  bibs_per_timer = 2

[[workshops]]
id = "hurdles"
help = "hurdles.md"

  [[workshops.variants]]
  group = "junior"
  label = "30 m"
  measure = "time"
  time_format = "ss.cc"
  better = "lower"
  attempts = 1
  foul_mode = "strict"
  plausible_range = [5.00, 15.00]
  bibs_per_timer = 2

  [[workshops.variants]]
  group = "senior"
  label = "40 m"
  measure = "time"
  time_format = "ss.cc"
  better = "lower"
  attempts = 1
  foul_mode = "strict"
  plausible_range = [6.00, 18.00]
  bibs_per_timer = 2

[[workshops]]
id = "vortex_throw"
help = "vortex_throw.md"

  [[workshops.variants]]
  group = "junior"
  measure = "distance"
  better = "higher"
  attempts = 2
  foul_mode = "strict"
  plausible_range = [1.00, 50.00]

  [[workshops.variants]]
  group = "senior"
  measure = "distance"
  better = "higher"
  attempts = 2
  foul_mode = "strict"
  plausible_range = [2.00, 80.00]

[[workshops]]
id = "hockey"
help = "hockey.md"

  [[workshops.variants]]
  group = "junior"
  measure = "time"
  time_format = "ss.cc"
  better = "lower"
  attempts = 2
  foul_mode = "strict"
  plausible_range = [5.00, 60.00]
  bibs_per_timer = 1
  modifiers = [ { id = "goal_scored", value = -3.00 } ]

  [[workshops.variants]]
  group = "senior"
  measure = "time"
  time_format = "ss.cc"
  better = "lower"
  attempts = 2
  foul_mode = "strict"
  plausible_range = [5.00, 60.00]
  bibs_per_timer = 1
  modifiers = [ { id = "goal_scored", value = -3.00 } ]

[[workshops]]
id = "football"
help = "football.md"

  [[workshops.variants]]
  group = "junior"
  measure = "time"
  time_format = "m:ss.cc"
  better = "lower"
  attempts = 1
  foul_mode = "strict"
  plausible_range = [10.00, 180.00]
  bibs_per_timer = 1

[[workshops]]
id = "obstacle_course"
help = "obstacle_course.md"

  [[workshops.variants]]
  group = "junior"
  measure = "time"
  time_format = "m:ss.cc"
  better = "lower"
  attempts = 1
  foul_mode = "strict"
  plausible_range = [10.00, 180.00]
  bibs_per_timer = 1

[[workshops]]
id = "long_jump"
help = "long_jump.md"

  [[workshops.variants]]
  group = "senior"
  measure = "distance"
  better = "higher"
  attempts = 2
  foul_mode = "lenient"
  plausible_range = [0.50, 5.00]

[[workshops]]
id = "basketball"
help = "basketball.md"

  [[workshops.variants]]
  group = "senior"
  measure = "counter"
  better = "higher"
  attempts = 1
  limit = { type = "duration", value = "1:30" }
  actions = [
    { id = "basket", points = 3 },
    { id = "rim",    points = 2 },
    { id = "board",  points = 1 },
  ]
  max_value = 60

# ---------- Spreadsheet import ----------
# Maps internal fields to the column headers of the source file.

[import.columns]
last_name = "Child last name"
first_name = "Child first name"
birth_date = "Child birth date"
gender = "Gender"
school = "School"
level = "Class"
guardian_name = "Guardian full name"
guardian_phone = "Guardian phone"
guardian_email = "Contact email"
volunteer = "Volunteer"

# ---------- Contacts shown on help pages ----------

[[contacts]]
role = "data_entry"
name = "Jane Doe"
phone = "+33 6 00 00 00 01"

[[contacts]]
role = "general"
name = "John Smith"
phone = "+33 6 00 00 00 02"
```

Les libellés affichés (noms des ateliers, des actions, des modificateurs, des rôles de contact) sont dans les fichiers de traduction, avec des clés dérivées des `id` (ex. `workshop.hockey.name`, `modifier.goal_scored`). Les valeurs de `plausible_range` sont indicatives.

---

## Annexe B — Exemples de calcul

**Épreuve « plus grand = meilleur »** (lancer, `floor` = 100)
Meilleure performance de la catégorie 68,00 m, moins bonne 8,00 m.

| Enfant | Résultat | Calcul | Score |
|---|---|---|---|
| A | 68,00 m | 100 + 60/60 × 900 | 1000 |
| B | 45,00 m | 100 + 37/60 × 900 | 655 |
| C | 8,00 m | 100 + 0/60 × 900 | 100 |
| D | 2 essais nuls | `foul_score` | 1 |
| E | présent, épreuve non faite | DNF | 0 |

**Épreuve « plus petit = meilleur »** (sprint, `floor` = 100)
Meilleur temps 6,20 s, moins bon 9,80 s. Enfant à 7,10 s :
progression = (7,10 − 9,80) / (6,20 − 9,80) = 2,70 / 3,60 = 0,75 → score = 100 + 0,75 × 900 = **775**.

**Hockey avec modificateur**
Temps chronométré 18,40 s, but marqué (−3,00 s) → résultat retenu **15,40 s**.
