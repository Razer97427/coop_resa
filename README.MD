# Gestion de Flotte Automobile — TERRACOOP

Système complet de gestion de parc automobile, conçu pour simplifier la réservation de véhicules, le suivi des affectations et la gestion administrative au sein de la coopérative.

---

## Sommaire

- [Fonctionnalités](#fonctionnalités)
- [Stack Technique](#stack-technique)
- [Architecture du Projet](#architecture-du-projet)
- [Sécurité](#sécurité)
- [Design Mobile](#design-mobile)
- [Installation](#installation)
- [Configuration](#configuration)
- [Comptes & Rôles](#comptes--rôles)
- [Dépannage](#dépannage)

---

## Fonctionnalités

### Espace Employé

- **Authentification sécurisée** : Connexion via matricule et mot de passe, renforcée par une authentification à deux facteurs (2FA) via Google Authenticator.
- **Tableau de bord personnel** : Vue d'ensemble des réservations en cours et à venir.
- **Réservation de véhicules** :
  - Formulaire simple pour réserver un véhicule communal.
  - Vérification automatique des disponibilités (anti-doublon).
  - Calendrier visuel interactif (FullCalendar v6) pour visualiser les créneaux libres.
- **Prise en charge & restitution** :
  - Validation du départ ("Je pars") avec saisie du kilométrage de début.
  - Validation du retour ("Je suis rentré") avec kilométrage de fin et commentaire (état du véhicule, incidents éventuels).
- **Paramètres du compte** : Modification du mot de passe et gestion du profil.

### Espace Manager

- **Tableau de bord de gestion** :
  - Vue des demandes de réservation "En attente" avec boutons de validation/refus rapides.
  - Historique global filtrable de toutes les sorties.
- **Recherche avancée** :
  - Filtrage par mots-clés (Nom, Prénom, Matricule, Immatriculation).
  - Filtrage par date précise.
- **Gestion du parc automobile** :
  - Ajout de nouveaux véhicules (Immatriculation, Marque, Modèle, Carburant).
  - Distinction entre véhicules **Communaux** (pool partagé) et **Attitrés** (affectés à un employé).
  - Mise hors service / Réactivation des véhicules.
  - Changement rapide de type (Communal <-> Attitré).
  - Filtres de liste (Immatriculation, Marque/Modèle, Carburant, État).
- **Gestion des employés** :
  - Ajout de nouveaux collaborateurs.
  - Annuaire avec statuts (Actif / Inactif).
- **Gestion des affectations fixes** :
  - Attribution d'un véhicule spécifique à un employé (le véhicule sort alors du pool communal).
- **Gestion des congés** :
  - Saisie des périodes de congés pour libérer temporairement un véhicule attitré dans le pool communal.
- **Planning visuel** :
  - Calendrier des trajets validés et en cours avec affichage conducteur/véhicule/dates.
- **Notifications e-mail** :
  - Envoi automatique d'e-mails via PHPMailer (SMTP Mailjet) lors des validations/refus de demandes.
- **Administration 2FA** :
  - Interface dédiée pour configurer ou réinitialiser la 2FA des utilisateurs.

---

## Stack Technique

| Couche | Technologie |
|---|---|
| Backend | PHP natif (sans framework) |
| Base de données | MySQL + MySQLi (requêtes préparées) |
| Frontend | HTML5, CSS3 (Variables CSS, Flexbox, Media Queries) |
| JavaScript | Vanilla JS + FullCalendar v6 |
| E-mail | PHPMailer v7.0.0 via SMTP (Mailjet) |
| Authentification 2FA | Google Authenticator — TOTP (RFC 6238) |
| Déploiement | Apache / Nginx + PHP |

---

## Architecture du Projet

```
gestion-auto/
│
├── config.php                  — Connexion BDD, session, timezone (Indian/Reunion UTC+4)
│
├── Pages principales
│   ├── index.php               — Tableau de bord employé, réservations, départ/retour
│   ├── login.php               — Formulaire de connexion (matricule + mot de passe)
│   ├── login_2fa_check.php     — Vérification du code TOTP (2FA)
│   ├── logout.php              — Destruction de session
│   ├── manager.php             — Tableau de bord manager (validation, historique)
│   ├── parc.php                — Gestion complète du parc (CRUD véhicules, filtres)
│   ├── vehicules.php           — Interface alternative de gestion des véhicules
│   ├── employes.php            — Gestion des collaborateurs
│   ├── affectations.php        — Attribution de véhicules aux employés
│   ├── planning.php            — Calendrier des trajets validés
│   ├── conges.php              — Gestion des congés
│   └── settings.php            — Paramètres du compte utilisateur
│
├── Authentification & Sécurité
│   ├── GoogleAuthenticator.php — Implémentation TOTP pour la 2FA
│   ├── setup_2fa.php           — Assistant de configuration 2FA (génération QR code)
│   ├── admin_2fa.php           — Gestion 2FA côté manager
│   ├── forgot.php              — Mot de passe oublié (envoi e-mail avec token)
│   ├── reset.php               — Réinitialisation du mot de passe (token)
│   └── update_password.php     — Mise à jour du mot de passe
│
├── API internes (JSON)
│   ├── api_vehicules.php       — Disponibilité des véhicules selon une date
│   ├── api_calendar.php        — Données du calendrier pour FullCalendar
│   ├── api_resources.php       — Ressources génériques
│   └── get_conges.php          — Périodes de congés pour une plage de dates
│
├── Utilitaires
│   ├── actions.php             — Handler d'actions (annulation de réservation)
│   ├── test_mail.php           — Test de la configuration SMTP
│   └── auth_debug.php          — Debug de session (développement uniquement)
│
├── Templates
│   ├── includes/header.php     — Navigation, logo, menu utilisateur, liens par rôle
│   └── includes/footer.php     — Pied de page
│
├── styles.css                  — CSS complet responsive (mobile-first)
│
└── vendor/
    └── phpmailer/phpmailer     — Librairie PHPMailer v7.0.0
```

---

## Sécurité

- **Authentification à deux facteurs (2FA)** : Code TOTP (30 secondes) via Google Authenticator, obligatoire pour accéder à l'application.
- **Requêtes SQL préparées** : Utilisation systématique de `bind_param()` (MySQLi) pour prévenir les injections SQL.
- **Sessions PHP sécurisées** : Gestion des droits par rôle avec redirection automatique si l'accès n'est pas autorisé.
- **Réinitialisation de mot de passe sécurisée** : Envoi d'un token unique par e-mail avec expiration.
- **PHP natif sans framework** : Surface d'attaque réduite, pas de dépendances lourdes.
- **Bonnes pratiques** :
  - Ne pas exposer `config.php` sur un dépôt public.
  - Déployer exclusivement en HTTPS.
  - Sauvegarder la base de données régulièrement.

---

## Design Mobile

L'interface est conçue en **Mobile First** avec les adaptations suivantes :

- **Menu hamburger** : Navigation compacte sur petits écrans.
- **Tableaux responsive** : Les lignes de tableau se transforment en cartes verticales pour une lecture sans scroll horizontal.
- **Calendrier adaptatif** : Vue et couleurs ajustées automatiquement selon la taille de l'écran.
- **Boutons tactiles** : Zones de clic agrandies pour une utilisation au doigt confortable.

---

## Installation

### Prérequis

- Serveur web : **Apache** ou **Nginx**
- **PHP >= 7.4** avec les extensions `mysqli`, `openssl`, `mbstring`
- **MySQL >= 5.7** ou **MariaDB**
- Accès SMTP (Mailjet ou autre fournisseur compatible)

### Étapes

**1. Déposer les fichiers** sur le répertoire web de votre serveur.

**2. Créer la base de données et importer le schéma SQL :**

```bash
mysql -u utilisateur -p nom_base < schema.sql
```

Tables principales : `employes`, `vehicules`, `reservations`, `affectations`, `conges`.

**3. Configurer la connexion BDD** dans `config.php` :

```php
$host     = 'localhost';
$user     = 'votre_utilisateur';
$password = 'votre_mot_de_passe';
$db_name  = 'votre_base';
```

**4. Configurer l'envoi d'e-mails** dans `forgot.php` et `manager.php` :

```php
$mail->Host     = 'in-v3.mailjet.com';
$mail->Username = 'CLE_API_MAILJET';
$mail->Password = 'SECRET_API_MAILJET';
$mail->Port     = 587;
```

**5. Créer le premier compte Manager** directement en base :

```sql
INSERT INTO employes (matricule, nom, prenom, password, role)
VALUES ('ADMIN01', 'Nom', 'Prenom', SHA2('mot_de_passe', 256), 'Manager');
```

**6. Configurer la 2FA** à la première connexion via l'assistant intégré (`setup_2fa.php`), en scannant le QR code avec Google Authenticator.

---

## Configuration

| Paramètre | Fichier | Description |
|---|---|---|
| Connexion BDD | `config.php` | Host, utilisateur, mot de passe, nom de la base |
| Timezone | `config.php` | Définie sur `Indian/Reunion` (UTC+4) |
| SMTP / Mailjet | `forgot.php`, `manager.php` | Clés API et paramètres de connexion SMTP |
| 2FA TOTP | `GoogleAuthenticator.php` | Algorithme standard, compatible Google Authenticator, Authy, etc. |

---

## Comptes & Rôles

| Rôle | Accès |
|---|---|
| **Employé** | Réservation, tableau de bord personnel, départ/retour, paramètres du compte |
| **Manager** | Tout l'espace employé + validation des demandes, gestion du parc, des employés, des affectations et des congés |

---

## Dépannage

| Problème | Solution |
|---|---|
| Erreur de connexion BDD | Vérifier les identifiants dans `config.php` et l'existence des tables |
| E-mails non reçus | Tester l'envoi avec `test_mail.php`, vérifier les clés SMTP Mailjet |
| Code 2FA invalide | Vérifier la synchronisation de l'horloge du serveur et du téléphone |
| Page blanche PHP | Activer les logs PHP (`display_errors = On` en développement) |
| Véhicule non disponible | Vérifier les congés et affectations actives dans la base |

---

*© 2025-2026 — Projet Gestion de Flotte TERRACOOP — Service Informatique*
