-- ============================================================
-- Traceo — Cahier de Texte Numérique + Vie Scolaire
-- Schéma MySQL multi-tenant (etablissement_id sur chaque table)
-- ============================================================

CREATE DATABASE IF NOT EXISTS traceo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE traceo;

-- ============ CONFIG PAYS / MULTI-TENANT ============

CREATE TABLE pays (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(5) NOT NULL UNIQUE,          -- BJ, CI, SN, CM...
    nom VARCHAR(100) NOT NULL,
    seuil_absences_alerte INT NOT NULL DEFAULT 10,
    duree_defaut_punition_jours INT NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE etablissement (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pays_id INT NOT NULL,
    nom VARCHAR(150) NOT NULL,
    type ENUM('college','lycee') NOT NULL,
    mode_cahier_texte ENUM('libre','programme_importe') NOT NULL DEFAULT 'libre',
    seuil_absences_custom INT NULL,           -- override du seuil pays, NULL = utilise pays.seuil
    duree_punition_custom_jours INT NULL,     -- override durée punition
    seuil_recidive_punition INT NOT NULL DEFAULT 3, -- nb punitions avant notif récidive
    fenetre_recidive_jours INT NOT NULL DEFAULT 30,
    statut ENUM('actif','suspendu') NOT NULL DEFAULT 'actif',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pays_id) REFERENCES pays(id)
);

CREATE TABLE annee_scolaire (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    libelle VARCHAR(20) NOT NULL,             -- "2026-2027"
    date_debut DATE NOT NULL,
    date_fin DATE NOT NULL,
    statut ENUM('en_cours','archivee') NOT NULL DEFAULT 'en_cours',
    FOREIGN KEY (etablissement_id) REFERENCES etablissement(id),
    INDEX idx_etab (etablissement_id)
);

-- ============ UTILISATEURS / RÔLES ============

CREATE TABLE utilisateur (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    role ENUM('censeur','surveillant','prof','responsable_classe') NOT NULL,
    nom VARCHAR(100) NOT NULL,
    prenom VARCHAR(100) NOT NULL,
    identifiant VARCHAR(100) NOT NULL,
    mot_de_passe_hash VARCHAR(255) NOT NULL,
    telephone VARCHAR(30) NULL,
    statut ENUM('actif','inactif') NOT NULL DEFAULT 'actif',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (etablissement_id) REFERENCES etablissement(id),
    FOREIGN KEY (created_by) REFERENCES utilisateur(id),
    UNIQUE KEY uniq_login_par_etab (etablissement_id, identifiant),
    INDEX idx_etab_role (etablissement_id, role)
);

-- ============ STRUCTURE PÉDAGOGIQUE ============

CREATE TABLE filiere (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    nom VARCHAR(150) NOT NULL,                -- libre : "Développement Web et Mobile", "Couture"...
    niveau_debut VARCHAR(20) NOT NULL,        -- libre : "6e", "2nde"...
    diplome_intermediaire VARCHAR(100) NULL,
    diplome_final VARCHAR(100) NULL,
    FOREIGN KEY (etablissement_id) REFERENCES etablissement(id),
    INDEX idx_etab (etablissement_id)
);

CREATE TABLE classe (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    annee_scolaire_id INT NOT NULL,
    filiere_id INT NULL,                      -- NULL = pas de filière (ex: collège général)
    niveau VARCHAR(20) NOT NULL,               -- 6e, 5e, 2nde, Tle...
    nom_affichage VARCHAR(50) NOT NULL,        -- "6e A"
    FOREIGN KEY (etablissement_id) REFERENCES etablissement(id),
    FOREIGN KEY (annee_scolaire_id) REFERENCES annee_scolaire(id),
    FOREIGN KEY (filiere_id) REFERENCES filiere(id),
    INDEX idx_etab_annee (etablissement_id, annee_scolaire_id)
);

CREATE TABLE eleve (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    classe_id INT NOT NULL,
    nom VARCHAR(100) NOT NULL,
    prenom VARCHAR(100) NOT NULL,
    contact_parent VARCHAR(30) NULL,           -- tel/WhatsApp pour notif
    statut ENUM('actif','passe','redouble','parti') NOT NULL DEFAULT 'actif',
    eleve_precedent_id INT NULL,               -- chaîne l'historique après passage en classe sup.
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (etablissement_id) REFERENCES etablissement(id),
    FOREIGN KEY (classe_id) REFERENCES classe(id),
    FOREIGN KEY (eleve_precedent_id) REFERENCES eleve(id),
    INDEX idx_etab_classe (etablissement_id, classe_id)
);

