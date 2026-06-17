<?php
/**
 * Basit ve temiz görsel yükleme sistemi
 * Dosya validasyonu, yüzde bazlı ölçekleme ve kaydetme
 */

if (!defined('ALLOWED_ACCESS')) {
    die('Doğrudan erişim yasaktır.');
}

function get_image_extension_from_mime(string $mime): ?string
{
    return match (strtolower($mime)) {
        'image/jpeg', 'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        default => null,
    };
}

function ensure_images_directory(): bool
{
    $uploadDir = __DIR__ . '/../assets/images';
    if (is_dir($uploadDir)) {
        return true;
    }
    return mkdir($uploadDir, 0755, true);
}

function resize_image_resource($source, int $width, int $height)
{
    $result = imagecreatetruecolor($width, $height);
    $mime = getImageMimeType($source);
    if (in_array($mime, ['image/png', 'image/gif', 'image/webp'], true)) {
        imagealphablending($result, false);
        imagesavealpha($result, true);
        $transparent = imagecolorallocatealpha($result, 0, 0, 0, 127);
        imagefilledrectangle($result, 0, 0, $width, $height, $transparent);
    }
    imagecopyresampled($result, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));
    return $result;
}

function getImageMimeType($resource): ?string
{
    if (!is_resource($resource)) {
        return null;
    }

    ob_start();
    imagepng($resource);
    $data = ob_get_clean();
    if ($data === false) {
        return null;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_buffer($finfo, $data);
    finfo_close($finfo);

    return $mime;
}

function output_image_buffer($image, string $ext, int $quality = 85)
{
    ob_start();
    switch ($ext) {
        case 'jpg':
            imagejpeg($image, null, $quality);
            break;
        case 'png':
            imagepng($image, null, 7);
            break;
        case 'gif':
            imagegif($image);
            break;
        case 'webp':
            imagewebp($image, null, $quality);
            break;
        default:
            ob_end_clean();
            return false;
    }
    return ob_get_clean();
}

function normalize_dimensions(int $width, int $height, int $maxDimension): array
{
    if ($width <= $maxDimension && $height <= $maxDimension) {
        return [$width, $height];
    }

    if ($width >= $height) {
        $newWidth = $maxDimension;
        $newHeight = max(1, (int)round($height * ($maxDimension / $width)));
    } else {
        $newHeight = $maxDimension;
        $newWidth = max(1, (int)round($width * ($maxDimension / $height)));
    }

    return [$newWidth, $newHeight];
}

function optimize_image_resource($image, string $ext, int $targetBytes = 5 * 1024 * 1024, int $maxDimension = 3000)
{
    $width = imagesx($image);
    $height = imagesy($image);
    [$targetWidth, $targetHeight] = normalize_dimensions($width, $height, $maxDimension);

    $bestData = null;
    $bestSize = PHP_INT_MAX;
    $initialQuality = in_array($ext, ['jpg', 'webp'], true) ? 85 : 7;

    for ($scale = 1.0; $scale >= 0.25; $scale -= 0.05) {
        $scaledWidth = max(1, (int)round($targetWidth * $scale));
        $scaledHeight = max(1, (int)round($targetHeight * $scale));
        $resized = resize_image_resource($image, $scaledWidth, $scaledHeight);
        $data = output_image_buffer($resized, $ext, $initialQuality);
        imagedestroy($resized);

        if ($data === false) {
            continue;
        }

        $size = strlen($data);
        if ($size < $bestSize) {
            $bestSize = $size;
            $bestData = $data;
        }

        if ($size <= $targetBytes) {
            return $data;
        }
    }

    if ($bestData === null) {
        return false;
    }

    if (in_array($ext, ['jpg', 'webp'], true)) {
        $quality = 80;
        while ($quality >= 40) {
            $resized = resize_image_resource($image, $targetWidth, $targetHeight);
            $data = output_image_buffer($resized, $ext, $quality);
            imagedestroy($resized);
            if ($data === false) {
                $quality -= 10;
                continue;
            }
            if (strlen($data) <= $targetBytes) {
                return $data;
            }
            if (strlen($data) < $bestSize) {
                $bestSize = strlen($data);
                $bestData = $data;
            }
            $quality -= 10;
        }
    }

    return $bestData;
}

function save_image_bytes_to_assets(string $data, int $event_id, string $ext): array
{
    if (!ensure_images_directory()) {
        return ['success' => false, 'error' => 'Görsel yükleme klasörü oluşturulamadı.'];
    }

    $filename = 'event_' . $event_id . '_' . uniqid() . '.' . $ext;
    $filepath = __DIR__ . '/../assets/images/' . $filename;
    if (file_put_contents($filepath, $data) === false) {
        return ['success' => false, 'error' => 'Görsel sunucuya kaydedilemedi.'];
    }

    return ['success' => true, 'path' => 'assets/images/' . $filename];
}

function handle_image_upload($file, $event_id)
{
    if (!isset($file['error'])) {
        return ['success' => false, 'error' => 'Dosya yüklenişi hatalı.'];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'Dosya PHP sınırını aştı (php.ini upload_max_filesize).',
            UPLOAD_ERR_FORM_SIZE => 'Dosya form sınırını aştı.',
            UPLOAD_ERR_PARTIAL => 'Dosya kısmen yüklendi.',
            UPLOAD_ERR_NO_FILE => 'Dosya seçilmedi.',
        ];
        return ['success' => false, 'error' => $errors[$file['error']] ?? 'Dosya yükleme hatası.'];
    }

    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['success' => false, 'error' => 'Geçersiz yüklenen dosya.'];
    }

    $file_size = $file['size'];
    $max_size = 25 * 1024 * 1024; // 25 MB, Apache ayarlarıyla uyumlu
    if ($file_size > $max_size) {
        return ['success' => false, 'error' => 'Dosya çok büyük (maks: 25 MB).'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($mime_type, $allowed_mimes, true)) {
        return ['success' => false, 'error' => 'Geçersiz dosya türü. JPG, PNG, GIF veya WebP kullanın.'];
    }

    $image_data = file_get_contents($file['tmp_name']);
    if ($image_data === false) {
        return ['success' => false, 'error' => 'Dosya okunamadı.'];
    }

    $image = @imagecreatefromstring($image_data);
    if (!$image) {
        return ['success' => false, 'error' => 'Geçersiz görsel dosyası.'];
    }

    $ext = get_image_extension_from_mime($mime_type) ?? 'jpg';
    $optimized = optimize_image_resource($image, $ext);
    imagedestroy($image);

    if ($optimized === false) {
        return ['success' => false, 'error' => 'Görsel işlenemedi.'];
    }

    return save_image_bytes_to_assets($optimized, $event_id, $ext);
}

