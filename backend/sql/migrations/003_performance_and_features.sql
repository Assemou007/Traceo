-- ============================================================
-- Migration 003 : Journal d'audit, Statut séance & Indexations
-- ============================================================

CREATE TABLE IF NOT EXISTS journal_audit (
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

ALTER TABLE seance ADD COLUMN statut_seance ENUM('tenue','annulee_prof','jour_ferie') NOT NULL DEFAULT 'tenue' AFTER chapitre_id;

CREATE INDEX idx_seance_statut ON seance (statut_validation);
CREATE INDEX idx_presence_statut ON presence (seance_id, statut);
