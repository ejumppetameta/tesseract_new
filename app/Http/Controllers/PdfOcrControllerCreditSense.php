<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\BankStatement;
use App\Models\Transaction;

class PdfOcrControllerCreditSense extends Controller
{
    public function process(Request $request)
    {
        set_time_limit(0);
        $request->validate([
            'pdf' => 'required|mimes:pdf|max:20480',
        ]);

        // Store PDF in storage/app/pdfs
        $pdfPath = $request->file('pdf')->store('pdfs');
        $fullPdfPath = storage_path('app' . DIRECTORY_SEPARATOR . $pdfPath);

        if (!file_exists($fullPdfPath)) {
            return response()->json(['error' => "File not found"], 500);
        }

        // Convert PDF pages to images using Imagick
        try {
            $imagick = new \Imagick();
            $imagick->setResolution(300, 300);
            $imagick->readImage($fullPdfPath);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error reading PDF: ' . $e->getMessage()], 500);
        }

        // Run OCR with Tesseract and accumulate text
        $extractedText = "";
        foreach ($imagick as $i => $page) {
            $page->setImageFormat('png');
            $tempDir = storage_path('app' . DIRECTORY_SEPARATOR . 'temp_images');
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0777, true);
            }
            $tempImagePath = $tempDir . "/temp_page_{$i}.png";
            $page->writeImage($tempImagePath);
            $pageText = shell_exec("tesseract " . escapeshellarg($tempImagePath) . " stdout");
            $extractedText .= $pageText . "\n";
            unlink($tempImagePath);
        }
        $imagick->destroy();

        // --- Parse Credit Sense PDF ---
        $statements = [];
        
        // Split text into account sections using "Overview:" as a delimiter.
        $accountSections = explode("Overview:", $extractedText);
        array_shift($accountSections); // Remove text before the first account

        foreach ($accountSections as $section) {
            // Extract Account Details
            preg_match('/^(.*?)\s*-\s*\((.*?)\)/m', $section, $accountMatches);
            $accountName = trim($accountMatches[1] ?? '');
            $accountNumber = trim($accountMatches[2] ?? '');
            
            // Extract Bank Name and Owner
            preg_match('/Bank:\s*(.*?)\s+Owner:/', $section, $bankMatches);
            $bankName = trim($bankMatches[1] ?? 'Unknown Bank');
            preg_match('/Owner:\s*(.*?)\s+Account BSB:/', $section, $ownerMatches);
            $accountHolder = trim($ownerMatches[1] ?? '');

            // Extract Balances
            preg_match('/Available Balance:\s*\$?\s*([\d,\.]+)/', $section, $availBalanceMatches);
            preg_match('/Current Balance:\s*\$?\s*([\d,\.]+)/', $section, $currentBalanceMatches);
            $availableBalance = floatval(str_replace(',', '', $availBalanceMatches[1] ?? 0));
            $currentBalance = floatval(str_replace(',', '', $currentBalanceMatches[1] ?? 0));

            // Create Bank Statement
            $statement = BankStatement::create([
                'bank_name'       => $bankName,
                'account_holder'  => $accountHolder,
                'account_number'  => $accountNumber,
                'account_type'    => $accountName,
                'closing_balance' => $currentBalance,
            ]);

            // --- Extract Transactions ---
            // Match transaction header rows: date and header text (before numeric fields)
            preg_match_all('/^(\d{2}\/\d{2}\/\d{2})\s+(.*?)(?=\s+\$?\s*[\d,\.]+\s+\$?\s*[\d,\.]+\s+\$?\s*[\d,\.]+)/m', $section, $txHeaderMatches, PREG_SET_ORDER);
            // Match numeric fields: debit, credit, balance at end of line.
            preg_match_all('/(\$?\s*[\d,\.]+)\s+(\$?\s*[\d,\.]+)\s+(\$?\s*[\d,\.]+)$/m', $section, $numMatches, PREG_SET_ORDER);

            $transactions = [];
            $count = min(count($txHeaderMatches), count($numMatches));

            // Define known category keywords
            $knownCategories = ['ATM/EFTPOS', 'Internal Transfers', 'Repayments', 'Salary', 'Other'];

            for ($i = 0; $i < $count; $i++) {
                $date = $txHeaderMatches[$i][1];
                $headerText = trim($txHeaderMatches[$i][2]);

                // Log header text for debugging
                \Log::info("Header Text: " . $headerText);

                // Normalize header text to remove extra spaces
                $normalizedHeader = preg_replace('/\s+/', ' ', $headerText);

                $foundCategory = null;
                // Check each known category
                foreach ($knownCategories as $keyword) {
                    if (stripos($normalizedHeader, $keyword) !== false) {
                        $foundCategory = $keyword;
                        // Remove the keyword (case-insensitive) from header text for description
                        $normalizedHeader = str_ireplace($keyword, '', $normalizedHeader);
                        break;
                    }
                }
                $category = $foundCategory ?? "Uncategorized";
                $description = trim($normalizedHeader);

                // Safety check for numeric matches
                if (!isset($numMatches[$i])) continue;

                // Process numeric values
                $debit = floatval(str_replace(['$', ','], '', $numMatches[$i][1]));
                $credit = floatval(str_replace(['$', ','], '', $numMatches[$i][2]));
                $balance = floatval(str_replace(['$', ','], '', $numMatches[$i][3]));

                // Convert date format (assume dd/mm/yy; prefix with '20' for year)
                $dateParts = explode('/', $date);
                if (count($dateParts) == 3) {
                    $date = '20' . $dateParts[2] . '-' . $dateParts[1] . '-' . $dateParts[0];
                }

                $transactions[] = [
                    'transaction_date' => $date,
                    'description'      => $description,
                    'category'         => $category,
                    'debit'            => $debit,
                    'credit'           => $credit,
                    'balance'          => $balance,
                ];
            }

            // Save transactions
            foreach ($transactions as $tx) {
                $statement->transactions()->create($tx);
            }

            $statements[] = $statement;
        }

        return response()->json([
            'message' => 'Credit Sense PDF processed successfully',
            'statements' => $statements,
            'raw_text' => $extractedText,
        ]);
    }
}
