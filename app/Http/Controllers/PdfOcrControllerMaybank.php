<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\BankStatement;
use App\Models\Transaction;
use App\Models\Category;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;

class PdfOcrControllerMaybank extends Controller
{
    // Holds the categories mapping from the database.
    private $categories;

    public function process(Request $request)
    {
        // Load categories from the database as a mapping: category => keywords.
        $this->categories = Category::all()->mapWithKeys(function ($cat) {
            return [$cat->name => $cat->keywords];
        })->toArray();

        set_time_limit(0);
        $request->validate([
            'pdf' => 'required|mimes:pdf|max:20480',
        ]);

        // Store PDF in storage/app/pdfs.
        $pdfPath = $request->file('pdf')->store('pdfs');
        $fullPdfPath = storage_path('app' . DIRECTORY_SEPARATOR . $pdfPath);

        if (!file_exists($fullPdfPath)) {
            return response()->json(['error' => "File not found"], 500);
        }

        // Convert PDF pages to images using Imagick.
        try {
            $imagick = new \Imagick();
            // Increase resolution for better OCR accuracy.
            $imagick->setResolution(300, 300);
            $imagick->readImage($fullPdfPath);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error reading PDF: ' . $e->getMessage()], 500);
        }

        // Directory for temporary images.
        $tempDir = storage_path('app' . DIRECTORY_SEPARATOR . 'temp_images');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0777, true);
        }

        // Run OCR on each page with Tesseract after pre-processing the image.
        $extractedText = "";
        foreach ($imagick as $i => $page) {
            try {
                // Pre-process the image for better OCR results:
                // Convert to grayscale.
                $page->setImageColorspace(\Imagick::COLORSPACE_GRAY);
                // Enhance contrast and normalize the image.
                $page->contrastImage(1);
                $page->normalizeImage();
                $page->setImageFormat('png');

                $tempImagePath = $tempDir . "/temp_page_{$i}.png";
                $page->writeImage($tempImagePath);

                // Using Tesseract with english language and a suitable page segmentation mode.
                $tesseractCommand = "tesseract " . escapeshellarg($tempImagePath) . " stdout --psm 6 -l eng";
                $pageText = shell_exec($tesseractCommand);
                $extractedText .= $pageText . "\n";
            } catch (\Exception $e) {
                Log::error("OCR error on page {$i}: " . $e->getMessage());
            } finally {
                if (file_exists($tempImagePath)) {
                    unlink($tempImagePath);
                }
            }
        }
        $imagick->destroy();

        // --- Parse Header Information ---
        $accountNumber = 'Unknown';
        $statementDate = null;
        $accountHolder = 'Unknown';
        $accountType = 'Savings';

        // Extract account_holder: only capture contiguous uppercase letters (and spaces)
        if (preg_match('/TARIKH PENYATA\s*\n([A-Z\s]+)(?:\s|$)/m', $extractedText, $match)) {
            $accountHolder = trim($match[1]);
        }

        // Extract statement_date: capture dd/mm/yy after the account_holder text
        if (preg_match('/TARIKH PENYATA\s*\n[A-Z\s]+.*?(\d{2}\/\d{2}\/\d{2})/m', $extractedText, $match)) {
            $rawDate = trim($match[1]);
            $parts = explode('/', $rawDate);
            if (count($parts) == 3) {
                list($day, $month, $year) = $parts;
                if (strlen($year) == 2) {
                    $year = '20' . $year;
                }
                $statementDate = "$year-$month-$day";
            } else {
                $statementDate = date('Y-m-d', strtotime($rawDate));
            }
        }

        // Extract account_number: number preceding "\nNUMBER"
        if (preg_match('/(\d{6,}-\d{6,})\s*\nNUMBER/i', $extractedText, $match)) {
            $accountNumber = trim($match[1]);
        }

        // Extract account_type: text following the specific phrase, then remove trailing extra text.
        if (preg_match('/PROTECTED BY PIDM UP TO RM250,000 FOR EACH DEPOSITOR\s+([A-Z0-9\s\-]+)/i', $extractedText, $match)) {
            $rawAccountType = trim($match[1]);
            $accountTypeParts = explode("\n", $rawAccountType);
            $accountType = trim($accountTypeParts[0]);
        }

        // Create the Bank Statement record.
        $statementRecord = BankStatement::create([
            'bank_name'       => 'Maybank Berhad',
            'account_holder'  => $accountHolder,
            'account_number'  => $accountNumber,
            'account_type'    => $accountType,
            'closing_balance' => 0, // Will update after processing transactions.
            'statement_date'  => $statementDate,
        ]);

        // --- Parse Transaction Table ---
        $lines = explode("\n", $extractedText);
        $transactionLines = [];
        $startCollecting = false;
        foreach ($lines as $line) {
            $trimmedLine = trim($line);
            if (
                stripos($trimmedLine, 'ENTRY DATE') !== false &&
                stripos($trimmedLine, 'TRANSACTION DESCRIPTION') !== false &&
                stripos($trimmedLine, 'TRANSACTION AMOUNT') !== false &&
                stripos($trimmedLine, 'STATEMENT BALANCE') !== false
            ) {
                $startCollecting = true;
                continue;
            }
            if ($startCollecting) {
                if (
                    stripos($trimmedLine, 'ENDING BALANCE') !== false ||
                    stripos($trimmedLine, 'TOTAL CREDIT') !== false ||
                    stripos($trimmedLine, 'TOTAL DEBIT') !== false ||
                    stripos($trimmedLine, 'BEGINNING BALANCE') !== false
                ) {
                    continue;
                }
                if ($trimmedLine === '') continue;
                $transactionLines[] = $trimmedLine;
            }
        }
        Log::info('Maybank Transaction Lines Extracted: ' . json_encode($transactionLines));

        // Combine multi-line transaction rows.
        $transactionsCombined = [];
        $currentTransaction = "";
        foreach ($transactionLines as $line) {
            if (preg_match('/^\d{2}\/\d{2}\/\d{2}/', $line)) {
                if (!empty($currentTransaction)) {
                    $transactionsCombined[] = $currentTransaction;
                }
                $currentTransaction = $line;
            } else {
                $currentTransaction .= " " . $line;
            }
        }
        if (!empty($currentTransaction)) {
            $transactionsCombined[] = $currentTransaction;
        }

        // --- Process Each Transaction ---
        foreach ($transactionsCombined as $transLine) {
            $transLine = trim($transLine);
            if (empty($transLine)) {
                continue;
            }
            if (preg_match('/^(?<date>\d{2}\/\d{2}\/\d{2})\s*\|\s*(?<rest>.+)$/s', $transLine, $matches)) {
                $dateToken = $matches['date'];
                $remaining = $matches['rest'];
            } else {
                if (preg_match('/^(\d{2}\/\d{2}\/\d{2})/', $transLine, $dateMatch)) {
                    $dateToken = $dateMatch[1];
                    $remaining = trim(substr($transLine, strlen($dateToken)));
                } else {
                    Log::info("Skipping transaction with no date: " . $transLine);
                    continue;
                }
            }
            $parts = explode('/', $dateToken);
            if (count($parts) == 3) {
                list($day, $month, $year) = $parts;
                if (strlen($year) == 2) {
                    $year = '20' . $year;
                }
                $transactionDate = "$year-$month-$day";
            } else {
                $transactionDate = date('Y-m-d', strtotime($dateToken));
            }

            $descriptionFull = $remaining;
            if (preg_match('/(?P<amount>[\d,]+\.\d{2}[+-])\s+(?P<balance>[\d,]+\.\d{2})/', $remaining, $matchValues)) {
                $amountToken = $matchValues['amount'];
                $balanceToken = $matchValues['balance'];
                $description = trim(preg_replace('/' . preg_quote($matchValues[0], '/') . '/', '', $remaining, 1));
            } else {
                $tokens = preg_split('/\s+/', $remaining);
                if (count($tokens) < 3) {
                    continue;
                }
                $balanceToken = array_pop($tokens);
                $amountToken = array_pop($tokens);
                $description = implode(' ', $tokens);
            }

            $descTokens = explode(' ', $description);
            while (!empty($descTokens) && preg_match('/^[A-Z]{2,3}-?$/', end($descTokens))) {
                array_pop($descTokens);
            }
            $description = trim(implode(' ', $descTokens));

            $debit = 0.0;
            $credit = 0.0;
            $amountToken = trim($amountToken);
            if (strlen($amountToken) > 1) {
                $sign = substr($amountToken, -1);
                $amountNum = substr($amountToken, 0, -1);
                $amountVal = floatval(str_replace([','], '', $amountNum));
                if ($sign === '+') {
                    $credit = $amountVal;
                } elseif ($sign === '-') {
                    $debit = $amountVal;
                }
            }

            // Determine transaction type: CR for credit and DR for debit.
            $type = ($credit > 0) ? 'CR' : 'DR';

            // --- Determine the Category and Type Using AI/ML or Fallback ---
            $categoryTypeData = $this->determineCategoryAndType($description, $debit, $credit);
            $category = $categoryTypeData['category'];
            $type = $categoryTypeData['type'];

            Log::info('Parsed Maybank Transaction Data', [
                'date'        => $transactionDate,
                'description' => $description,
                'category'    => $category,
                'debit'       => $debit,
                'credit'      => $credit,
                'balance'     => floatval(str_replace([','], '', trim($balanceToken))),
                'type'        => $type,
            ]);

            // Create the transaction record.
            $statementRecord->transactions()->create([
                'transaction_date' => $transactionDate,
                'description'      => substr($description, 0, 100),
                'category'         => $category,
                'debit'            => $debit,
                'credit'           => $credit,
                'balance'          => floatval(str_replace([','], '', trim($balanceToken))),
                'type'             => $type,
            ]);
        }

        // --- Update Statement's Closing Balance ---
        if (preg_match('/ENDING BALANCE\s*:\s*([\d,]+\.\d{2})/i', $extractedText, $match)) {
            $closingBalance = floatval(str_replace([','], '', $match[1]));
            $statementRecord->closing_balance = $closingBalance;
            $statementRecord->save();
        } else {
            $lastTransactionRecord = $statementRecord->transactions()->orderBy('transaction_date', 'desc')->first();
            if ($lastTransactionRecord) {
                $statementRecord->closing_balance = $lastTransactionRecord->balance;
                $statementRecord->save();
            }
        }

        return response()->json([
            'message'   => 'Maybank statement PDF processed successfully',
            'statement' => $statementRecord,
            'raw_text'  => $extractedText,
        ]);
    }

    /**
     * Determines the category and type of a transaction based on its description.
     *
     * @param string $description
     * @param float $debit
     * @param float $credit
     * @return array
     */
    private function determineCategoryAndType($description, $debit, $credit)
    {
        $type = ($credit > 0) ? 'CR' : 'DR';

        // Attempt AI/ML prediction.
        try {
            $client = new Client(['timeout' => 5]);
            $response = $client->post('http://ml:5000/predict', [
                'json' => ['text' => $description]
            ]);
            $result = json_decode($response->getBody(), true);
            if (isset($result['category'])) {
                return ['category' => $result['category'], 'type' => $type];
            }
        } catch (\Exception $e) {
            Log::error('AI/ML service error: ' . $e->getMessage());
        }

        // Fallback: keyword matching.
        $descUpper = strtoupper($description);
        foreach ($this->categories as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($descUpper, strtoupper($keyword)) !== false) {
                    return ['category' => $category, 'type' => $type];
                }
            }
        }

        return ['category' => 'Others', 'type' => $type];
    }
}
