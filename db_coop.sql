-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Hôte : localhost
-- Généré le : ven. 21 nov. 2025 à 04:17
-- Version du serveur : 11.8.3-MariaDB-0+deb13u1 from Debian
-- Version de PHP : 8.4.11

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de données : `db_coop`
--

-- --------------------------------------------------------

--
-- Structure de la table `affectations_fixes`
--

CREATE TABLE `affectations_fixes` (
  `id_affectation` int(11) NOT NULL,
  `id_employe` int(11) NOT NULL,
  `id_vehicule` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `conges`
--

CREATE TABLE `conges` (
  `id_conge` int(11) NOT NULL,
  `id_employe` int(11) NOT NULL,
  `date_debut` datetime NOT NULL,
  `date_fin` datetime NOT NULL,
  `motif` varchar(255) DEFAULT NULL,
  `statut` varchar(20) DEFAULT 'En_Attente'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `employes`
--

CREATE TABLE `employes` (
  `id_employe` int(11) NOT NULL,
  `matricule` varchar(20) NOT NULL,
  `nom` varchar(100) NOT NULL,
  `prenom` varchar(100) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `mot_de_passe` varchar(255) DEFAULT NULL,
  `role` varchar(20) DEFAULT 'Employé',
  `actif` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `employes`
--

INSERT INTO `employes` (`id_employe`, `matricule`, `nom`, `prenom`, `email`, `mot_de_passe`, `role`, `actif`) VALUES
(1, 'MAT-001', 'Martin', 'Sophie', 'sophie.martin@test.com', 'password123', 'Manager', 1),
(2, 'MAT-002', 'Dupont', 'Jean', 'jean.dupont@test.com', 'password123', 'employer', 1),
(3, 'MAT-003', 'Dubois', 'Luc', 'luc.dubois@test.com', 'password123', 'employer', 1),
(4, 'MAT-004', 'Leroy', 'Alice', 'alice.leroy@test.com', 'password123', 'employer', 1),
(5, 'MAT-005', 'Moreau', 'Thomas', 'thomas.moreau@test.com', 'password123', 'employer', 1),
(6, 'MAT-006', 'Petit', 'Isabelle', 'isabelle.petit@test.com', 'password123', 'employer', 0),
(7, 'MAT-007', 'Garcia', 'Nicolas', 'nicolas.garcia@test.com', 'password123', 'employer', 1),
(8, 'MAT-008', 'Roux', 'Michel', 'michel.roux@test.com', 'password123', 'employer', 1),
(9, 'MAT-009', 'Lefebvre', 'Claire', 'claire.lefebvre@test.com', 'password123', 'employer', 1),
(10, 'MAT-010', 'Mercier', 'Pierre', 'pierre.mercier@test.com', 'password123', 'employer', 1);

-- --------------------------------------------------------

--
-- Structure de la table `reservations`
--

CREATE TABLE `reservations` (
  `id_reservation` int(11) NOT NULL,
  `id_employe` int(11) NOT NULL,
  `date_demande` datetime DEFAULT current_timestamp(),
  `id_vehicule` int(11) NOT NULL,
  `date_debut_resa` datetime NOT NULL,
  `date_fin_resa` datetime NOT NULL,
  `km_debut` int(11) DEFAULT NULL,
  `date_depart_reel` datetime DEFAULT NULL,
  `km_fin` int(11) DEFAULT NULL,
  `date_retour_reel` datetime DEFAULT NULL,
  `commentaire_retour` varchar(500) DEFAULT NULL,
  `motif` varchar(255) DEFAULT NULL,
  `statut_resa` varchar(20) DEFAULT 'Confirmée',
  `commentaire_restitution` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `vehicules`
--

CREATE TABLE `vehicules` (
  `id_vehicule` int(11) NOT NULL,
  `immatriculation` varchar(20) NOT NULL,
  `marque` varchar(50) NOT NULL,
  `modele` varchar(50) NOT NULL,
  `type_carburant` varchar(20) DEFAULT NULL,
  `est_communal` tinyint(1) DEFAULT 1,
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  `kilometrage` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `vehicules`
--

INSERT INTO `vehicules` (`id_vehicule`, `immatriculation`, `marque`, `modele`, `type_carburant`, `est_communal`, `actif`, `kilometrage`) VALUES
(1, 'AB-123-CD', 'Renault', 'Clio V', 'Essence', 1, 1, 25400),
(2, 'EF-456-GH', 'Peugeot', '208', 'Diesel', 1, 1, 85200),
(3, 'IJ-789-KL', 'Citroën', 'Berlingo', 'Diesel', 1, 1, 120500),
(4, 'MN-101-OP', 'Renault', 'Zoe', 'Électrique', 1, 1, 15000),
(5, 'QR-202-ST', 'Ford', 'Transit', 'Diesel', 1, 1, 210000),
(6, 'UV-303-WX', 'Dacia', 'Duster', 'GPL', 1, 1, 45000),
(7, 'YZ-404-AB', 'Peugeot', '3008', 'Hybride', 1, 0, 160000),
(8, 'CD-505-EF', 'Volkswagen', 'Golf', 'Essence', 1, 1, 67000),
(9, 'GH-606-IJ', 'Iveco', 'Daily', 'Diesel', 1, 1, 320000),
(10, 'KL-707-MN', 'Toyota', 'Yaris', 'Hybride', 1, 1, 12500);

--
-- Index pour les tables déchargées
--

--
-- Index pour la table `affectations_fixes`
--
ALTER TABLE `affectations_fixes`
  ADD PRIMARY KEY (`id_affectation`),
  ADD UNIQUE KEY `id_employe` (`id_employe`),
  ADD UNIQUE KEY `id_vehicule` (`id_vehicule`);

--
-- Index pour la table `conges`
--
ALTER TABLE `conges`
  ADD PRIMARY KEY (`id_conge`),
  ADD KEY `id_employe` (`id_employe`);

--
-- Index pour la table `employes`
--
ALTER TABLE `employes`
  ADD PRIMARY KEY (`id_employe`),
  ADD UNIQUE KEY `matricule` (`matricule`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Index pour la table `reservations`
--
ALTER TABLE `reservations`
  ADD PRIMARY KEY (`id_reservation`),
  ADD KEY `id_employe` (`id_employe`),
  ADD KEY `id_vehicule` (`id_vehicule`);

--
-- Index pour la table `vehicules`
--
ALTER TABLE `vehicules`
  ADD PRIMARY KEY (`id_vehicule`),
  ADD UNIQUE KEY `immatriculation` (`immatriculation`);

--
-- AUTO_INCREMENT pour les tables déchargées
--

--
-- AUTO_INCREMENT pour la table `affectations_fixes`
--
ALTER TABLE `affectations_fixes`
  MODIFY `id_affectation` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT pour la table `conges`
--
ALTER TABLE `conges`
  MODIFY `id_conge` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT pour la table `employes`
--
ALTER TABLE `employes`
  MODIFY `id_employe` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT pour la table `reservations`
--
ALTER TABLE `reservations`
  MODIFY `id_reservation` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT pour la table `vehicules`
--
ALTER TABLE `vehicules`
  MODIFY `id_vehicule` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- Contraintes pour les tables déchargées
--

--
-- Contraintes pour la table `affectations_fixes`
--
ALTER TABLE `affectations_fixes`
  ADD CONSTRAINT `affectations_fixes_ibfk_1` FOREIGN KEY (`id_employe`) REFERENCES `employes` (`id_employe`),
  ADD CONSTRAINT `affectations_fixes_ibfk_2` FOREIGN KEY (`id_vehicule`) REFERENCES `vehicules` (`id_vehicule`);

--
-- Contraintes pour la table `conges`
--
ALTER TABLE `conges`
  ADD CONSTRAINT `conges_ibfk_1` FOREIGN KEY (`id_employe`) REFERENCES `employes` (`id_employe`);

--
-- Contraintes pour la table `reservations`
--
ALTER TABLE `reservations`
  ADD CONSTRAINT `reservations_ibfk_1` FOREIGN KEY (`id_employe`) REFERENCES `employes` (`id_employe`),
  ADD CONSTRAINT `reservations_ibfk_2` FOREIGN KEY (`id_vehicule`) REFERENCES `vehicules` (`id_vehicule`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
