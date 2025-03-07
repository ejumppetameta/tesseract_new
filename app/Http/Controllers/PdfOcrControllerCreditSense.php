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

        // Run OCR with Tesseract using --psm 6 for structured text extraction
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

        // --- Parse PDF content based on structured columns ---
        $statements = [];
        // Split by statement sections using "Statement:" delimiter
        $sections = explode("Statement:", $extractedText);
        array_shift($sections); // Remove any text before the first statement

        foreach ($sections as $section) {
            // Extract account information from the Overview line if available.
            // Example: "Overview: Prime Access Account - (30737905)"
            if (preg_match('/Overview:\s*(.*?)\s*-\s*\((\d+)\)/i', $section, $accountMatches)) {
                $accountName = trim($accountMatches[1]);
                $accountNumber = trim($accountMatches[2]);
            } else {
                $accountName = 'Unknown Account';
                $accountNumber = 'Unknown';
            }

            // Extract bank name and owner details
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

            // Extract available and current balances if available
            preg_match('/Available Balance:\s*\$?\s*([\d,\.]+)/i', $section, $availBalanceMatches);
            $availableBalance = floatval(str_replace(',', '', $availBalanceMatches[1] ?? 0));
            preg_match('/Current Balance:\s*\$?\s*([\d,\.]+)/i', $section, $currentBalanceMatches);
            $currentBalance = floatval(str_replace(',', '', $currentBalanceMatches[1] ?? 0));

            // Create the bank statement record (including owner information)
            $statement = BankStatement::create([
                'bank_name'       => $bankName,
                'account_holder'  => $accountOwner,
                'account_number'  => $accountNumber,
                'account_type'    => $accountName,
                'closing_balance' => $currentBalance,
            ]);

            // --- Extract Transactions ---
            // Regex to capture:
            //   Group 1: Date (dd/mm/yy or dd/mm/yyyy)
            //   Group 2: Description (up to the category)
            //   Group 3: Category (one of ATM/EFTPOS, Other Outgoings, Other income, Repayments, Salary, Internal Transfers)
            //   Group 4: Transaction Amount
            //   Group 5: New Balance
            $transactionRegex = '/^(\d{2}\/\d{2}\/\d{2,4})\s+(.*?)\s+(ATM\/EFTPOS|Other Outgoings|Other income|Repayments|Salary|Internal Transfers)\s+\$?\s*([\d,\.]+)\s+\$?\s*([\d,\.]+)/i';
            $lines = explode("\n", $section);
            
            // Define category mappings for determining debit versus credit
            $creditCategories = ['Other income', 'Salary'];
            $debitCategories  = ['ATM/EFTPOS', 'Other Outgoings', 'Repayments'];

            foreach ($lines as $line) {
                $line = trim($line);
                if (preg_match($transactionRegex, $line, $matches)) {
                    $dateToken   = $matches[1]; // e.g., "04/03/25"
                    $description = trim($matches[2]);
                    $category    = trim($matches[3]);
                    $amount      = floatval(str_replace([',', '$'], '', $matches[4]));
                    $balanceVal  = floatval(str_replace([',', '$'], '', $matches[5]));
                    $formattedDate = date('Y-m-d', strtotime($dateToken));

                    // Determine if the amount is debit or credit
                    if ($category === 'Internal Transfers') {
                        // Check if the description contains 'From:' (credit) or 'To:' (debit)
                        if (stripos($description, 'From:') !== false) {
                            $debit = 0;
                            $credit = $amount;
                        } elseif (stripos($description, 'To:') !== false) {
                            $debit = $amount;
                            $credit = 0;
                        } else {
                            // Fallback if neither found
                            $debit = $amount;
                            $credit = 0;
                        }
                    } elseif (in_array($category, $creditCategories, true)) {
                        $debit = 0;
                        $credit = $amount;
                    } elseif (in_array($category, $debitCategories, true)) {
                        $debit = $amount;
                        $credit = 0;
                    } else {
                        // Fallback: default to debit if unknown category
                        $debit = $amount;
                        $credit = 0;
                    }
                    
                    $statement->transactions()->create([
                        'transaction_date' => $formattedDate,
                        'description'      => $description,
                        'category'         => $category,
                        'debit'            => $debit,
                        'credit'           => $credit,
                        'balance'          => $balanceVal,
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
}
