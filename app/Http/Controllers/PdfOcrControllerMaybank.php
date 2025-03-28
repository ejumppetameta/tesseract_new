<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\BankStatement;
use App\Models\Category;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;

class PdfOcrControllerMaybank extends Controller
{
    // This property holds our full category records from the database.
    private $categories;

    public function process(Request $request)
    {
        // Load full category records (including 'keywords' and 'type')
        $this->categories = Category::all()->toArray();

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

        // Create temporary images directory if needed.
        $tempDir = storage_path('app' . DIRECTORY_SEPARATOR . 'temp_images');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0777, true);
        }

        $extractedText = "";
        foreach ($imagick as $i => $page) {
            try {
                // Pre-process image: grayscale, contrast, normalize, set format to png.
                $page->setImageColorspace(\Imagick::COLORSPACE_GRAY);
                $page->contrastImage(1);
                $page->normalizeImage();
                $page->setImageFormat('png');

                $tempImagePath = $tempDir . "/temp_page_{$i}.png";
                $page->writeImage($tempImagePath);

                // Run Tesseract OCR with English language.
                $tesseractCommand = "tesseract " . escapeshellarg($tempImagePath) . " stdout --psm 6 -l eng";
                $pageText = shell_exec($tesseractCommand);
                $extractedText .= $pageText . "\n";
            } catch (\Exception $e) {
                Log::error("OCR error on page {$i}: " . $e->getMessage());
            } finally {
                if (isset($tempImagePath) && file_exists($tempImagePath)) {
                    unlink($tempImagePath);
                }
            }
        }
        $imagick->destroy();

        // --- Parse Header Information (Maybank version) ---
        // Note: The header parsing below is adjusted for Maybank PDFs.
        $accountHolder = 'Unknown';
        $accountNumber = 'Unknown';
        $accountType = 'Savings';
        $statementDate = null;

        // Extract account_holder: capture contiguous uppercase letters (and spaces) after "TARIKH PENYATA"
        if (preg_match('/TARIKH PENYATA\s*\n([A-Z\s]+)(?:\s|$)/m', $extractedText, $match)) {
            $accountHolder = trim($match[1]);
        }
        // Extract statement_date: capture dd/mm/yy after the account_holder text.
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
        // Extract account_type: text following a specific phrase.
        if (preg_match('/PROTECTED BY PIDM UP TO RM250,000 FOR EACH DEPOSITOR\s+([A-Z0-9\s\-]+)/i', $extractedText, $match)) {
            $rawAccountType = trim($match[1]);
            $accountTypeParts = explode("\n", $rawAccountType);
            $accountType = trim($accountTypeParts[0]);
        }

        // Create Bank Statement record.
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
                if ($trimmedLine === '') {
                    continue;
                }
                $transactionLines[] = $trimmedLine;
            }
        }
        Log::info('Transaction Lines Extracted: ' . json_encode($transactionLines));

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
        $lastTransactionDate = null;
        $lastTransaction = null;
        foreach ($transactionsCombined as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (preg_match('/^(\d{1,2}\/\d{1,2}(?:\/\d{2,4})?)/', $line, $dateMatch)) {
                $dateToken = $dateMatch[1];
                if (!preg_match('/\/\d{2,4}$/', $dateToken)) {
                    $dateToken .= '/' . date('Y', strtotime($statementDate));
                }
                $lastTransactionDate = date('Y-m-d', strtotime($dateToken));
                $descriptionPart = trim(substr($line, strlen($dateMatch[1])));
            } else {
                if ($lastTransactionDate === null) {
                    Log::info('Skipping line with no date and no previous date: ' . $line);
                    continue;
                }
                $descriptionPart = $line;
            }

            $tokens = explode(' ', $descriptionPart);
            $amounts = [];
            $descTokens = [];
            $amountRegex = '/^(\d{1,3}(?:,\d{3})*\.\d{2})([A-Za-z].*)?$/';

            foreach ($tokens as $token) {
                if (preg_match($amountRegex, $token, $matchAmt)) {
                    $amounts[] = $matchAmt[1];
                    if (isset($matchAmt[2]) && trim($matchAmt[2]) !== '') {
                        $descTokens[] = trim($matchAmt[2]);
                    }
                } else {
                    $descTokens[] = $token;
                }
            }

            $debit = 0.0;
            $credit = 0.0;
            $balanceVal = 0.0;
            if (count($amounts) == 1) {
                $balanceVal = floatval(str_replace([','], '', $amounts[0]));
            } elseif (count($amounts) >= 3) {
                $debit = floatval(str_replace([','], '', $amounts[0]));
                $credit = floatval(str_replace([','], '', $amounts[1]));
                $balanceVal = floatval(str_replace([','], '', end($amounts)));
            } elseif (count($amounts) == 2) {
                if (stripos($descriptionPart, 'CR') !== false) {
                    $credit = floatval(str_replace([','], '', $amounts[0]));
                } else {
                    $debit = floatval(str_replace([','], '', $amounts[0]));
                }
                $balanceVal = floatval(str_replace([','], '', $amounts[1]));
            }
            $description = substr(implode(' ', $descTokens), 0, 255);

            // --- Determine transaction type: CR for credit and DR for debit ---
            if ($credit > 0) {
                $type = 'CR';
            } elseif ($debit > 0) {
                $type = 'DR';
            } else {
                $type = 'DR';
            }

            // --- Determine the Category Using Both ML and Keyword Matching ---
            $mlCategory = $this->getMlCategory($description, $type);
            $keywordCategory = $this->keywordMatchCategory($description, $type);

            if ($mlCategory !== null && $mlCategory !== "Uncertain") {
                $finalCategory = $mlCategory;
            } elseif ($keywordCategory !== null) {
                $finalCategory = $keywordCategory;
                $this->trainMlCategory($description, $type, $keywordCategory);
            } else {
                $finalCategory = 'Uncertain';
            }
            $category = $finalCategory;

            if ($balanceVal > 1000000000) {
                Log::info('Skipping transaction due to out-of-range balance: ' . $balanceVal);
                continue;
            }

            Log::info('Parsed Transaction Data', [
                'date'        => $lastTransactionDate,
                'description' => $description,
                'category'    => $category,
                'debit'       => $debit,
                'credit'      => $credit,
                'balance'     => $balanceVal,
                'type'        => $type,
            ]);

            if ($debit != 0.0 || $credit != 0.0 || $balanceVal != 0.0) {
                $lastTransaction = $statementRecord->vtableRecords()->create([
                    'transaction_date' => $lastTransactionDate,
                    'description'      => $description,
                    'category'         => $category,
                    'debit'            => $debit,
                    'credit'           => $credit,
                    'balance'          => $balanceVal,
                    'type'             => $type,
                ]);
            } else {
                if ($lastTransaction) {
                    $mergedDescription = trim($lastTransaction->description . ' ' . $description);
                    $lastTransaction->update([
                        'description' => substr($mergedDescription, 0, 100),
                        'category'    => $this->determineCategory($mergedDescription, $type)
                    ]);
                }
            }
        }

        // --- Update Statement's Closing Balance ---
        if (preg_match('/Closing Balance(?: In This Statement)?\s+([\d,]+\.\d{2})/i', $extractedText, $match)) {
            $closingBalance = floatval(str_replace([','], '', $match[1]));
            $statementRecord->closing_balance = $closingBalance;
            $statementRecord->save();
        } else {
            $lastTransactionRecord = $statementRecord->vtableRecords()->orderBy('transaction_date', 'desc')->first();
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
     * Determines the category of a transaction using both ML prediction and keyword matching.
     *
     * @param string $description
     * @param string $type
     * @return string
     */
    private function determineCategory($description, $type)
    {
        $mlCategory = $this->getMlCategory($description, $type);
        $keywordCategory = $this->keywordMatchCategory($description, $type);

        if ($mlCategory !== null && $mlCategory !== "Uncertain") {
            return $mlCategory;
        } elseif ($keywordCategory !== null) {
            $this->trainMlCategory($description, $type, $keywordCategory);
            return $keywordCategory;
        }
        return 'Uncertain';
    }

    /**
     * Uses keyword matching to determine a category.
     *
     * @param string $description
     * @param string $type
     * @return string|null
     */
    private function keywordMatchCategory($description, $type)
    {
        $description = strtolower($description);
        foreach ($this->categories as $cat) {
            if (strcasecmp($cat['type'], $type) !== 0) {
                continue;
            }
            foreach ($cat['keywords'] as $keyword) {
                if (preg_match('/\b' . preg_quote(strtolower($keyword), '/') . '\b/', $description)) {
                    return $cat['name'];
                }
            }
        }
        return null;
    }

    /**
     * Calls the ML service to get a predicted category.
     *
     * @param string $description
     * @param string $type
     * @return string|null
     */
    private function getMlCategory($description, $type)
    {
        try {
            $client = new Client(['timeout' => 5]);
            $response = $client->post('http://ml:5000/predict', [
                'json' => [
                    'text' => $description,
                    'type' => $type
                ]
            ]);
            $result = json_decode($response->getBody(), true);
            if (isset($result['category']) && !empty($result['category'])) {
                $confidence = isset($result['confidence']) ? (float)$result['confidence'] : 1.0;
                if ($confidence >= 0.7) {
                    return $result['category'];
                }
                Log::info("ML prediction confidence too low ({$confidence}) for: {$description}");
            }
        } catch (\Exception $e) {
            Log::error("ML service error: " . $e->getMessage());
        }
        return null;
    }

    /**
     * Trains the ML service with the provided category.
     *
     * @param string $description
     * @param string $type
     * @param string $category
     */
    private function trainMlCategory($description, $type, $category)
    {
        try {
            $client = new Client(['timeout' => 5]);
            $client->post('http://ml:5000/train', [
                'json' => [
                    'text'     => $description,
                    'type'     => $type,
                    'category' => $category
                ]
            ]);
        } catch (\Exception $e) {
            Log::error("ML training error: " . $e->getMessage());
        }
    }
}