function handle_remote_image($url, $event_id)
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return ['success' => false, 'error' => 'Geçersiz URL.'];
    }

    $parsed = parse_url($url);
    $scheme = strtolower($parsed['scheme'] ?? '');
    if (!in_array($scheme, ['http', 'https'], true)) {
        return ['success' => false, 'error' => 'Sadece HTTP/HTTPS URL ler desteklenir.'];
    }

    $context = stream_context_create([
        'http' => ['timeout' => 10, 'user_agent' => 'Mozilla/5.0'],
        'https' => ['timeout' => 10, 'user_agent' => 'Mozilla/5.0'],
    ]);
    $image_data = @file_get_contents($url, false, $context);
    if ($image_data === false || strlen($image_data) === 0) {
        return ['success' => false, 'error' => 'Görsel indirilemiyor.'];
    }

    if (strlen($image_data) > 20 * 1024 * 1024) {
        return ['success' => false, 'error' => 'İndirilen görsel çok büyük (maks: 20 MB).'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_buffer($finfo, $image_data);
    finfo_close($finfo);

    $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($mime_type, $allowed_mimes, true)) {
        return ['success' => false, 'error' => 'Geçersiz görsel türü.'];
    }

    $image = @imagecreatefromstring($image_data);
    if (!$image) {
        return ['success' => false, 'error' => 'Görsel işlenemiyor.'];
    }

    $ext = get_image_extension_from_mime($mime_type) ?? 'jpg';
    $optimized = optimize_image_resource($image, $ext);
    imagedestroy($image);

    if ($optimized === false) {
        return ['success' => false, 'error' => 'Görsel işlenemedi.'];
    }

    return save_image_bytes_to_assets($optimized, $event_id, $ext);
}
