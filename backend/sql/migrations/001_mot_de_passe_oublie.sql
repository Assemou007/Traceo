-- ============================================================
-- Migration 001 — Mot de passe oublié
-- Table des codes de réinitialisation (code à 6 chiffres via SMS)
-- ============================================================

CREATE TABLE IF NOT EXISTS code_reset (
    id INT AUTO_INCREMENT PRIMARY KEY,
    utilisateur_id INT NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    expire_le DATETIME NOT NULL,
    utilise TINYINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (utilisateur_id) REFERENCES utilisateur(id),
    INDEX idx_utilisateur (utilisateur_id)
);
