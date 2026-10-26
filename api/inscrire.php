<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Cloudinary\Cloudinary;

const MAX_PHOTO_OCTETS = 5 * 1024 * 1024; // 5 Mo
const TYPES_AUTORISES  = ['image/jpeg', 'image/png', 'image/webp'];

function repondre(int $code, array $corps): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($corps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    repondre(405, ['success' => false, 'message' => 'Méthode non autorisée. Utilisez POST.']);
}

try {
    // 1. Données du formulaire
    $nom    = trim((string) ($_POST['nom'] ?? ''));
    $prenom = trim((string) ($_POST['prenom'] ?? ''));
    $ville  = trim((string) ($_POST['ville'] ?? ''));

    if ($nom === '' || $prenom === '' || $ville === '') {
        repondre(422, ['success' => false, 'message' => 'Nom, prénom et ville sont obligatoires.']);
    }
    if (mb_strlen($nom) > 100 || mb_strlen($prenom) > 100 || mb_strlen($ville) > 100) {
        repondre(422, ['success' => false, 'message' => 'Un champ dépasse 100 caractères.']);
    }

    // 2. Fichier image
    $photo = $_FILES['photo'] ?? null;
    if ($photo === null || ($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        repondre(422, ['success' => false, 'message' => 'Photo manquante ou invalide.']);
    }
    if (!is_uploaded_file($photo['tmp_name'])) {
        repondre(422, ['success' => false, 'message' => 'Fichier non valide.']);
    }
    if ($photo['size'] > MAX_PHOTO_OCTETS) {
        repondre(422, ['success' => false, 'message' => 'La photo ne doit pas dépasser 5 Mo.']);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string) $finfo->file($photo['tmp_name']);
    if (!in_array($mime, TYPES_AUTORISES, true) || @getimagesize($photo['tmp_name']) === false) {
        repondre(422, ['success' => false, 'message' => 'Format accepté : JPG, PNG ou WebP.']);
    }

    // 3. Envoi vers Cloudinary
    $cloudinary = new Cloudinary(getenv('CLOUDINARY_URL'));
    $resultat = $cloudinary->uploadApi()->upload($photo['tmp_name'], [
        'folder' => 'stagiaires',
    ]);

    // 4. URL sécurisée
    $photoUrl = $resultat['secure_url'] ?? '';
    $publicId = $resultat['public_id'] ?? '';
    if ($photoUrl === '' || !str_starts_with($photoUrl, 'https://')) {
        throw new RuntimeException("Cloudinary n'a pas renvoyé d'URL sécurisée.");
    }

    // 5. Insertion dans MySQL
    try {
        $pdo = getDatabase();
        $stmt = $pdo->prepare(
            'INSERT INTO stagiaires (nom, prenom, ville, photo)
             VALUES (:nom, :prenom, :ville, :photo)'
        );
        $stmt->execute([
            ':nom'    => $nom,
            ':prenom' => $prenom,
            ':ville'  => $ville,
            ':photo'  => $photoUrl,
        ]);
    } catch (Throwable $e) {
        // Si l'insertion échoue, on retire la photo pour ne pas laisser de fichier orphelin.
        if ($publicId !== '') {
            try { $cloudinary->uploadApi()->destroy($publicId); } catch (Throwable $ignore) {}
        }
        throw $e;
    }

    // 6. Réponse JSON
    repondre(201, ['success' => true, 'message' => 'Stagiaire inscrit avec succès.']);
} catch (Throwable $e) {
    error_log('[inscrire.php] ' . $e::class . ' : ' . $e->getMessage());
    // TEMPORAIRE : à remplacer par un message générique quand tout marche
    repondre(500, ['success' => false, 'message' => 'DEBUG: ' . $e->getMessage()]);
}