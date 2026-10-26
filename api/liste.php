<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

const LIMITE_DEFAUT = 20;
const LIMITE_MAX    = 100;

function repondre(int $code, array $corps): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($corps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    repondre(405, ['success' => false, 'message' => 'Méthode non autorisée. Utilisez GET.']);
}

try {
    $page   = max(1, (int) ($_GET['page'] ?? 1));
    $limite = (int) ($_GET['limite'] ?? LIMITE_DEFAUT);
    $limite = min(LIMITE_MAX, max(1, $limite));
    $offset = ($page - 1) * $limite;

    $pdo = getDatabase();

    $total = (int) $pdo->query('SELECT COUNT(*) FROM stagiaires')->fetchColumn();

    $requete = $pdo->prepare(
        'SELECT id, nom, prenom, ville, photo, date_inscription
           FROM stagiaires
          ORDER BY id DESC
          LIMIT :limite OFFSET :offset'
    );
    $requete->bindValue(':limite', $limite, PDO::PARAM_INT);
    $requete->bindValue(':offset', $offset, PDO::PARAM_INT);
    $requete->execute();

    $stagiaires = $requete->fetchAll();
    foreach ($stagiaires as &$ligne) {
        $ligne['id'] = (int) $ligne['id'];
    }
    unset($ligne);

    repondre(200, [
        'success' => true,
        'data'    => $stagiaires,
        'meta'    => [
            'total'  => $total,
            'page'   => $page,
            'limite' => $limite,
            'pages'  => (int) ceil($total / $limite),
        ],
    ]);
} catch (Throwable $e) {
    error_log('[liste.php] ' . $e::class . ' : ' . $e->getMessage());
    // TEMPORAIRE : pour voir l'erreur réelle
    repondre(500, ['success' => false, 'message' => 'DEBUG: ' . $e->getMessage()]);
}
