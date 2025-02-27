<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\BankStatement;
use App\Models\Transaction;

class PdfOcrController extends Controller
{
    public function process(Request $request)
    {
        // Remove time limit for long processing
        set_time_limit(0);

        // Validate uploaded PDF (max 20MB)
        $request->validate([
            'pdf' => 'required|mimes:pdf|max:20480',
        ]);

        // Store PDF in storage/app/pdfs
        $pdfPath = $request->file('pdf')->store('pdfs');
        $fullPdfPath = storage_path('app' . DIRECTORY_SEPARATOR . $pdfPath);

        if (!file_exists($fullPdfPath)) {
            return response()->json(['error' => "File not found at path: $fullPdfPath"], 500);
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
            $tempImagePath = $tempDir . DIRECTORY_SEPARATOR . "temp_page_{$i}.png";
            $page->writeImage($tempImagePath);
            $command = escapeshellcmd("tesseract " . escapeshellarg($tempImagePath) . " stdout");
            $pageText = shell_exec($command);
            $extractedText .= $pageText . "\n";
            if (file_exists($tempImagePath)) {
                unlink($tempImagePath);
            }
        }
        $imagick->clear();
        $imagick->destroy();

        // --- PARSING THE OCR OUTPUT ---

        // Extract Bank Name. If not found, use fallback if text indicates "PUBLIC ISLAMIC".
        preg_match('/Bank Name\s*[:\-]?\s*(.+)/i', $extractedText, $bankNameMatches);
        $bankName = trim($bankNameMatches[1] ?? '');
        if (empty($bankName) && stripos($extractedText, 'PUBLIC ISLAMIC') !== false) {
            $bankName = "Public Islamic Bank Berhad";
        }

        // Extract Account Holder.
        preg_match('/Account Holder\s*[:\-]?\s*(.+)/i', $extractedText, $accountHolderMatches);
        $accountHolder = trim($accountHolderMatches[1] ?? '');
        // Fallback: look for uppercase text preceding "PENYATA AKAUN"
        if (empty($accountHolder)) {
            preg_match('/([A-Z\s]+)\s+PENYATA AKAUN/i', $extractedText, $accountHolderMatches);
            $accountHolder = trim($accountHolderMatches[1] ?? '');
        }

        // Extract Account Number (matches either "Nombor Akaun" or "Account Number")
        preg_match('/(?:Nombor Akaun|Account Number)\s*[:\-]?\s*([\d]+)/i', $extractedText, $accountNumberMatches);
        $accountNumber = trim($accountNumberMatches[1] ?? '');

        // Extract Account Type (matches either "Jenis Akaun" or "Account Type")
        preg_match('/(?:Jenis Akaun|Account Type)\s*[:\-]?\s*(.+)/i', $extractedText, $accountTypeMatches);
        $accountType = trim($accountTypeMatches[1] ?? '');
        $accountType = ltrim($accountType, '/ ');

        // Extract Statement Date (matches either "Tarikh Penyata" or "Statement Date")
        preg_match('/(?:Tarikh Penyata|Statement Date)\s*[:\-]?\s*([\d]{1,2}\s+\w+\s+\d{4})/i', $extractedText, $statementDateMatches);
        $statementDate = isset($statementDateMatches[1]) ? date('Y-m-d', strtotime($statementDateMatches[1])) : null;

        // Extract Closing Balance
        preg_match('/Closing Balance\s*[:\-]?\s*([\d,]+\.\d{2})/i', $extractedText, $closingBalanceMatches);
        $closingBalance = isset($closingBalanceMatches[1]) ? floatval(str_replace(',', '', $closingBalanceMatches[1])) : null;

        // Save the main statement data.
        $statement = BankStatement::create([
            'bank_name'        => $bankName,
            'account_holder'   => $accountHolder,
            'account_number'   => $accountNumber,
            'account_type'     => $accountType,
            'statement_date'   => $statementDate,
            'closing_balance'  => $closingBalance,
        ]);

        // --- Extract Transaction Lines ---
        $lines = explode("\n", $extractedText);
        $transactionGroups = [];
        $currentGroup = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (empty($trimmed)) {
                continue;
            }
            // If line starts with a date (e.g., "12/01"), start a new group.
            if (preg_match('/^\d{1,2}\/\d{1,2}/', $trimmed)) {
                if (!empty($currentGroup)) {
                    $transactionGroups[] = implode(' ', $currentGroup);
                    $currentGroup = [];
                }
            }
            $currentGroup[] = $trimmed;
        }
        if (!empty($currentGroup)) {
            $transactionGroups[] = implode(' ', $currentGroup);
        }

