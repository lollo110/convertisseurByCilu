<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class ConvertController extends AbstractController
{
    private array $imageMimes = ['image/jpeg', 'image/png', 'image/webp'];
    private array $pdfMimes = ['application/pdf'];
    private array $wordMimes = [
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ];
    private array $videoMimes = [
        'video/mp4',
        'video/x-msvideo',
        'video/quicktime',
        'video/x-matroska',
        'video/webm'
    ];

    #[Route('/', name: 'app_home')]
    public function index(Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->render('convert/index.html.twig');
        }

        $file = $request->files->get('file');
        $format = $request->request->get('format');

        if (!$file) {
            return $this->error("Aucun fichier reçu");
        }

        if ($file->getSize() > 500 * 1024 * 1024) {
            return $this->error("Fichier trop volumineux (max 500MB)");
        }

        $mime = $file->getMimeType();
        $originalPath = $file->getPathname();

        try {
            if (in_array($mime, $this->imageMimes)) {
                return $this->handleImage($originalPath, $format, $mime);
            }

            if (in_array($mime, $this->pdfMimes)) {
                return $this->handlePdf($originalPath, $format);
            }

            if (in_array($mime, $this->wordMimes)) {
                return $this->handleWord($file, $format);
            }

            if (in_array($mime, $this->videoMimes)) {
                return $this->handleVideo($file, $format);
            }

            return $this->error("Type de fichier non supporté");

        } catch (\Throwable $e) {
            return $this->error("Erreur serveur: " . $e->getMessage(), 500);
        }
    }

    // ------------------------
    // IMAGE
    // ------------------------
    private function handleImage(string $path, string $format, string $mime): Response
    {
        if ($format === 'pdf') {
            $imagick = new \Imagick();
            $imagick->setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, 256);
            $imagick->readImage($path);
            $imagick->setImageFormat('pdf');

            $file = $this->tempFile('pdf');
            $imagick->writeImage($file);
            $imagick->clear();

            return $this->download($file, 'pdf');
        }

        $image = match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($path),
            'image/png' => imagecreatefrompng($path),
            'image/webp' => imagecreatefromwebp($path),
            default => null
        };

        if (!$image) {
            return $this->error("Erreur lecture image");
        }

        $file = $this->tempFile($format);

        match ($format) {
            'png' => imagepng($image, $file),
            'jpg' => imagejpeg($image, $file),
            'webp' => imagewebp($image, $file),
            default => throw new \Exception("Format image invalide")
        };

        imagedestroy($image);

        return $this->download($file, $format);
    }

    // ------------------------
    // PDF
    // ------------------------
    private function handlePdf(string $path, string $format): Response
    {
        if (!in_array($format, ['png','jpg','webp'])) {
            return $this->error("Format invalide pour PDF");
        }

        $imagick = new \Imagick();
        $imagick->readImage($path);
        $imagick->setImageFormat($format);

        $file = $this->tempFile($format);
        $imagick->writeImage($file);
        $imagick->clear();

        return $this->download($file, $format);
    }

    // ------------------------
    // WORD
    // ------------------------
    private function handleWord($file, string $format): Response
    {
        if ($format !== 'pdf') {
            return $this->error("Word → PDF uniquement");
        }

        $filename = uniqid() . '.' . $file->getClientOriginalExtension();
        $file->move(sys_get_temp_dir(), $filename);

        $input = sys_get_temp_dir() . '/' . $filename;
        $outputDir = sys_get_temp_dir();
        $soffice = $_ENV['LIBREOFFICE_PATH'];

        $cmd = sprintf(
            '%s --headless --convert-to pdf --outdir %s %s 2>&1',
            escapeshellarg($soffice),
            escapeshellarg($outputDir),
            escapeshellarg($input)
        );

        exec($cmd, $output, $code);

        if ($code !== 0) {
            return $this->error("Erreur conversion Word");
        }

        $outputFile = $outputDir . '/' . pathinfo($filename, PATHINFO_FILENAME) . '.pdf';

        return $this->download($outputFile, 'pdf');
    }

    // ------------------------
    // VIDEO
    // ------------------------
    private function handleVideo($file, string $format): Response
    {
        if (!in_array($format, ['mp4', 'avi', 'mkv', 'webm'])) {
            return $this->error("Format vidéo invalide");
        }

        set_time_limit(300);

        $filename = uniqid() . '.' . $file->getClientOriginalExtension();
        $file->move(sys_get_temp_dir(), $filename);

        $input = sys_get_temp_dir() . '/' . $filename;
        $output = $this->tempFile($format);

        $ffmpeg = $_ENV['FFMPEG_PATH'];

        $cmd = sprintf(
            '%s -i %s -c:v libx264 -c:a aac %s 2>&1',
            escapeshellarg($ffmpeg),
            escapeshellarg($input),
            escapeshellarg($output)
        );

        exec($cmd, $log, $code);

        if ($code !== 0 || !file_exists($output)) {
            return $this->error("Erreur conversion vidéo");
        }

        return $this->download($output, $format);
    }

    // ------------------------
    // HELPERS
    // ------------------------
    private function tempFile(string $ext): string
    {
        return sys_get_temp_dir() . '/' . uniqid('conv_') . '.' . $ext;
    }

    private function download(string $path, string $ext): Response
    {
        $response = $this->file($path, 'converted_' . time() . '.' . $ext);

        register_shutdown_function(function () use ($path) {
            if (file_exists($path)) {
                unlink($path);
            }
        });

        return $response;
    }

    private function error(string $message, int $code = 400): Response
    {
        return new Response($message, $code);
    }
}