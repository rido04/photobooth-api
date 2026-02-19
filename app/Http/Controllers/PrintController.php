<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use TCPDF;

class PrintController extends Controller
{
    // CUPS Printer Configuration
    private $printerName = 'Canon_G1030_series_USB';  // Nama printer CUPS (sesuai lpstat)
    
    public function print(Request $request)
    {
        Log::info("=== PRINT REQUEST RECEIVED ===");
        Log::info("Request IP: " . $request->ip());
        Log::info("Content-Type: " . $request->header('Content-Type'));
        Log::info("Image length: " . strlen($request->input('image', '')));    
    
        $request->validate([
            'image' => 'required|string',
            'copies' => 'integer|min:1|max:5'
        ]);

        try {
            $imageBase64 = $request->image;
            
            // Strip data URI scheme
            if (preg_match('/^data:image\/(\w+);base64,/', $imageBase64)) {
                $imageBase64 = substr($imageBase64, strpos($imageBase64, ',') + 1);
            }
            
            // Decode base64
            $imageData = base64_decode($imageBase64);
            
            if (!$imageData || strlen($imageData) < 100) {
                return response()->json(['success' => false, 'message' => 'Invalid image data'], 400);
            }
            
            // Create image resource
            $image = @imagecreatefromstring($imageData);
            
            if (!$image) {
                return response()->json(['success' => false, 'message' => 'Invalid image format'], 400);
            }
            
            $width = imagesx($image);
            $height = imagesy($image);
            
            Log::info("Original image: {$width}x{$height}");
            
            // Resize if too large
            $maxWidth = 1500;
            if ($width > $maxWidth) {
                $newWidth = $maxWidth;
                $newHeight = (int)(($maxWidth / $width) * $height);
                
                $resized = imagecreatetruecolor($newWidth, $newHeight);
                $white = imagecolorallocate($resized, 255, 255, 255);
                imagefill($resized, 0, 0, $white);
                imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                imagedestroy($image);
                $image = $resized;
                
                Log::info("Resized to: {$newWidth}x{$newHeight}");
            }
            
            // Generate filenames
            $timestamp = time();
            $random = Str::random(8);
            $jpgFilename = "print_{$timestamp}_{$random}.jpg";
            $pdfFilename = "print_{$timestamp}_{$random}.pdf";
            
            $jpgPath = storage_path('app/prints/' . $jpgFilename);
            $pdfPath = storage_path('app/prints/' . $pdfFilename);
            
            // Create directory
            if (!file_exists(storage_path('app/prints'))) {
                mkdir(storage_path('app/prints'), 0755, true);
            }
            
            // Save JPEG
            imagejpeg($image, $jpgPath, 95);
            $jpgWidth = imagesx($image);
            $jpgHeight = imagesy($image);
            imagedestroy($image);
            
            if (!file_exists($jpgPath)) {
                return response()->json(['success' => false, 'message' => 'Failed to save JPEG'], 500);
            }
            
            $jpgSize = filesize($jpgPath);
            Log::info("Saved JPEG: {$jpgFilename}, size: {$jpgSize} bytes");
            
            // Create PDF using TCPDF
            //$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
            //$pdf = new TCPDF('P', 'mm', 'A6', true, 'UTF-8', false);
            $pdf = new TCPDF('P', 'mm', [101.6, 152.4], true, 'UTF-8', false);
            
            $pdf->SetCreator('Photobooth App');
            $pdf->SetTitle('Photo Print');
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            $pdf->SetMargins(5, 5, 5);
            $pdf->SetAutoPageBreak(false, 0);
            $pdf->AddPage();
            
            // Calculate image dimensions to fit A4
            //$pageWidth = 200;
            //$pageHeight = 287;
            //$pageWidth = 95;
            //$pageHeight = 134;
            $pageWidth = 91;
            $pageHeight = 142;
            
            $imageRatio = $jpgWidth / $jpgHeight;
            $pageRatio = $pageWidth / $pageHeight;
            
            if ($imageRatio > $pageRatio) {
                $imgWidth = $pageWidth;
                $imgHeight = $pageWidth / $imageRatio;
                $imgX = 5;
                $imgY = 5 + (($pageHeight - $imgHeight) / 2);
            } else {
                $imgHeight = $pageHeight;
                $imgWidth = $pageHeight * $imageRatio;
                $imgX = 5 + (($pageWidth - $imgWidth) / 2);
                $imgY = 5;
            }
            
            $pdf->Image($jpgPath, $imgX, $imgY, $imgWidth, $imgHeight, 'JPG', '', '', true, 300, '', false, false, 0, false, false, false);
            $pdf->Output($pdfPath, 'F');
            
            if (!file_exists($pdfPath)) {
                Log::error("PDF not created: {$pdfPath}");
                @unlink($jpgPath);
                return response()->json(['success' => false, 'message' => 'PDF creation failed'], 500);
            }
            
            $pdfSize = filesize($pdfPath);
            Log::info("PDF created: {$pdfFilename}, size: {$pdfSize} bytes");
            
            // Get copies
            $copies = $request->input('copies', 1);
            
            // CUPS Print Command (SIMPLE!)
            $printCommand = sprintf(
                '/usr/bin/lp -d %s -n %d -o media=Custom.4x6in -o fit-to-page %s 2>&1',
                //'/usr/bin/lp -d %s -n %d -o media=A6 -o fit-to-page %s 2>&1',
                //'/usr/bin/lp -d %s -n %d -o media=A4 -o fit-to-page %s 2>&1',
                escapeshellarg($this->printerName),
                (int)$copies,
                escapeshellarg($pdfPath)
            );
            
            Log::info("CUPS Print command: {$printCommand}");
            
            // Execute print
            exec($printCommand, $printOutput, $printReturnCode);
            
            $printOutputStr = implode("\n", $printOutput);
            Log::info("Print output: {$printOutputStr}");
            Log::info("Return code: {$printReturnCode}");
            
            // Cleanup PDF after 5 seconds
            sleep(30);
            @unlink($pdfPath);
            
            return response()->json([
                'success' => $printReturnCode === 0,
                'message' => $printReturnCode === 0 ? 'Print job sent successfully' : 'Print command executed with errors',
                'output' => $printOutputStr,
                'jpg_file' => $jpgFilename,
                'jpg_size' => $jpgSize,
                'pdf_size' => $pdfSize,
                'copies' => $copies,
                'return_code' => $printReturnCode,
                'printer' => $this->printerName
            ]);
            
        } catch (\Exception $e) {
            Log::error("Print error: " . $e->getMessage());
            Log::error($e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'message' => 'Print failed: ' . $e->getMessage()
            ], 500);
        }
    }
    
    public function status()
    {
        try {
            // Get printer status
            $statusCommand = sprintf('/usr/bin/lpstat -p %s 2>&1', escapeshellarg($this->printerName));
            $status = shell_exec($statusCommand);
            
            // Get queue
            $queueCommand = sprintf('/usr/bin/lpq -P %s 2>&1', escapeshellarg($this->printerName));
            $queue = shell_exec($queueCommand);
            
            return response()->json([
                'printer' => $this->printerName,
                'status' => $status,
                'queue' => $queue,
                'timestamp' => now()->toDateTimeString()
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Status check failed: ' . $e->getMessage()
            ], 500);
        }
    }
}
