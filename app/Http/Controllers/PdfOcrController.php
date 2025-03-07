<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\BankStatement;
use App\Models\Transaction;
use Illuminate\Support\Facades\Log;

class PdfOcrController extends Controller
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

        // Run OCR with Tesseract (using --psm 6 for block/text layout)
        $extractedText = "";
        foreach ($imagick as $i => $page) {
            $page->setImageFormat('png');
            $tempDir = storage_path('app' . DIRECTORY_SEPARATOR . 'temp_images');
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0777, true);
            }
            $tempImagePath = $tempDir . "/temp_page_{$i}.png";
            $page->writeImage($tempImagePath);
            $pageText = shell_exec("tesseract " . escapeshellarg($tempImagePath) . " stdout --psm 6");
            $extractedText .= $pageText . "\n";
            unlink($tempImagePath);
        }
        $imagick->destroy();

        // --- Parse Header Information ---
        $accountNumber = 'Unknown';
        $accountType = 'Unknown';
        $statementDate = null;

        if (preg_match('/Nombor Akaun\s*\/\s*Account Number\s*(\d+)/i', $extractedText, $match)) {
            $accountNumber = trim($match[1]);
        }
        if (preg_match('/Jenis Akaun\s*\/\s*Account Type\s*(.+)/i', $extractedText, $match)) {
            $accountType = trim($match[1]);
        }
        if (preg_match('/Tarikh Penyata\s*\/\s*Statement Date\s*(.+)/i', $extractedText, $match)) {
            $rawStatementDate = trim($match[1]);
            // Convert to Y-m-d format (e.g., "11 Feb 2025" becomes "2025-02-11")
            $statementDate = date('Y-m-d', strtotime($rawStatementDate));
        }

        // Optionally extract the year from the statement date for transactions lacking a year.
        $statementYear = date('Y', strtotime($statementDate));

        // Create Bank Statement record
        $statementRecord = BankStatement::create([
            'bank_name'       => 'Public Islamic Bank', // You can extract branch info if needed
            'account_holder'  => null, // Public statements may not include an owner name
            'account_number'  => $accountNumber,
            'account_type'    => $accountType,
            'closing_balance' => 0, // Will update after processing transactions
            'statement_date'  => $statementDate,
        ]);

        // --- Parse Transaction Table ---
        $lines = explode("\n", $extractedText);
        $transactionLines = [];
        $startCollecting = false;
        foreach ($lines as $line) {
            $trimmedLine = trim($line);
            // Start collecting when we see the header row containing "DATE TRANSACTION" with numeric columns
            if (stripos($trimmedLine, 'DATE TRANSACTION') !== false &&
                (stripos($trimmedLine, 'DEBIT') !== false && stripos($trimmedLine, 'CREDIT') !== false && stripos($trimmedLine, 'BALANCE') !== false)
            ) {
                $startCollecting = true;
                continue;
            }
            if ($startCollecting) {
                // Skip empty lines or footer markers (like "Balance C/F")
                if ($trimmedLine === '' || stripos($trimmedLine, 'Balance C/F') !== false) {
                    continue;
                }
                $transactionLines[] = $trimmedLine;
            }
        }

        // Log the merged transaction lines array for debugging
        Log::info('Transaction Lines Extracted: ' . json_encode($transactionLines));

        // Merge lines where a transaction description spans multiple lines.
        $mergedLines = [];
        $currentLine = "";
        foreach ($transactionLines as $line) {
            if (preg_match('/^\d{1,2}\/\d{1,2}(?:\/\d{2,4})?/', $line)) {
                if ($currentLine !== "") {
                    $mergedLines[] = $currentLine;
                }
                $currentLine = $line;
            } else {
                $currentLine .= ' ' . $line;
            }
        }
        if ($currentLine !== "") {
            $mergedLines[] = $currentLine;
        }

        // Log merged lines for debugging
        Log::info('Merged Transaction Lines: ' . json_encode($mergedLines));

        // Process each merged transaction line
        foreach ($mergedLines as $line) {
            Log::info('Processing transaction line: ' . $line);

            // Remove extra spaces
            $line = preg_replace('/\s+/', ' ', $line);
            $tokens = explode(' ', $line);
            Log::info('Tokens: ' . json_encode($tokens));

            if (count($tokens) < 2) {
                Log::info('Skipping line due to insufficient tokens.');
                continue;
            }
            // Check if the line starts with a date
            if (!preg_match('/^\d{1,2}\/\d{1,2}(?:\/\d{2,4})?$/', $tokens[0])) {
                Log::info('Skipping line; first token is not a valid date: ' . $tokens[0]);
                continue;
            }
            $dateToken = array_shift($tokens);
            $numericTokens = array_filter($tokens, function ($t) {
                return preg_match('/^[\d,\.]+$/', str_replace(['$', ','], '', $t));
            });
            if (count($numericTokens) >= 3) {
                $balanceToken = array_pop($tokens);
                $creditToken  = array_pop($tokens);
                $debitToken   = array_pop($tokens);
                $description = implode(' ', $tokens);
            } else {
                $description = implode(' ', $tokens);
                $debitToken = '0';
                $creditToken = '0';
                if (preg_match('/([\d,\.]+)$/', $description, $numMatch)) {
                    $balanceToken = $numMatch[1];
                    $description = trim(str_replace($balanceToken, '', $description));
                } else {
                    $balanceToken = '0';
                }
            }
            if (!preg_match('/\/\d{2,4}$/', $dateToken)) {
                $dateToken .= '/' . $statementYear;
            }
            $formattedDate = date('Y-m-d', strtotime($dateToken));

            $debit = floatval(str_replace([',', '$'], '', $debitToken));
            $credit = floatval(str_replace([',', '$'], '', $creditToken));
            $balanceVal = floatval(str_replace([',', '$'], '', $balanceToken));

            // Validate that balance is within a reasonable range (e.g., less than 1 billion)
            if ($balanceVal > 1000000000) {
                Log::info('Skipping transaction due to out-of-range balance: ' . $balanceVal);
                continue;
            }

            // Truncate the description to 255 characters
            $description = substr($description, 0, 255);

            Log::info('Parsed Transaction Data', [
                'date' => $formattedDate,
                'description' => $description,
                'debit' => $debit,
                'credit' => $credit,
                'balance' => $balanceVal,
            ]);

            // Create the transaction record
            $statementRecord->transactions()->create([
                'transaction_date' => $formattedDate,
                'description'      => $description,
                'debit'            => $debit,
                'credit'           => $credit,
                'balance'          => $balanceVal,
            ]);
        }

        // Update the closing balance on the statement using the last transaction's balance
        $lastTransaction = $statementRecord->transactions()->orderBy('transaction_date', 'desc')->first();
        if ($lastTransaction) {
            $statementRecord->closing_balance = $lastTransaction->balance;
            $statementRecord->save();
        }

        return response()->json([
            'message'   => 'Public bank statement PDF processed successfully',
            'statement' => $statementRecord,
            'raw_text'  => $extractedText,
        ]);
    }
}
