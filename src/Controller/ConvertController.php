<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class ConvertController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(Request $request): Response
    {
        if ($request->isMethod('POST')) {
    $file = $request->files->get('file');
    $format = $request->request->get('format');

    if (!$file) {
        return new Response("Aucun fichier reçu", 400);
    }

    // Vérifier la taille (5 Mo max)
    $maxSize = 5 * 1024 * 1024;
    if ($file->getSize() > $maxSize) {
        return new Response("Fichier trop gros. Max 5 Mo.", 400);
    }

    // Vérifier type MIME
    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($file->getMimeType(), $allowedMimes)) {
        return new Response("Format non supporté. Utilisez JPG, PNG ou WEBP.", 400);
    }

    $originalPath = $file->getPathname();

    // Charger l'image selon son type
    $mime = $file->getMimeType();
    $image = null;
    if ($mime === 'image/jpeg') $image = imagecreatefromjpeg($originalPath);
    if ($mime === 'image/png')  $image = imagecreatefrompng($originalPath);
    if ($mime === 'image/webp') $image = imagecreatefromwebp($originalPath);

    if (!$image) {
        return new Response("Impossible de traiter l'image.", 400);
    }

    // Fichier temporaire
    $tempFile = sys_get_temp_dir() . '/converted_' . uniqid() . '.' . $format;

    // Conversion
    switch ($format) {
        case 'png':  imagepng($image, $tempFile); break;
        case 'jpg':  imagejpeg($image, $tempFile); break;
        case 'webp': imagewebp($image, $tempFile); break;
        default: return new Response("Format cible inconnu.", 400);
    }

    imagedestroy($image);

    // Envoyer le fichier
    return $this->file($tempFile, 'converted.' . $format);
}

        return $this->render('convert/index.html.twig');
    }
}