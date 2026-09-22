# Traceo — Cahier de Texte Numérique & Vie Scolaire

Solution B2B multi-tenant pour écoles/lycées : cahier de texte numérique, présences/absences/retards,
emploi du temps, punitions, notifications parents. Multi-pays (Afrique francophone en priorité).

Zéro dépendance externe — PHP natif + MySQL. Fonctionne sur tout hébergement PHP mutualisé.

## Structure

```
Traceo/
├── index.php              ← Accueil : choix du rôle
├── login.php              Connexion avec ?role=
├── inscription.php        Créer un établissement + compte censeur
├── oubli.php              Réinitialisation mot de passe (code SMS)
├── dashboard.php          Tableau de bord censeur
├── classes.php            Gestion classes & élèves
├── matieres.php           Gestion matières
├── filieres.php           Filières (lycée technique)
├── affectations.php       Affectation profs → classes
├── responsables.php       Responsables de classe
├── utilisateurs.php       Comptes utilisateurs
├── emploi_du_temps.php    Créneaux hebdomadaires
├── import.php             Import CSV/XLSX (élèves, EDT)
├── transition.php         Passage/redoublement fin d'année
├── punitions.php          Gestion punitions
├── rapports.php           Rapports de présence & cahier
├── profil.php             Changement mot de passe (tous)
├── logout.php             Déconnexion
├── prof/                  Portail professeur
│   ├── dashboard.php      Emploi du temps du jour + cahier
│   ├── appel.php          Appel (cases Absent/Retard)
│   └── save_seance.php    Enregistre le contenu d'une séance
├── responsable/           Portail responsable de classe
│   └── dashboard.php      Validation des séances
├── surveillant/           Portail surveillant
│   └── dashboard.php      Stats absences/retards/punitions
├── assets/css/app.css     Design system complet
├── inc/                   Layout partagé (header.php, footer.php)
└── backend/               API, config, helpers, SQL, cron, SMS
```

## Installation

1. Importer le schéma : `mysql -u root -p < backend/sql/schema.sql`
2. Configurer `backend/config/database.php` (DB_HOST, DB_NAME, DB_USER, DB_PASS, APP_SECRET)
3. Déposer tout le dossier `Traceo/` sur ton hébergeur PHP
4. Accéder à `http://ton-domaine.com/Traceo/` (ou `http://localhost/Traceo/`)
5. Première visite → choisir un rôle sur la page d'accueil
6. **Changer `APP_SECRET`** avant mise en production

## API — Endpoints (`backend/api/`)

| Endpoint | Accès | Description |
|---|---|---|
| `auth_login.php` | public | Connexion, retourne token JWT |
| `etablissement_inscription.php` | public | Crée un établissement + compte censeur |
| `utilisateur_creer.php` | censeur | Crée un compte prof/surveillant/responsable |
| `emploi_du_temps_jour.php` | prof | Programme du jour du prof |
| `seance_save.php` | prof | Enregistre le cahier de texte d'une séance |
| `appel_save.php` | prof | Enregistre absences/retards (par exception) |
| `seances_a_valider.php` | responsable_classe | Séances en attente de validation |
| `seance_valider.php` | responsable_classe | Valide une séance |
| `punition_creer.php` | surveillant | Crée une punition + détecte récidive |
| `eleves_par_classe.php` | prof/responsable | Liste des élèves d'une classe |
| `classes_liste.php` | tous | Liste des classes de l'établissement |

## Flux de connexion

1. `index.php` → 4 cartes (Censeur, Professeur, Responsable, Surveillant)
2. Choix d'un rôle → `login.php?role=X` (lien inscription visible seulement pour censeur)
3. Authentification → redirection vers le dashboard du rôle
- Session stateful pour le web, JWT stateless pour les API

## Notifications SMS

Les notifications sont gérées par un système de provider configurable :

1. **Configurer** `backend/config/notifications.php` :
   - `'log'` — écrit dans un fichier (mode développement par défaut)
   - `'africas_talking'` — API Africa's Talking (recommandé Afrique)
   - `'twilio'` — API Twilio (international)

2. **Exécuter** le cron régulièrement :
   ```
   php backend/cron/traitement_notifications.php
   ```
   À mettre dans crontab (ex: toutes les heures).

3. **Providers supportés** : Africa's Talking, Twilio, log local.
   Le canal `whatsapp` est accepté par l'API mais dépend du provider configuré.
   Aucune bibliothèque externe nécessaire — utilise `file_get_contents` avec stream context.

## Import Excel

Le parseur XLSX utilise ZipArchive + SimpleXML (extensions PHP standard).
Pas besoin de PHPSpreadsheet ni d'aucune bibliothèque.

Formats supportés : CSV (.csv) et Excel (.xlsx).
L'ancien format .xls (binaire) n'est pas supporté — convertissez en .xlsx ou CSV.

Templates téléchargeables depuis la page d'import (CSV avec BOM UTF-8).

## Points de conception

- **Multi-tenant** : `etablissement_id` sur toutes les tables, chaque requête filtre dessus
- **Multi-pays** : `pays` porte les valeurs par défaut, `etablissement` peut surcharger
- **Filières libres** : chaque établissement définit ses propres filières (noms, niveaux, diplômes)
- **Présence par exception** : absent/retard seulement ; présent = pas de ligne
- **Validation bloquante** : cahier de texte complet uniquement après validation par un responsable de classe
- **Rôles** : censeur (admin), prof (cahier + appel), responsable_classe (validation), surveillant (punitions)
- **Palette** : claire/sombre avec design tokens CSS (bleu institutionnel `#0B1324`, or `#C7920E`, vert présent, orange alerte)
