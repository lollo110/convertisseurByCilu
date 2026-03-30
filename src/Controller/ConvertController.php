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
            $pdfMimes = ['application/pdf'];
            $wordMimes = [
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            ];
            $videoMimes = [
                'video/mp4',
                'video/x-msvideo', // avi
                'video/quicktime', // mov
                'video/x-matroska', // mkv
                'video/webm'
            ];

            // ------------------------
            // IMAGE → IMAGE / PDF
            // ------------------------
            if (in_array($mime, $imageMimes)) {

                if ($format === 'pdf') {
                    // IMAGE → PDF
                    $imagick = new \Imagick();
                    $imagick->readImage($originalPath);
                    $imagick->setImageFormat('pdf');
                    $tempFile = sys_get_temp_dir() . '\\converted_' . uniqid() . '.pdf';
                    $imagick->writeImage($tempFile);
                    $imagick->clear();
                    $imagick->destroy();
                    return $this->file($tempFile, 'converted.pdf');
                } else {
                    // IMAGE → IMAGE
                    $image = null;
                    if ($mime === 'image/jpeg') $image = imagecreatefromjpeg($originalPath);
                    if ($mime === 'image/png') $image = imagecreatefrompng($originalPath);
                    if ($mime === 'image/webp') $image = imagecreatefromwebp($originalPath);

                    if (!$image) return new Response("Erreur lecture image", 400);

                    $tempFile = sys_get_temp_dir() . '\\converted_' . uniqid() . '.' . $format;

                    switch ($format) {
                        case 'png': imagepng($image, $tempFile); break;
                        case 'jpg': imagejpeg($image, $tempFile); break;
                        case 'webp': imagewebp($image, $tempFile); break;
                        default: return new Response("Format image invalide", 400);
                    }

                    imagedestroy($image);
                    return $this->file($tempFile, 'converted.' . $format);
                }
            }

            // ------------------------
            // PDF → IMAGE
            // ------------------------
            if (in_array($mime, $pdfMimes)) {

                if (!in_array($format, ['png','jpg','webp'])) {
                    return new Response("Format image invalide pour PDF", 400);
                }

                $imagick = new \Imagick();
                $imagick->readImage($originalPath);
                $imagick->setImageFormat($format);

                $tempFile = sys_get_temp_dir() . '\\converted_' . uniqid() . '.' . $format;
                $imagick->writeImage($tempFile);
                $imagick->clear();
                $imagick->destroy();

                return $this->file($tempFile, 'converted.' . $format);
            }

            // ------------------------
            // WORD → PDF
            // ------------------------
            if (in_array($mime, $wordMimes)) {

                if ($format !== 'pdf') {
                    return new Response("Seule conversion Word → PDF autorisée", 400);
                }

                $extension = $file->getClientOriginalExtension(); // doc ou docx
                $filename = uniqid() . '.' . $extension;
                $file->move(sys_get_temp_dir(), $filename);

                $inputPath = sys_get_temp_dir() . '\\' . $filename;
                $outputDir = sys_get_temp_dir();
                $soffice = "C:\\Program Files\\LibreOffice\\program\\soffice.exe";

                $cmd = sprintf(
                    '%s --headless --convert-to pdf --outdir %s %s 2>&1',
                    escapeshellarg($soffice),
                    escapeshellarg($outputDir),
                    escapeshellarg($inputPath)
                );

                exec($cmd, $output, $returnVar);

                if ($returnVar !== 0) {
                    return new Response("Erreur conversion Word:\n" . implode("\n", $output), 500);
                }

                $outputFile = $outputDir . '\\' . pathinfo($filename, PATHINFO_FILENAME) . '.pdf';
                if (!file_exists($outputFile)) {
                    return new Response("Fichier PDF non généré", 500);
                }

                return $this->file($outputFile, 'converted.pdf');
            }

            // ------------------------
            // VIDEO → VIDEO
            // ------------------------
            if (in_array($mime, $videoMimes)) {

                if (!in_array($format, ['mp4', 'avi', 'mkv', 'webm'])) {
                    return new Response("Format vidéo invalide", 400);
                }

                $extension = $file->getClientOriginalExtension();
                $filename = uniqid() . '.' . $extension;
                $file->move(sys_get_temp_dir(), $filename);

                $inputPath = sys_get_temp_dir() . '\\' . $filename;
                $outputFile = sys_get_temp_dir() . '\\converted_' . uniqid() . '.' . $format;

                $ffmpeg = "C:\\ffmpeg\\bin\\ffmpeg.exe";

                $cmd = sprintf(
                    '%s -i %s -c:v libx264 -c:a aac %s 2>&1',
                    escapeshellarg($ffmpeg),
                    escapeshellarg($inputPath),
                    escapeshellarg($outputFile)
                );

                exec($cmd, $output, $returnVar);

                if ($returnVar !== 0 || !file_exists($outputFile)) {
                    return new Response("Erreur conversion vidéo:\n" . implode("\n", $output), 500);
                }

                return $this->file($outputFile, 'video.' . $format);
            }

            return new Response("Type de fichier non supporté", 400);
        }

        return $this->render('convert/index.html.twig');
    }
}