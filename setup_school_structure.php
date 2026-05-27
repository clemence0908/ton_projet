<?php
require_once 'config.php';

try {
    // Check if tables exist, and clear them to rebuild the correct structure if needed.
    // Ensure amphis and groupes_td exist.
    
    $promotions = $pdo->query("SELECT id, nom_promotion FROM promotions")->fetchAll();
    
    foreach ($promotions as $promo) {
        $promo_id = $promo['id'];
        
        // Ensure 3 Amphis per promotion
        for ($i = 1; $i <= 3; $i++) {
            $amphi_nom = "Amphi " . $i;
            
            $stmt = $pdo->prepare("SELECT id FROM amphis WHERE nom = ? AND promotion_id = ?");
            $stmt->execute([$amphi_nom, $promo_id]);
            $amphi_id = $stmt->fetchColumn();
            
            if (!$amphi_id) {
                $pdo->prepare("INSERT INTO amphis (nom, promotion_id) VALUES (?, ?)")->execute([$amphi_nom, $promo_id]);
                $amphi_id = $pdo->lastInsertId();
            }
            
            // Ensure 2 TDs per Amphi (Total 6 per promotion)
            $start_td = ($i - 1) * 2 + 1;
            for ($j = $start_td; $j <= $start_td + 1; $j++) {
                $td_nom = "TD" . $j;
                $stmtTD = $pdo->prepare("SELECT id FROM groupes_td WHERE nom = ? AND amphi_id = ?");
                $stmtTD->execute([$td_nom, $amphi_id]);
                if (!$stmtTD->fetchColumn()) {
                    $pdo->prepare("INSERT INTO groupes_td (nom, amphi_id) VALUES (?, ?)")->execute([$td_nom, $amphi_id]);
                }
            }
        }
    }
    echo "Structure (3 Amphis, 6 TDs par promo) verifiee et mise a jour.";
} catch (Exception $e) {
    echo "Erreur : " . $e->getMessage();
}
?>