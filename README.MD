# **🚘 Gestion de Flotte Automobile \- COOP**

Système complet de gestion de parc automobile, conçu pour simplifier la réservation de véhicules, le suivi des affectations et la gestion administrative.

## **🌟 Fonctionnalités Principales**

### **👤 Espace Employé**

* **Authentification sécurisée** : Connexion via matricule et mot de passe.  
* **Tableau de Bord Personnel** : Vue d'ensemble des réservations en cours et à venir.  
* **Réservation de Véhicules** :  
  * Formulaire simple pour réserver un véhicule communal.  
  * Vérification automatique des disponibilités (anti-doublon).  
  * Calendrier visuel interactif pour voir les créneaux libres.  
* **Prise en Charge & Restitution** :  
  * Validation du départ ("Je pars") avec kilométrage.  
  * Validation du retour ("Je suis rentré") avec kilométrage de fin et commentaire (état, problèmes).  
* **Responsive Design** : Interface adaptée aux mobiles (tableaux en mode cartes, menu hamburger).

### **🛠️ Espace Manager**

* **Tableau de Bord de Gestion** :  
  * Vue des demandes de réservation "En attente" avec boutons de validation/refus rapides.  
  * Historique global filtrable de toutes les sorties.  
* **Recherche Avancée** :  
  * Filtrage par mots-clés (Nom, Prénom, Matricule, Immatriculation).  
  * Filtrage par date précise.  
* **Gestion du Parc Automobile (Véhicules)** :  
  * Ajout de nouveaux véhicules (Immatriculation, Marque, Modèle, Carburant).  
  * Distinction entre véhicules "Communaux" (partagés) et "Attitrés" (personnels).  
  * Mise hors service / Réactivation des véhicules.  
  * Changement rapide de type (Communal \<-\> Attitré).  
  * Filtres de liste (Par Immatriculation, Marque/Modèle, Carburant, État).  
* **Gestion des Ressources Humaines (Employés)** :  
  * Ajout de nouveaux collaborateurs.  
  * Annuaire avec statuts (Actif/Inactif).  
* **Gestion des Affectations Fixes** :  
  * Attribution d'un véhicule spécifique à un employé (ce véhicule sort alors du pool de réservation communal).  
* **Gestion des Congés** :  
  * Saisie des congés pour libérer temporairement un véhicule attitré dans le pool communal.

## **💻 Stack Technique**

* **Backend** : PHP (Natif, sans framework lourd).  
* **Base de Données** : MySQL (avec MySQLi).  
* **Frontend** : HTML5, CSS3 (Variables CSS, Flexbox, Media Queries).  
* **JavaScript** : Vanilla JS \+ **FullCalendar** (v6) pour l'affichage du planning.  
* **Sécurité** :  
  * Sessions PHP sécurisées.  
  * Requêtes SQL préparées (bind\_param) pour éviter les injections SQL.  
  * Protection des pages par rôle (Redirection si non-Manager).

## **📱 Optimisations Mobile**

Le site est conçu en "Mobile First" avec des adaptations spécifiques :

* **Menu Hamburger** : Navigation compacte sur petit écran.  
* **Tableaux Responsive** : Transformation des lignes de tableau en "Cartes" verticales pour une lecture facile sans scroll horizontal.  
* **Calendrier Adaptatif** : Ajustement automatique de la vue et des couleurs pour la lisibilité sur téléphone.  
* **Boutons Tactiles** : Zones de clic agrandies pour l'utilisation au doigt.

## **🚀 Installation**

1. Importer la base de données via le script SQL fourni (structure des tables employes, vehicules, reservations, etc.).  
2. Configurer le fichier config.php avec vos accès BDD (host, user, password, db\_name).  
3. Déposer les fichiers sur votre serveur Web (Apache/Nginx avec PHP).  
4. Se connecter avec un compte administrateur pré-créé.

*© 2025 \- Projet Gestion de Flotte COOP*