CREATE TABLE matiere (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    nom VARCHAR(100) NOT NULL,
    FOREIGN KEY (etablissement_id) REFERENCES etablissement(id),
    INDEX idx_etab (etablissement_id)
);

CREATE TABLE programme_importe (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    matiere_id INT NOT NULL,
    fichier_source VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (etablissement_id) REFERENCES etablissement(id),
    FOREIGN KEY (matiere_id) REFERENCES matiere(id)
);

CREATE TABLE programme_chapitre (
    id INT AUTO_INCREMENT PRIMARY KEY,
    programme_importe_id INT NOT NULL,
    titre VARCHAR(255) NOT NULL,
    ordre INT NOT NULL DEFAULT 0,
    FOREIGN KEY (programme_importe_id) REFERENCES programme_importe(id)
);

-- ============ ACCÈS PROF / RESPONSABLE DE CLASSE ============

CREATE TABLE prof_matiere_classe (
    id INT AUTO_INCREMENT PRIMARY KEY,
    utilisateur_id INT NOT NULL,               -- prof
    classe_id INT NOT NULL,
    matiere_id INT NOT NULL,
    annee_scolaire_id INT NOT NULL,
    FOREIGN KEY (utilisateur_id) REFERENCES utilisateur(id),
    FOREIGN KEY (classe_id) REFERENCES classe(id),
    FOREIGN KEY (matiere_id) REFERENCES matiere(id),
    FOREIGN KEY (annee_scolaire_id) REFERENCES annee_scolaire(id),
    UNIQUE KEY uniq_affectation (utilisateur_id, classe_id, matiere_id, annee_scolaire_id)
);

CREATE TABLE responsable_classe (
    id INT AUTO_INCREMENT PRIMARY KEY,
    utilisateur_id INT NOT NULL,
    classe_id INT NOT NULL,
    annee_scolaire_id INT NOT NULL,
    date_election DATE NOT NULL,
    statut ENUM('actif','remplace') NOT NULL DEFAULT 'actif',
    FOREIGN KEY (utilisateur_id) REFERENCES utilisateur(id),
    FOREIGN KEY (classe_id) REFERENCES classe(id),
    FOREIGN KEY (annee_scolaire_id) REFERENCES annee_scolaire(id),
    INDEX idx_classe_statut (classe_id, statut)
    -- max 2 actifs par classe : contrôlé côté applicatif (helpers/tenant.php)
);

-- ============ EMPLOI DU TEMPS ============

CREATE TABLE creneau (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    annee_scolaire_id INT NOT NULL,
    classe_id INT NOT NULL,
    matiere_id INT NOT NULL,
    prof_id INT NOT NULL,
    jour_semaine TINYINT NOT NULL,              -- 1=lundi ... 7=dimanche
    heure_debut TIME NOT NULL,
    heure_fin TIME NOT NULL,
    salle VARCHAR(50) NULL,
    source ENUM('importe','cree_manuellement') NOT NULL DEFAULT 'cree_manuellement',
    FOREIGN KEY (etablissement_id) REFERENCES etablissement(id),
    FOREIGN KEY (annee_scolaire_id) REFERENCES annee_scolaire(id),
    FOREIGN KEY (classe_id) REFERENCES classe(id),
    FOREIGN KEY (matiere_id) REFERENCES matiere(id),
    FOREIGN KEY (prof_id) REFERENCES utilisateur(id),
    INDEX idx_prof_jour (prof_id, jour_semaine),
    INDEX idx_classe_jour (classe_id, jour_semaine)
);

-- ============ CAHIER DE TEXTE ============

CREATE TABLE seance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    creneau_id INT NOT NULL,
    date DATE NOT NULL,
    contenu_texte TEXT NULL,                    -- si mode libre
    chapitre_id INT NULL,                        -- si mode programme_importe
    statut_seance ENUM('tenue','annulee_prof','jour_ferie') NOT NULL DEFAULT 'tenue',
    statut_validation ENUM('en_attente','validee','forcee_censeur') NOT NULL DEFAULT 'en_attente',
    valide_par INT NULL,                          -- FK responsable_classe.id
    valide_le TIMESTAMP NULL,
    rappel_envoye_le TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (creneau_id) REFERENCES creneau(id),
    FOREIGN KEY (chapitre_id) REFERENCES programme_chapitre(id),
    FOREIGN KEY (valide_par) REFERENCES responsable_classe(id),
    UNIQUE KEY uniq_seance_jour (creneau_id, date),
    INDEX idx_date (date),
    INDEX idx_seance_statut (statut_validation)
);

