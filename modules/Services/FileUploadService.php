<?php
/**
 * modules/Services/FileUploadService.php
 * 
 * Service layer for applicant photo and document file uploads.
 * Replaces handleApplicantFileUpload() from config/functions.php.
 * Photos: public/uploads/application/, Documents: public/uploads/documents/.
 */

class FileUploadService
{
    private string $projectRoot;

    public function __construct()
    {
        $this->projectRoot = dirname(dirname(__DIR__)); // project root level
    }

    /**
     * Handle applicant photo and document file upload
     */
    public function handleUpload(string $applicantId): array
    {
        $publicRoot = $this->projectRoot . '/public';
        $photoUploadDir    = $publicRoot . '/uploads/application/';
        $documentUploadDir = $publicRoot . '/uploads/documents/';

        foreach ([$photoUploadDir, $documentUploadDir] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        $applicantPhotoPath = '';
        $photoUploadError = '';

        $photoKey = isset($_FILES['applicant_photo']) ? 'applicant_photo' : 'photo';
        $hasPhotoUpload = isset($_FILES[$photoKey]) && isset($_FILES[$photoKey]['tmp_name']);

        if ($hasPhotoUpload) {
            $photoError = $_FILES[$photoKey]['error'] ?? UPLOAD_ERR_NO_FILE;

            if ($photoError === UPLOAD_ERR_NO_FILE) {
                $hasPhotoUpload = false;
            } elseif ($photoError !== UPLOAD_ERR_OK) {
                $errorMessages = [
                    UPLOAD_ERR_INI_SIZE   => 'ফাইলের আকার php.ini সীমা ছাড়িয়ে গেছে (upload_max_filesize)।',
                    UPLOAD_ERR_FORM_SIZE  => 'ফাইলের আকার ফর্মের MAX_FILE_SIZE সীমা ছাড়িয়ে গেছে।',
                    UPLOAD_ERR_PARTIAL    => 'ফাইলটি আংশিকভাবে আপলোড হয়েছে, অনুগ্রহ করে পুনরায় চেষ্টা করুন।',
                    UPLOAD_ERR_NO_TMP_DIR => 'সার্ভারে টেম্প ফোল্ডার পাওয়া যায়নি।',
                    UPLOAD_ERR_CANT_WRITE => 'সার্ভারে ফাইল লেখা সম্ভব হয়নি।',
                    UPLOAD_ERR_EXTENSION  => 'একটি PHP এক্সটেনশন আপলোড ব্লক করেছে।',
                ];
                $photoUploadError = $errorMessages[$photoError] ?? 'ছবি আপলোড করার সময় একটি অজানা ত্রুটি হয়েছে।';
                $hasPhotoUpload = false;
            } elseif (empty($_FILES[$photoKey]['tmp_name'])) {
                $hasPhotoUpload = false;
            }
        }

        if ($hasPhotoUpload && empty($photoUploadError)) {
            $detectedMime = null;
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $detectedMime = finfo_file($finfo, $_FILES[$photoKey]['tmp_name']);
                finfo_close($finfo);
            } elseif (function_exists('mime_content_type')) {
                $detectedMime = mime_content_type($_FILES[$photoKey]['tmp_name']);
            }

            if ($detectedMime !== null && strpos($detectedMime, 'image/') !== 0) {
                $photoUploadError = 'আপলোড করা ফাইলটি বৈধ ছবি নয় (সনাক্তকৃত MIME: ' . $detectedMime . ')। শুধুমাত্র ছবির ফাইল অনুমোদিত।';
            } else {
                $photoExtension = strtolower(pathinfo($_FILES[$photoKey]['name'], PATHINFO_EXTENSION));
                $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'tiff', 'tif', 'ico', 'avif', 'heic', 'heif', 'jfif'];

                if (!in_array($photoExtension, $allowedExtensions, true)) {
                    $photoUploadError = 'অননুমোদিত ছবির ফরম্যাট (বর্তমান: .' . $photoExtension . ').';
                } else {
                    $finalExtension = ($photoExtension === 'jfif') ? 'jpg' : $photoExtension;
                    $photoFilename = $applicantId . '.' . $finalExtension;
                    $fullPath = $photoUploadDir . $photoFilename;

                    if (move_uploaded_file($_FILES[$photoKey]['tmp_name'], $fullPath)) {
                        $applicantPhotoPath = '/uploads/application/' . $photoFilename;
                    } else {
                        $photoUploadError = 'ছবি সংরক্ষণ করতে ব্যর্থ হয়েছে। সার্ভার ত্রুটির জন্য পুনরায় চেষ্টা করুন।';
                    }
                }
            }
        }

        $documentsPath = [];

        if (!empty($_FILES['documents']['name'][0]) && !empty($_FILES['documents']['tmp_name'][0])) {
            $allowedDocExtensions = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'jfif'];

            foreach ($_FILES['documents']['name'] as $key => $fileName) {
                if (empty($fileName)) continue;
                $fileTmp = $_FILES['documents']['tmp_name'][$key] ?? '';
                if (empty($fileTmp)) continue;

                $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                if (in_array($fileExtension, $allowedDocExtensions, true)) {
                    $finalDocExtension = ($fileExtension === 'jfif') ? 'jpg' : $fileExtension;
                    $docFilename = $applicantId . '_doc' . ($key + 1) . '.' . $finalDocExtension;
                    $fullDocPath = $documentUploadDir . $docFilename;

                    if (move_uploaded_file($fileTmp, $fullDocPath)) {
                        $documentsPath[] = '/uploads/documents/' . $docFilename;
                    }
                }
            }
        }

        $combinedError = $photoUploadError;

        return [
            'success'        => empty($combinedError),
            'photo'          => $applicantPhotoPath,
            'error'          => $combinedError,
            'documents'      => $documentsPath,
            'documents_json' => json_encode($documentsPath, JSON_UNESCAPED_SLASHES),
        ];
    }
}
