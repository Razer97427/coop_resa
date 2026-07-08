<div align="center">

# 🚗 Gestion de Flotte Automobile
### TERRACOOP — Système Interne de Réservation & Suivi de Parc

![Version](https://img.shields.io/badge/version-10.3.6-blue?style=for-the-badge)
![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?style=for-the-badge&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-4479A1?style=for-the-badge&logo=mysql&logoColor=white)
![License](https://img.shields.io/badge/licence-interne-green?style=for-the-badge)
![2FA](https://img.shields.io/badge/2FA-activée-success?style=for-the-badge&logo=google-authenticator)

> Plateforme web interne permettant aux collaborateurs de **réserver des véhicules du parc**, de gérer les **départs/retours**, et aux managers de **piloter l'ensemble du parc automobile** de la coopérative.

</div>

---

## 📋 Sommaire

- [✨ Fonctionnalités](#-fonctionnalités)
- [🛠️ Stack Technique](#️-stack-technique)
- [🗂️ Architecture du Projet](#️-architecture-du-projet)
- [🔐 Sécurité](#-sécurité)
- [📱 Design Mobile](#-design-mobile)
- [⚙️ Installation](#️-installation)
- [🔧 Configuration](#-configuration)
- [👥 Comptes & Rôles](#-comptes--rôles)
- [🚦 Flux Utilisateur](#-flux-utilisateur)
- [🩺 Dépannage](#-dépannage)
- [📬 Contact & Maintenance](#-contact--maintenance)

---

## ✨ Fonctionnalités

### 👤 Espace Employé

| Fonctionnalité | Description |
|---|---|
| 🔑 **Connexion sécurisée** | Authentification par matricule + mot de passe + code 2FA (Google Authenticator) |
| 🏠 **Tableau de bord** | Vue d'ensemble des réservations en cours et à venir |
| 📅 **Réservation de véhicule** | Formulaire simple, vérification anti-doublon, calendrier interactif FullCalendar v6 |
| 🚦 **Départ** | Validation "Je pars" avec saisie du kilométrage de début |
| 🏁 **Retour** | Validation "Je suis rentré" avec kilométrage de fin + commentaire (état, incidents) |
| ⚙️ **Paramètres du compte** | Modification du mot de passe, gestion du profil |

---

### 🧑‍💼 Espace Manager

#### Validation & Suivi
- ✅ **Demandes en attente** : validation ou refus en un clic
- 📜 **Historique global** : toutes les sorties, filtrables par date, nom, matricule, immatriculation

#### 🚘 Gestion du Parc Automobile
- ➕ Ajout de véhicules (immatriculation, marque, modèle, carburant)
- 🔄 Deux types de véhicules :
  - **Communaux** — disponibles dans le pool partagé
  - **Attitrés** — affectés à un employé spécifique
- 🔧 Mise hors service / réactivation
- 🔀 Changement rapide de type (Communal ↔ Attitré)
- 🔍 Filtres avancés (immatriculation, marque, carburant, état)

#### 👨‍👩‍👦 Gestion des Employés & Affectations
- ➕ Ajout de collaborateurs avec annuaire (Actif / Inactif)
- 🔗 Attribution d'un véhicule dédié à un employé (sort du pool communal)
- 🏖️ **Gestion des congés** : libération temporaire d'un véhicule attitré dans le pool communal pendant les absences

#### 📆 Planning & Notifications
- 🗓️ **Calendrier visuel** : trajets validés avec conducteur, véhicule et dates
- 📧 **Notifications e-mail automatiques** : confirmation / refus via PHPMailer + Mailjet
- 🔐 **Administration 2FA** : configuration et réinitialisation de la 2FA pour chaque utilisateur

---

## 🛠️ Stack Technique

| Couche | Technologie | Détail |
|---|---|---|
| 🖥️ Backend | ![PHP](https://img.shields.io/badge/PHP_natif-777BB4?logo=php&logoColor=white) | Sans framework — léger et sécurisé |
| 🗄️ Base de données | ![MySQL](https://img.shields.io/badge/MySQL-4479A1?logo=mysql&logoColor=white) | MySQLi + requêtes préparées |
| 🎨 Frontend | HTML5 + CSS3 | Variables CSS, Flexbox, Media Queries |
| ⚡ JavaScript | Vanilla JS | FullCalendar v6 pour les calendriers |
| 📧 E-mail | PHPMailer v7.0.0 | SMTP via Mailjet |
| 🔐 2FA | Google Authenticator | TOTP standard RFC 6238 |
| 🌐 Serveur | Apache / Nginx | Compatible PHP 7.4+ |

---

## 🗂️ Architecture du Projet

```
gestion-auto/
│
├── 📄 config.php                  — Connexion BDD, sessions, timezone (UTC+4 La Réunion)
│
├── 📁 Pages principales
│   ├── index.php                  — Tableau de bord employé (réservations, départ/retour)
│   ├── login.php                  — Connexion (matricule + mot de passe)
│   ├── login_2fa_check.php        — Vérification code TOTP (2FA)
│   ├── logout.php                 — Déconnexion et destruction de session
│   ├── manager.php                — Dashboard manager (validation, historique)
│   ├── parc.php                   — Gestion complète du parc (CRUD, filtres)
│   ├── employes.php               — Gestion des collaborateurs
│   ├── affectations.php           — Affectation de véhicules aux employés
│   ├── planning.php               — Calendrier des trajets validés
│   ├── conges.php                 — Gestion des congés
│   └── settings.php               — Paramètres du compte
│
├── 📁 Authentification & Sécurité
│   ├── GoogleAuthenticator.php    — Implémentation TOTP (2FA)
│   ├── setup_2fa.php              — Assistant 2FA (génération QR code)
│   ├── admin_2fa.php              — Administration 2FA (côté manager)
│   ├── forgot.php                 — Mot de passe oublié (e-mail + token)
│   ├── reset.php                  — Réinitialisation via token
│   └── update_password.php        — Changement de mot de passe
│
├── 📁 API internes (JSON)
│   ├── api_vehicules.php          — Disponibilité des véhicules par date
│   ├── api_calendar.php           — Données pour FullCalendar
│   ├── api_resources.php          — Ressources génériques
│   └── get_conges.php             — Périodes de congés sur une plage de dates
│
├── 📁 Utilitaires
│   ├── actions.php                — Handler d'actions (annulation de réservation)
│   ├── test_mail.php              — Test de la config SMTP
│   └── auth_debug.php             — Debug session (dev uniquement)
│
├── 📁 Templates
│   ├── includes/header.php        — Navigation, logo, menu par rôle
│   └── includes/footer.php        — Pied de page
│
├── 🎨 styles.css                  — CSS complet responsive (mobile-first)
│
└── 📦 vendor/
    └── phpmailer/phpmailer        — PHPMailer v7.0.0
```

---

## 🗄️ Schéma de Base de Données

Les 5 tables principales de l'application :

```
employes        — Comptes, rôles, matricules, 2FA
vehicules       — Parc auto (communaux & attitrés), états
reservations    — Demandes de réservation (en attente / validée / refusée / terminée)
affectations    — Liaison véhicule ↔ employé
conges          — Périodes d'absence (libération temporaire du pool)
```

---

## 🔐 Sécurité

> L'application a été conçue avec une approche **sécurité par défaut**.

| Mesure | Détail |
|---|---|
| 🔑 **2FA obligatoire** | Code TOTP 30s via Google Authenticator — impossible de se connecter sans |
| 🛡️ **Requêtes préparées** | `bind_param()` MySQLi sur toutes les requêtes — protection injections SQL |
| 🔒 **Sessions PHP sécurisées** | Gestion des droits par rôle, redirection automatique si accès non autorisé |
| 📧 **Reset de mot de passe** | Token unique envoyé par e-mail avec expiration |
| ⚡ **PHP natif** | Surface d'attaque réduite, aucune dépendance framework lourde |

### ⚠️ Bonnes Pratiques de Déploiement

> **Ne jamais ignorer ces points avant la mise en production.**

- 🚫 Ne **jamais** exposer `config.php` sur un dépôt public
- 🔒 Déployer **exclusivement en HTTPS** (certificat SSL obligatoire)
- 💾 **Sauvegarder la base de données** régulièrement (cron quotidien recommandé)
- 🗑️ Supprimer ou désactiver `auth_debug.php` et `test_mail.php` en production

---

## 📱 Design Mobile

L'interface est conçue en **Mobile First** — elle s'adapte automatiquement à toutes les tailles d'écran.

| Adaptation | Description |
|---|---|
| 🍔 **Menu hamburger** | Navigation compacte sur mobiles et tablettes |
| 📊 **Tableaux responsifs** | Les lignes se transforment en cartes verticales — plus de scroll horizontal |
| 📅 **Calendrier adaptatif** | Vues et couleurs ajustées selon la taille d'écran |
| 👆 **Boutons tactiles** | Zones de clic agrandies pour une utilisation confortable au doigt |

---

## ⚙️ Installation

### ✅ Prérequis

- 🌐 Serveur web : **Apache** ou **Nginx**
- 🐘 **PHP >= 7.4** avec les extensions `mysqli`, `openssl`, `mbstring`
- 🗄️ **MySQL >= 5.7** ou **MariaDB**
- 📧 Accès SMTP — compte **Mailjet** (ou équivalent compatible)
- 📱 Application **Google Authenticator** (ou Authy) sur mobile

---

### 🚀 Étapes d'Installation

**Étape 1 — Déposer les fichiers**

Copier le contenu du dossier dans le répertoire web de votre serveur (ex : `/var/www/html/gestion-auto/`).

---

**Étape 2 — Créer la base de données**

```bash
mysql -u utilisateur -p nom_base < schema.sql
```

Tables créées : `employes`, `vehicules`, `reservations`, `affectations`, `conges`.

---

**Étape 3 — Configurer la connexion BDD**

Editer `config.php` :

```php
$host     = 'localhost';
$user     = 'votre_utilisateur';
$password = 'votre_mot_de_passe';
$db_name  = 'votre_base';
```

---

**Étape 4 — Configurer l'envoi d'e-mails**

Dans `forgot.php` et `manager.php` :

```php
$mail->Host     = 'in-v3.mailjet.com';
$mail->Username = 'CLE_API_MAILJET';
$mail->Password = 'SECRET_API_MAILJET';
$mail->Port     = 587;
```

> 💡 Tester la config avec `test_mail.php` avant la mise en production.

---

**Étape 5 — Créer le premier compte Manager**

Directement en base de données :

```sql
INSERT INTO employes (matricule, nom, prenom, password, role)
VALUES ('ADMIN01', 'Nom', 'Prenom', SHA2('mot_de_passe', 256), 'Manager');
```

---

**Étape 6 — Activer la 2FA**

Se connecter avec le compte créé → suivre l'assistant `setup_2fa.php` → scanner le QR code avec Google Authenticator.

---

## 🔧 Configuration

| Paramètre | Fichier | Description |
|---|---|---|
| 🗄️ Connexion BDD | `config.php` | Host, utilisateur, mot de passe, nom base |
| 🕐 Timezone | `config.php` | `Indian/Reunion` — UTC+4 (La Réunion) |
| 📧 SMTP Mailjet | `forgot.php`, `manager.php` | Clés API et paramètres SMTP |
| 🔐 2FA TOTP | `GoogleAuthenticator.php` | RFC 6238 — compatible Google Authenticator, Authy, etc. |

---

## 👥 Comptes & Rôles

```
┌─────────────────────────────────────────────────────────────────┐
│  RÔLE EMPLOYÉ                                                    │
│  ✅ Réserver un véhicule communal                               │
│  ✅ Voir son tableau de bord personnel                          │
│  ✅ Valider son départ (kilométrage début)                      │
│  ✅ Valider son retour (kilométrage fin + commentaire)          │
│  ✅ Modifier son mot de passe / profil                          │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│  RÔLE MANAGER   (tout l'espace Employé +)                        │
│  ✅ Valider / refuser les demandes de réservation               │
│  ✅ Consulter l'historique complet et filtré                    │
│  ✅ Ajouter / modifier / désactiver des véhicules               │
│  ✅ Gérer les employés (ajout, statut)                          │
│  ✅ Gérer les affectations de véhicules                         │
│  ✅ Gérer les congés et leur impact sur le pool                 │
│  ✅ Consulter le planning visuel des trajets                    │
│  ✅ Administrer la 2FA des utilisateurs                         │
└─────────────────────────────────────────────────────────────────┘
```

---

## 🚦 Flux Utilisateur

### Réservation standard (Employé)

```
Connexion (matricule + MDP + 2FA)
        ↓
Tableau de bord → Nouvelle réservation
        ↓
Sélection du véhicule & des dates (FullCalendar)
        ↓
Envoi de la demande  →  Statut : ⏳ En attente
        ↓
Manager valide  →  Statut : ✅ Validée  (e-mail envoyé)
ou
Manager refuse  →  Statut : ❌ Refusée  (e-mail envoyé)
        ↓
Employé part  →  "Je pars" + kilométrage début
        ↓
Employé revient  →  "Je suis rentré" + kilométrage fin + commentaire
        ↓
Statut : 🏁 Terminée
```

---

## 🩺 Dépannage

| 🔴 Problème | ✅ Solution |
|---|---|
| Erreur de connexion BDD | Vérifier les identifiants dans `config.php` et l'existence des tables |
| E-mails non reçus | Tester avec `test_mail.php`, vérifier les clés API Mailjet |
| Code 2FA invalide | Synchroniser l'horloge du serveur (`ntpdate`) et du téléphone |
| Page blanche PHP | Activer les logs : `display_errors = On` dans `php.ini` (dev uniquement) |
| Véhicule non disponible | Vérifier les congés actifs et les affectations dans la base |
| Accès refusé (403) | Vérifier les permissions fichiers (`chmod 755` répertoires, `644` fichiers) |
| Calendrier ne s'affiche pas | Vérifier le chargement de FullCalendar (CDN ou local) et l'API `api_calendar.php` |

---

## 📬 Contact & Maintenance

| Info | Détail |
|---|---|
| 🏢 **Organisation** | TERRACOOP — Coopérative agricole |
| 💻 **Service** | Service Informatique |
| 📧 **Contact** | service.informatique@terracoop.re |
| 🗓️ **Version actuelle** | v10.3.6 (2026) |
| 🌍 **Fuseau horaire** | Indian/Reunion — UTC+4 |

---

<div align="center">

*© 2025-2026 — Gestion de Flotte TERRACOOP — Service Informatique*

![Made with PHP](https://img.shields.io/badge/Made%20with-PHP-777BB4?style=flat-square&logo=php)
![MySQL](https://img.shields.io/badge/DB-MySQL-4479A1?style=flat-square&logo=mysql)
![Sécurisé 2FA](https://img.shields.io/badge/Sécurisé-2FA%20TOTP-success?style=flat-square)

</div>