-- ============ PRÉSENCES ============
-- Une ligne uniquement pour absent/retard. Présent = pas de ligne.

CREATE TABLE presence (
    id INT AUTO_INCREMENT PRIMARY KEY,
    seance_id INT NOT NULL,
    eleve_id INT NOT NULL,
    statut ENUM('absent','retard') NOT NULL,
    motif VARCHAR(255) NULL,                      -- NULL/'' = sans motif au moment de la saisie
    saisi_par INT NOT NULL,                        -- FK utilisateur (prof)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (seance_id) REFERENCES seance(id),
    FOREIGN KEY (eleve_id) REFERENCES eleve(id),
    FOREIGN KEY (saisi_par) REFERENCES utilisateur(id),
    UNIQUE KEY uniq_presence (seance_id, eleve_id),
    INDEX idx_eleve (eleve_id),
    INDEX idx_presence_statut (seance_id, statut)
);

-- ============ PUNITIONS ============

CREATE TABLE punition (
    id INT AUTO_INCREMENT PRIMARY KEY,
    eleve_id INT NOT NULL,
    gere_par INT NOT NULL,                         -- FK utilisateur (surveillant)
    motif VARCHAR(255) NOT NULL,
    date_attribution DATE NOT NULL,
    duree_jours INT NOT NULL,
    statut ENUM('a_faire','faite') NOT NULL DEFAULT 'a_faire',
    date_faite DATE NULL,
    FOREIGN KEY (eleve_id) REFERENCES eleve(id),
    FOREIGN KEY (gere_par) REFERENCES utilisateur(id),
    INDEX idx_eleve_statut (eleve_id, statut)
);

-- ============ NOTIFICATIONS ============

CREATE TABLE notification (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    type ENUM('absence','retard','recidive_punition','rappel_validation','rapport_periodique') NOT NULL,
    destinataire_contact VARCHAR(100) NOT NULL,
    canal ENUM('sms','whatsapp','email') NOT NULL,
    contenu TEXT NOT NULL,
    statut_envoi ENUM('en_attente','envoye','echec') NOT NULL DEFAULT 'en_attente',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (etablissement_id) REFERENCES etablissement(id),
    INDEX idx_statut (statut_envoi)
);

CREATE TABLE rapport_periodique (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    periode_debut DATE NOT NULL,
    periode_fin DATE NOT NULL,
    type ENUM('presence','cahier_texte') NOT NULL,
    destinataire_id INT NOT NULL,
    genere_le TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (etablissement_id) REFERENCES etablissement(id),
    FOREIGN KEY (destinataire_id) REFERENCES utilisateur(id)
);

-- ============ RÉINITIALISATION DE MOT DE PASSE ============

CREATE TABLE code_reset (
    id INT AUTO_INCREMENT PRIMARY KEY,
    utilisateur_id INT NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    expire_le DATETIME NOT NULL,
    utilise TINYINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (utilisateur_id) REFERENCES utilisateur(id),
    INDEX idx_utilisateur (utilisateur_id)
);

-- ============ JOURNAL D'AUDIT / SÉCURITÉ ============

CREATE TABLE journal_audit (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT NOT NULL,
    utilisateur_id INT NULL,
    action VARCHAR(100) NOT NULL,
    details TEXT NULL,
    adresse_ip VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (etablissement_id) REFERENCES etablissement(id),
    FOREIGN KEY (utilisateur_id) REFERENCES utilisateur(id),
    INDEX idx_etab_action (etablissement_id, action)
);

-- ============ SEED : quelques pays de départ ============

INSERT INTO pays (code, nom, seuil_absences_alerte, duree_defaut_punition_jours) VALUES
('BJ', 'Bénin', 10, 1),
('CI', 'Côte d''Ivoire', 12, 1),
('SN', 'Sénégal', 10, 1),
('TG', 'Togo', 10, 1);

-- ============ CONTRAINTE APPLICATIVE EN BASE ============
-- Max 2 responsables actifs par classe (défense en profondeur,
-- en complément du contrôle dans responsables.php).

DROP TRIGGER IF EXISTS trg_check_max_responsables;

DELIMITER //
CREATE TRIGGER trg_check_max_responsables
BEFORE INSERT ON responsable_classe
FOR EACH ROW
BEGIN
    IF (SELECT COUNT(*) FROM responsable_classe
        WHERE classe_id = NEW.classe_id
          AND annee_scolaire_id = NEW.annee_scolaire_id
          AND statut = 'actif') >= 2 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'max 2 responsables actifs par classe';
    END IF;
END//
DELIMITER ;
