-- ============================================================
-- Migration 002 — Contrainte "max 2 responsables actifs par classe"
-- Enforcée en base en complément du contrôle applicatif.
-- ============================================================

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
