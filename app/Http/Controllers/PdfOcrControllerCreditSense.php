<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\BankStatement;
use App\Models\Category;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;

class PdfOcrControllerCreditSense extends Controller
{
    // This property will hold our categories (for ML/keyword matching) loaded from the database.
    private $categories;

    public function process(Request $request)
    {
        // For ML/keyword matching, load full category records.
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
                $page->setImageFormat('png');
                $tempImagePath = $tempDir . "/temp_page_{$i}.png";
                $page->writeImage($tempImagePath);
                // Run Tesseract OCR (using --psm 6 for structured extraction).
                $pageText = shell_exec("tesseract " . escapeshellarg($tempImagePath) . " stdout --psm 6");
                $extractedText .= $pageText . "\n";
            } finally {
                if (file_exists($tempImagePath)) {
                    unlink($tempImagePath);
                }
            }
        }
        $imagick->destroy();

        // --- Parse Header Information (Credit Sense style) ---
        $statements = [];
        // Split by statement sections using "Statement:" delimiter.
        $sections = explode("Statement:", $extractedText);
        array_shift($sections); // Remove any text before the first statement

        foreach ($sections as $section) {
            // Extract account information from the Overview line.
            // Example: "Overview: Prime Access Account - (30737905)"
            if (preg_match('/Overview:\s*(.*?)\s*-\s*\((\d+)\)/i', $section, $accountMatches)) {
                $accountName = trim($accountMatches[1]);
                $accountNumber = trim($accountMatches[2]);
            } else {
                $accountName = 'Unknown Account';
                $accountNumber = 'Unknown';
            }

            // Extract bank name and owner details.
            if (preg_match('/Bank:\s*(.+)/i', $section, $bankMatches)) {
                $bankName = trim($bankMatches[1]);
            } else {
                $bankName = 'Unknown Bank';
            }
            if (preg_match('/Owner:\s*(.+)/i', $section, $ownerMatches)) {
                $accountOwner = trim($ownerMatches[1]);
            } else {
                $accountOwner = 'Unknown Owner';
            }

            // Extract available and current balances.
            preg_match('/Available Balance:\s*\$?\s*([\d,\.]+)/i', $section, $availBalanceMatches);
            $availableBalance = floatval(str_replace(',', '', $availBalanceMatches[1] ?? 0));
            preg_match('/Current Balance:\s*\$?\s*([\d,\.]+)/i', $section, $currentBalanceMatches);
            $currentBalance = floatval(str_replace(',', '', $currentBalanceMatches[1] ?? 0));

            // Create the Bank Statement record.
            $statement = BankStatement::create([
                'bank_name'       => $bankName,
                'account_holder'  => $accountOwner,
                'account_number'  => $accountNumber,
                'account_type'    => $accountName,
                'closing_balance' => $currentBalance,
            ]);

            // --- Extract Transactions ---
            // We'll split the section into lines.
            $lines = explode("\n", $section);
            // Define regex to capture:
            //   Group 1: Date (dd/mm/yy or dd/mm/yyyy)
            //   Group 2: Description (rest of the line)
            //   Group 3: Transaction Amount
            //   Group 4: New Balance
            $transactionRegex = '/^(\d{2}\/\d{2}\/\d{2,4})\s+(.*?)\s+\$?\s*([\d,\.]+)\s+\$?\s*([\d,\.]+)/i';
            // Define arrays for fallback type determination.
            $creditCategories = ['Other income', 'Salary'];
            $debitCategories  = ['ATM/EFTPOS', 'Other Outgoings', 'Repayments'];

            foreach ($lines as $line) {
                $line = trim($line);
                if (preg_match($transactionRegex, $line, $matches)) {
                    $dateToken   = $matches[1]; // e.g., "04/03/25"
                    $description = trim($matches[2]);
                    // Originally extracted category from regex (group 3) is ignored in favor of ML matching.
                    $amount      = floatval(str_replace([',', '$'], '', $matches[3]));
                    $balanceVal  = floatval(str_replace([',', '$'], '', $matches[4]));
                    $formattedDate = date('Y-m-d', strtotime($dateToken));

                    // Determine transaction type.
                    // For Internal Transfers, check description for 'From:' or 'To:'
                    if (stripos($description, 'Internal Transfers') !== false) {
                        if (stripos($description, 'From:') !== false) {
                            $type = 'CR';
                        } elseif (stripos($description, 'To:') !== false) {
                            $type = 'DR';
                        } else {
                            $type = 'DR';
                        }
                    } else {
                        // Fallback: if the extracted (or assumed) category is in creditCategories, type is CR,
                        // else if in debitCategories, type is DR. Since we no longer extract a static category,
                        // default to CR if amount seems to be incoming based on context.
                        $type = 'CR';
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

                    // Determine debit/credit amounts based on type.
                    if ($type === 'CR') {
                        $credit = $amount;
                        $debit = 0.0;
                    } else {
                        $debit = $amount;
                        $credit = 0.0;
                    }

                    // Create the transaction record using vtableRecords relationship.
                    $statement->vtableRecords()->create([
                        'transaction_date' => $formattedDate,
                        'description'      => $description,
                        'category'         => $category,
                        'debit'            => $debit,
                        'credit'           => $credit,
                        'balance'          => $balanceVal,
                        'type'             => $type,
                    ]);
                }
            }
            $statements[] = $statement;
        }

        return response()->json([
            'message' => 'Credit Sense PDF processed successfully',
            'statements' => $statements,
            'raw_text' => $extractedText,
        ]);
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
                if (preg_match('/\\b' . preg_quote(strtolower($keyword), '/') . '\\b/', $description)) {
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