        // Regular expression to capture transaction parts
        $transactionRegex = '/^(\d{1,2}\/\d{1,2})\s+(.+?)(?=\s+[\d,]+(?:\.\d+)?\b)\s+([\d,]+(?:\.\d+)?)[\s]+([\d,]+(?:\.\d+)?)[\s]+([\d,]+(?:\.\d+)?)/';

        $transactions = [];
        foreach ($transactionGroups as $group) {
            // Skip headers or summary lines
            if (
                stripos($group, 'Balance From Last Statement') !== false ||
                stripos($group, 'Balance B/F') !== false ||
                stripos($group, 'PENYATA ini dicetak') !== false
            ) {
                continue;
            }
            
            $txData = [];
            if (preg_match($transactionRegex, $group, $matches)) {
                $txDate = $matches[1]; // e.g. "12/01"
                // Use statement date's year (if available) or current year
                $year = $statementDate ? date('Y', strtotime($statementDate)) : date('Y');
                $txFullDate = date('Y-m-d', strtotime($txDate . '/' . $year));
                $description = trim($matches[2]);
                $debit = floatval(str_replace(',', '', $matches[3]));
                $credit = floatval(str_replace(',', '', $matches[4]));
                $balance = floatval(str_replace(',', '', $matches[5]));

                $txData = [
                    'transaction_date' => $txFullDate,
                    'description'      => $description,
                    'debit'            => $debit,
                    'credit'           => $credit,
                    'balance'          => $balance,
                ];
            } else {
                // Fallback: if regex fails but a date is present, record the whole group as description.
                if (preg_match('/^(\d{1,2}\/\d{1,2})/', $group, $dateMatch)) {
                    $txDate = $dateMatch[1];
                    $year = $statementDate ? date('Y', strtotime($statementDate)) : date('Y');
                    $txFullDate = date('Y-m-d', strtotime($txDate . '/' . $year));
                    $txData = [
                        'transaction_date' => $txFullDate,
                        'description'      => $group,
                        'debit'            => 0,
                        'credit'           => 0,
                        'balance'          => 0,
                    ];
                }
            }
            
            // --- Extract additional details from the same group ---
            // Extract QR reference number (if available)
            $referenceNumber = null;
            if (preg_match('/QR REF NO:\s*([\d]+)/i', $group, $refMatches)) {
                $referenceNumber = trim($refMatches[1]);
            }
            
            // Extract DR MYDEBIT code (if available)
            $drMyDebit = null;
            if (preg_match('/DR MYDEBIT\s+(\d+)/i', $group, $drMatches)) {
                $drMyDebit = trim($drMatches[1]);
            }
            
            // Add these extra details into the transaction data array.
            $txData['reference_number'] = $referenceNumber;
            $txData['dr_mydebit'] = $drMyDebit;
            
            $transactions[] = $txData;
        }

        // Save transactions linked to this statement.
        foreach ($transactions as $txData) {
            $statement->transactions()->create($txData);
        }

        return response()->json([
            'message' => 'PDF processed and details saved successfully',
            'data' => [
                'bank_statement' => $statement,
                'transactions'   => $transactions,
            ],
            'raw_text' => $extractedText,
        ]);
    }
}
