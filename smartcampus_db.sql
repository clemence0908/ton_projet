-- phpMyAdmin SQL Dump
-- version 5.1.2
-- https://www.phpmyadmin.net/
--
-- Hôte : localhost:3306
-- Généré le : mer. 27 mai 2026 à 15:29
-- Version du serveur : 5.7.24
-- Version de PHP : 8.3.1

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de données : `smartcampus_db`
--

-- --------------------------------------------------------

--
-- Structure de la table `amphis`
--

CREATE TABLE `amphis` (
  `id` int(11) NOT NULL,
  `nom` varchar(50) NOT NULL,
  `promotion_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Structure de la table `cours`
--

CREATE TABLE `cours` (
  `id` int(11) NOT NULL,
  `ue_id` int(11) NOT NULL,
  `nom_cours` varchar(150) NOT NULL,
  `cours_prerequis_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Déchargement des données de la table `cours`
--

INSERT INTO `cours` (`id`, `ue_id`, `nom_cours`, `cours_prerequis_id`) VALUES
(1, 1, 'Bases du Développement Web (ING3)', NULL),
(2, 1, 'Architecture des Systèmes Web (ING4)', 1);

-- --------------------------------------------------------

--
-- Structure de la table `etudiants`
--

CREATE TABLE `etudiants` (
  `utilisateur_id` int(11) NOT NULL,
  `promotion_id` int(11) NOT NULL,
  `statut_parcours` enum('initial','alternant') DEFAULT 'initial',
  `score_toeic` int(11) DEFAULT NULL,
  `toeic_valide` tinyint(1) GENERATED ALWAYS AS ((`score_toeic` >= 785)) STORED,
  `groupe_td_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Déchargement des données de la table `etudiants`
--

INSERT INTO `etudiants` (`utilisateur_id`, `promotion_id`, `statut_parcours`, `score_toeic`, `groupe_td_id`) VALUES
(1, 3, 'initial', 820, NULL),
(2, 1, 'initial', 760, NULL),
(3, 3, 'initial', 820, NULL),
(4, 1, 'initial', 760, NULL),
(5, 1, 'initial', 810, NULL),
(6, 1, 'alternant', 790, NULL),
(7, 1, 'initial', 720, NULL),
(8, 2, 'initial', 850, NULL),
(9, 2, 'alternant', 780, NULL),
(10, 2, 'initial', 800, NULL),
(11, 2, 'initial', 735, NULL),
(12, 2, 'alternant', 820, NULL),
(13, 3, 'initial', 770, NULL),
(14, 3, 'initial', 815, NULL),
(15, 3, 'alternant', 790, NULL),
(16, 3, 'initial', 700, NULL),
(17, 3, 'initial', 840, NULL),
(18, 1, 'alternant', 795, NULL),
(19, 1, 'initial', 760, NULL),
(20, 2, 'initial', 830, NULL),
(21, 2, 'alternant', 785, NULL),
(22, 3, 'initial', 810, NULL),
(23, 3, 'alternant', 750, NULL),
(24, 1, 'initial', 790, NULL);

-- --------------------------------------------------------

--
-- Structure de la table `etudiants_profil`
--

CREATE TABLE `etudiants_profil` (
  `id` int(11) NOT NULL,
  `utilisateur_id` int(11) DEFAULT NULL,
  `promotion_id` int(11) DEFAULT NULL,
  `td_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Structure de la table `groupes_td`
--

CREATE TABLE `groupes_td` (
  `id` int(11) NOT NULL,
  `nom` varchar(50) NOT NULL,
  `amphi_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Structure de la table `inscriptions_cours`
--

CREATE TABLE `inscriptions_cours` (
  `etudiant_id` int(11) NOT NULL,
  `cours_id` int(11) NOT NULL,
  `date_inscription` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Déchargement des données de la table `inscriptions_cours`
--

INSERT INTO `inscriptions_cours` (`etudiant_id`, `cours_id`, `date_inscription`) VALUES
(3, 2, '2025-09-01');

-- --------------------------------------------------------

--
-- Structure de la table `majeures`
--

CREATE TABLE `majeures` (
  `id` int(11) NOT NULL,
  `nom_majeure` varchar(100) NOT NULL,
  `code_majeure` varchar(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Déchargement des données de la table `majeures`
--

INSERT INTO `majeures` (`id`, `nom_majeure`, `code_majeure`) VALUES
(1, 'Génie Logiciel & Applications Web', 'GL'),
(2, 'Cybersécurité & Cloud', 'CYB');

-- --------------------------------------------------------

--
-- Structure de la table `messages`
--

CREATE TABLE `messages` (
  `id` int(11) NOT NULL,
  `expediteur_id` int(11) DEFAULT NULL,
  `destinataire_id` int(11) DEFAULT NULL,
  `contenu` text,
  `date_envoi` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Structure de la table `notes`
--

CREATE TABLE `notes` (
  `id` int(11) NOT NULL,
  `etudiant_id` int(11) NOT NULL,
  `cours_id` int(11) NOT NULL,
  `enseignant_id` int(11) NOT NULL,
  `note_valeur` decimal(4,2) NOT NULL,
  `type_evaluation` enum('CC','Examen') NOT NULL,
  `statut_verrouillage` enum('en_cours','valide_definitif') DEFAULT 'en_cours',
  `date_saisie` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Déchargement des données de la table `notes`
--

INSERT INTO `notes` (`id`, `etudiant_id`, `cours_id`, `enseignant_id`, `note_valeur`, `type_evaluation`, `statut_verrouillage`, `date_saisie`) VALUES
(1, 3, 2, 25, '14.50', 'CC', 'valide_definitif', '2026-05-26 22:27:00'),
(2, 3, 2, 25, '12.00', 'Examen', 'en_cours', '2026-05-26 22:27:00');

-- --------------------------------------------------------

--
-- Structure de la table `presences`
--

CREATE TABLE `presences` (
  `id` int(11) NOT NULL,
  `session_cours_id` int(11) NOT NULL,
  `etudiant_id` int(11) NOT NULL,
  `statut_presence` enum('present','absent_justifie','absent_injustifie') NOT NULL DEFAULT 'present',
  `date_marquage` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Structure de la table `promotions`
--

CREATE TABLE `promotions` (
  `id` int(11) NOT NULL,
  `nom_promotion` enum('ING1','ING2','ING3','ING4','ING5') NOT NULL,
  `annee_academique` varchar(20) NOT NULL,
  `majeure_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Déchargement des données de la table `promotions`
--

INSERT INTO `promotions` (`id`, `nom_promotion`, `annee_academique`, `majeure_id`) VALUES
(1, 'ING1', '2025-2026', NULL),
(2, 'ING3', '2025-2026', NULL),
(3, 'ING4', '2025-2026', 1);

-- --------------------------------------------------------

--
-- Structure de la table `salles`
--

CREATE TABLE `salles` (
  `id` int(11) NOT NULL,
  `nom_salle` varchar(50) NOT NULL,
  `type_salle` enum('Amphi','TD','TP') NOT NULL,
  `capacite_max` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Déchargement des données de la table `salles`
--

INSERT INTO `salles` (`id`, `nom_salle`, `type_salle`, `capacite_max`) VALUES
(1, 'Amphi Georges Charpak', 'Amphi', 150),
(2, 'Labo Informatique 3', 'TP', 35),
(3, 'Petite Salle TD 104', 'TD', 30);

-- --------------------------------------------------------

--
-- Structure de la table `rendez_vous`
--

CREATE TABLE `rendez_vous` (
  `id` int(11) NOT NULL,
  `etudiant_id` int(11) NOT NULL,
  `enseignant_id` int(11) NOT NULL,
  `sujet` varchar(150) NOT NULL,
  `message` text,
  `date_rdv` date NOT NULL,
  `heure_debut` time NOT NULL,
  `heure_fin` time NOT NULL,
  `statut` enum('en_attente','accepte','refuse','annule') NOT NULL DEFAULT 'en_attente',
  `reponse_enseignant` text,
  `date_demande` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_reponse` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Structure de la table `sessions_cours`
--

CREATE TABLE `sessions_cours` (
  `id` int(11) NOT NULL,
  `cours_id` int(11) NOT NULL,
  `enseignant_id` int(11) NOT NULL,
  `salle_id` int(11) NOT NULL,
  `date_cours` date NOT NULL,
  `heure_debut` time NOT NULL,
  `heure_fin` time NOT NULL,
  `groupe_td_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Déchargement des données de la table `sessions_cours`
--

INSERT INTO `sessions_cours` (`id`, `cours_id`, `enseignant_id`, `salle_id`, `date_cours`, `heure_debut`, `heure_fin`, `groupe_td_id`) VALUES
(1, 2, 25, 2, '2026-05-25', '08:30:00', '10:15:00', NULL);

-- --------------------------------------------------------

--
-- Structure de la table `unites_enseignement`
--

CREATE TABLE `unites_enseignement` (
  `id` int(11) NOT NULL,
  `code_ue` varchar(20) NOT NULL,
  `nom_ue` varchar(150) NOT NULL,
  `credits_ects` int(11) NOT NULL DEFAULT '6',
  `semestre` enum('S1','S2') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Déchargement des données de la table `unites_enseignement`
--

INSERT INTO `unites_enseignement` (`id`, `code_ue`, `nom_ue`, `credits_ects`, `semestre`) VALUES
(1, 'UE-INF401', 'Développement Logiciel Avancé', 6, 'S1');

-- --------------------------------------------------------

--
-- Structure de la table `utilisateurs`
--

CREATE TABLE `utilisateurs` (
  `id` int(11) NOT NULL,
  `email` varchar(150) NOT NULL,
  `mot_de_pass` varchar(255) NOT NULL,
  `nom` varchar(100) NOT NULL,
  `prenom` varchar(100) NOT NULL,
  `role` enum('etudiant','enseignant','admin') NOT NULL,
  `date_creation` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Déchargement des données de la table `utilisateurs`
--

INSERT INTO `utilisateurs` (`id`, `email`, `mot_de_pass`, `nom`, `prenom`, `role`, `date_creation`) VALUES

-- Étudiants
(1, 'emma.martin@ecole.fr', 'password123', 'Martin', 'Emma', 'etudiant', '2026-05-26 22:26:59'),
(2, 'clemence.audebert@ecole.fr', 'password123', 'Audebert', 'Clemence', 'etudiant', '2026-05-26 22:26:59'),
(3, 'tasnyme.tebib@ecole.fr', 'password123', 'Tebib', 'Tasnyme', 'etudiant', '2026-05-26 22:26:59'),
(4, 'aloise.martin@ecole.fr', 'password123', 'Martin', 'Aloise', 'etudiant', '2026-05-26 22:26:59'),
(5, 'lea.moreau@ecole.fr', 'password123', 'Moreau', 'Léa', 'etudiant', '2026-05-26 22:26:59'),
(6, 'hugo.petit@ecole.fr', 'password123', 'Petit', 'Hugo', 'etudiant', '2026-05-26 22:26:59'),
(7, 'chloe.garcia@ecole.fr', 'password123', 'Garcia', 'Chloé', 'etudiant', '2026-05-26 22:26:59'),
(8, 'nathan.roux@ecole.fr', 'password123', 'Roux', 'Nathan', 'etudiant', '2026-05-26 22:26:59'),
(9, 'laure.leroy@ecole.fr', 'password123', 'Leroy', 'Laure', 'etudiant', '2026-05-26 22:26:59'),
(10, 'enzo.david@ecole.fr', 'password123', 'David', 'Enzo', 'etudiant', '2026-05-26 22:26:59'),
(11, 'jade.mouassi@ecole.fr', 'password123', 'Mouassi', 'Jade', 'etudiant', '2026-05-26 22:26:59'),
(12, 'noah.dubois@ecole.fr', 'password123', 'Dubois', 'Noah', 'etudiant', '2026-05-26 22:26:59'),
(13, 'alice.fournier@ecole.fr', 'password123', 'Fournier', 'Alice', 'etudiant', '2026-05-26 22:26:59'),
(14, 'louis.morel@ecole.fr', 'password123', 'Morel', 'Louis', 'etudiant', '2026-05-26 22:26:59'),
(15, 'ines.laurent@ecole.fr', 'password123', 'Laurent', 'Inès', 'etudiant','2026-05-26 22:26:59'),
(16, 'adam.simon@ecole.fr', 'password123', 'Simon', 'Adam', 'etudiant', '2026-05-26 22:26:59'),
(17, 'lina.michel@ecole.fr', 'password123', 'Michel', 'Lina', 'etudiant', '2026-05-26 22:26:59'),
(18, 'ethan.lefevre@ecole.fr', 'password123', 'Lefevre', 'Ethan', 'etudiant', '2026-05-26 22:26:59'),
(19, 'sarah.mercier@ecole.fr', 'password123', 'Mercier', 'Sarah', 'etudiant', '2026-05-26 22:26:59'),
(20, 'paul.blanc@ecole.fr', 'password123', 'Blanc', 'Paul', 'etudiant', '2026-05-26 22:26:59'),
(21, 'anna.guerin@ecole.fr', 'password123', 'Guerin', 'Anna', 'etudiant', '2026-05-26 22:26:59'),
(22, 'leo.chevalier@ecole.fr', 'password123', 'Chevalier', 'Léo', 'etudiant', '2026-05-26 22:26:59'),
(23, 'robin.desbois@ecole.fr', 'password123', 'Des Bois', 'Robin', 'etudiant', '2026-05-26 22:26:59'),
(24, 'lucas.bernard@ecole.fr', 'password123', 'Bernard', 'Lucas', 'etudiant', '2026-05-26 22:26:59'),

-- Enseignants
(25, 'durand.prof@ecole.fr', 'password123', 'Durand', 'Pierre', 'enseignant', '2026-05-26 22:26:59'),
(26, 'lambert.prof@ecole.fr', 'password123', 'Lambert', 'Sophie', 'enseignant', '2026-05-26 22:26:59' ),
(27, 'girard.prof@ecole.fr', 'password123', 'Girard', 'Antoine', 'enseignant', '2026-05-26 22:26:59'),
(28, 'andre.prof@ecole.fr', 'password123', 'Andre', 'Marie', 'enseignant', '2026-05-26 22:26:59'),
(29, 'muller.prof@ecole.fr', 'password123', 'Muller', 'Thomas', 'enseignant', '2026-05-26 22:26:59'),
(30, 'faure.prof@ecole.fr', 'password123', 'Faure', 'Camille', 'enseignant', '2026-05-26 22:26:59'),
(31, 'perrin.prof@ecole.fr', 'password123', 'Perrin', 'Nicolas', 'enseignant', '2026-05-26 22:26:59'),
(32, 'renard.prof@ecole.fr', 'password123', 'Renard', 'Claire', 'enseignant', '2026-05-26 22:26:59'),

-- Administrateurs
(33, 'direction.studies@ecole.fr', 'password123', 'Lemoine', 'Claire', 'admin', '2026-05-26 22:26:59'),
(34, 'admin1@ecole.fr', 'password123', 'Carreau', 'Julie', 'admin', '2026-05-26 22:26:59'),
(35, 'admin2@ecole.fr', 'password123', 'Jones', 'Hervé', 'admin', '2026-05-26 22:26:59');

--
-- Index pour les tables déchargées
--

--
-- Index pour la table `amphis`
--
ALTER TABLE `amphis`
  ADD PRIMARY KEY (`id`),
  ADD KEY `promotion_id` (`promotion_id`);

--
-- Index pour la table `cours`
--
ALTER TABLE `cours`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ue_id` (`ue_id`),
  ADD KEY `cours_prerequis_id` (`cours_prerequis_id`);

--
-- Index pour la table `etudiants`
--
ALTER TABLE `etudiants`
  ADD PRIMARY KEY (`utilisateur_id`),
  ADD KEY `promotion_id` (`promotion_id`),
  ADD KEY `fk_etudiant_td` (`groupe_td_id`);

--
-- Index pour la table `etudiants_profil`
--
ALTER TABLE `etudiants_profil`
  ADD PRIMARY KEY (`id`);

--
-- Index pour la table `groupes_td`
--
ALTER TABLE `groupes_td`
  ADD PRIMARY KEY (`id`),
  ADD KEY `amphi_id` (`amphi_id`);

--
-- Index pour la table `inscriptions_cours`
--
ALTER TABLE `inscriptions_cours`
  ADD PRIMARY KEY (`etudiant_id`,`cours_id`),
  ADD KEY `cours_id` (`cours_id`);

--
-- Index pour la table `majeures`
--
ALTER TABLE `majeures`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code_majeure` (`code_majeure`);

--
-- Index pour la table `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`id`);

--
-- Index pour la table `notes`
--
ALTER TABLE `notes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `etudiant_id` (`etudiant_id`),
  ADD KEY `cours_id` (`cours_id`),
  ADD KEY `enseignant_id` (`enseignant_id`);

--
-- Index pour la table `presences`
--
ALTER TABLE `presences`
  ADD PRIMARY KEY (`id`),
  ADD KEY `session_cours_id` (`session_cours_id`),
  ADD KEY `etudiant_id` (`etudiant_id`);

--
-- Index pour la table `rendez_vous`
--
ALTER TABLE `rendez_vous`
  ADD PRIMARY KEY (`id`),
  ADD KEY `etudiant_id` (`etudiant_id`),
  ADD KEY `enseignant_id` (`enseignant_id`);

--
-- Index pour la table `promotions`
--
ALTER TABLE `promotions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `majeure_id` (`majeure_id`);

--
-- Index pour la table `salles`
--
ALTER TABLE `salles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nom_salle` (`nom_salle`);

--
-- Index pour la table `sessions_cours`
--
ALTER TABLE `sessions_cours`
  ADD PRIMARY KEY (`id`),
  ADD KEY `cours_id` (`cours_id`),
  ADD KEY `enseignant_id` (`enseignant_id`),
  ADD KEY `salle_id` (`salle_id`),
  ADD KEY `fk_session_groupe_td` (`groupe_td_id`);

--
-- Index pour la table `unites_enseignement`
--
ALTER TABLE `unites_enseignement`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code_ue` (`code_ue`);

--
-- Index pour la table `utilisateurs`
--
ALTER TABLE `utilisateurs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT pour les tables déchargées
--

--
-- AUTO_INCREMENT pour la table `amphis`
--
ALTER TABLE `amphis`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `cours`
--
ALTER TABLE `cours`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT pour la table `etudiants_profil`
--
ALTER TABLE `etudiants_profil`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `groupes_td`
--
ALTER TABLE `groupes_td`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `majeures`
--
ALTER TABLE `majeures`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT pour la table `messages`
--
ALTER TABLE `messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `notes`
--
ALTER TABLE `notes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT pour la table `presences`
--
ALTER TABLE `presences`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `promotions`
--
ALTER TABLE `promotions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT pour la table `rendez_vous`
--
ALTER TABLE `rendez_vous`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `salles`
--
ALTER TABLE `salles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT pour la table `sessions_cours`
--
ALTER TABLE `sessions_cours`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT pour la table `unites_enseignement`
--
ALTER TABLE `unites_enseignement`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT pour la table `utilisateurs`
--
ALTER TABLE `utilisateurs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=36;

--
-- Contraintes pour les tables déchargées
--

--
-- Contraintes pour la table `amphis`
--
ALTER TABLE `amphis`
  ADD CONSTRAINT `amphis_ibfk_1` FOREIGN KEY (`promotion_id`) REFERENCES `promotions` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `cours`
--
ALTER TABLE `cours`
  ADD CONSTRAINT `cours_ibfk_1` FOREIGN KEY (`ue_id`) REFERENCES `unites_enseignement` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cours_ibfk_2` FOREIGN KEY (`cours_prerequis_id`) REFERENCES `cours` (`id`) ON DELETE SET NULL;

--
-- Contraintes pour la table `etudiants`
--
ALTER TABLE `etudiants`
  ADD CONSTRAINT `etudiants_ibfk_1` FOREIGN KEY (`utilisateur_id`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `etudiants_ibfk_2` FOREIGN KEY (`promotion_id`) REFERENCES `promotions` (`id`),
  ADD CONSTRAINT `fk_etudiant_td` FOREIGN KEY (`groupe_td_id`) REFERENCES `groupes_td` (`id`) ON DELETE SET NULL;

--
-- Contraintes pour la table `groupes_td`
--
ALTER TABLE `groupes_td`
  ADD CONSTRAINT `groupes_td_ibfk_1` FOREIGN KEY (`amphi_id`) REFERENCES `amphis` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `inscriptions_cours`
--
ALTER TABLE `inscriptions_cours`
  ADD CONSTRAINT `inscriptions_cours_ibfk_1` FOREIGN KEY (`etudiant_id`) REFERENCES `etudiants` (`utilisateur_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `inscriptions_cours_ibfk_2` FOREIGN KEY (`cours_id`) REFERENCES `cours` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `notes`
--
ALTER TABLE `notes`
  ADD CONSTRAINT `notes_ibfk_1` FOREIGN KEY (`etudiant_id`) REFERENCES `etudiants` (`utilisateur_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `notes_ibfk_2` FOREIGN KEY (`cours_id`) REFERENCES `cours` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `notes_ibfk_3` FOREIGN KEY (`enseignant_id`) REFERENCES `utilisateurs` (`id`);

--
-- Contraintes pour la table `presences`
--
ALTER TABLE `presences`
  ADD CONSTRAINT `presences_ibfk_1` FOREIGN KEY (`session_cours_id`) REFERENCES `sessions_cours` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `presences_ibfk_2` FOREIGN KEY (`etudiant_id`) REFERENCES `etudiants` (`utilisateur_id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `promotions`
--
ALTER TABLE `promotions`
  ADD CONSTRAINT `promotions_ibfk_1` FOREIGN KEY (`majeure_id`) REFERENCES `majeures` (`id`) ON DELETE SET NULL;

--
-- Contraintes pour la table `sessions_cours`
--
ALTER TABLE `sessions_cours`
  ADD CONSTRAINT `fk_session_groupe_td` FOREIGN KEY (`groupe_td_id`) REFERENCES `groupes_td` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `sessions_cours_ibfk_1` FOREIGN KEY (`cours_id`) REFERENCES `cours` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sessions_cours_ibfk_2` FOREIGN KEY (`enseignant_id`) REFERENCES `utilisateurs` (`id`),
  ADD CONSTRAINT `sessions_cours_ibfk_3` FOREIGN KEY (`salle_id`) REFERENCES `salles` (`id`);

--
-- Contraintes pour la table `rendez_vous`
--
ALTER TABLE `rendez_vous`
  ADD CONSTRAINT `rendez_vous_ibfk_1` FOREIGN KEY (`etudiant_id`) REFERENCES `etudiants` (`utilisateur_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `rendez_vous_ibfk_2` FOREIGN KEY (`enseignant_id`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
