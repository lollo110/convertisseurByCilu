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

            $originalPath = $file->getPathname();
            $mime = $file->getMimeType();

            // ------------------------
            // 🖼️ IMAGES
            // ------------------------
            $imageMimes = ['image/jpeg', 'image/png', 'image/webp'];

            if (in_array($mime, $imageMimes)) {
                $image = null;

                if ($mime === 'image/jpeg')
                    $image = imagecreatefromjpeg($originalPath);
                if ($mime === 'image/png')
                    $image = imagecreatefrompng($originalPath);
                if ($mime === 'image/webp')
                    $image = imagecreatefromwebp($originalPath);

                if (!$image) {
                    return new Response("Erreur lecture image", 400);
                }

                $tempFile = sys_get_temp_dir() . '\\converted_' . uniqid() . '.' . $format;

                switch ($format) {
                    case 'png':
                        imagepng($image, $tempFile);
                        break;
                    case 'jpg':
                        imagejpeg($image, $tempFile);
                        break;
                    case 'webp':
                        imagewebp($image, $tempFile);
                        break;
                    default:
                        return new Response("Format image invalide", 400);
                }

                imagedestroy($image);

                return $this->file($tempFile, 'converted.' . $format);
            }

            // ------------------------
// 📄 WORD -> PDF (FIABLE)
// ------------------------

            $wordMimes = [
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            ];

            if (in_array($mime, $wordMimes)) {

                if ($format !== 'pdf') {
                    return new Response("Seule conversion Word → PDF autorisée", 400);
                }

                // ✅ Sauvegarder avec vraie extension
                $extension = $file->getClientOriginalExtension(); // doc ou docx
                $filename = uniqid() . '.' . $extension;
                $file->move(sys_get_temp_dir(), $filename);

                $inputPath = sys_get_temp_dir() . '\\' . $filename;
                $outputDir = sys_get_temp_dir();

                // ⚠️ Chemin LibreOffice
                $soffice = "C:\\Program Files\\LibreOffice\\program\\soffice.exe";

                $cmd = sprintf(
                    '%s --headless --convert-to pdf --outdir %s %s 2>&1',
                    escapeshellarg($soffice),
                    escapeshellarg($outputDir),
                    escapeshellarg($inputPath)
                );

                exec($cmd, $output, $returnVar);

                if ($returnVar !== 0) {
                    return new Response("Erreur conversion:\n" . implode("\n", $output), 500);
                }

                $outputFile = $outputDir . '\\' . pathinfo($filename, PATHINFO_FILENAME) . '.pdf';

                if (!file_exists($outputFile)) {
                    return new Response("Fichier PDF non généré", 500);
                }

                return $this->file($outputFile, 'converted.pdf');
            }

            return new Response("Type de fichier non supporté", 400);
        }

        return $this->render('convert/index.html.twig');
    }
}