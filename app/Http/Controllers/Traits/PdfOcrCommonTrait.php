<?php
namespace App\Http\Controllers\Traits;

use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;
use App\Models\TrainData;

trait PdfOcrCommonTrait
{
    /**
     * Calls the ML service to get a predicted category.
     *
     * @param string $description
     * @param string $type
     * @return string|null
     */
    protected function getMlCategory($description, $type)
    {
        try {
            $client = new Client(['timeout' => 5]);
            $response = $client->post('http://ml:5000/predict', [
                'json' => [
                    'text' => $description,
                    'type' => $type,
                ]
            ]);
            $result = json_decode($response->getBody(), true);
            if (isset($result['category']) && !empty($result['category'])) {
                // Handle possible differences in confidence key names.
                $confidence = isset($result['confidence'])
                    ? (float)$result['confidence']
                    : (isset($result['category_confidence']) ? (float)$result['category_confidence'] : 1.0);
                if ($confidence >= 0.1) {
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
     * Uses keyword matching to determine a category.
     *
     * @param string $description
     * @param string $type
     * @param array  $categories
     * @return string|null
     */
    protected function keywordMatchCategory($description, $type, array $categories = [])
    {
        $description = strtolower($description);
        foreach ($categories as $cat) {
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
     * Determines the final category using both ML and keyword matching.
     *
     * @param string $description
     * @param string $type
     * @param array  $categories
     * @return string
     */
    protected function determineCategory($description, $type, array $categories)
    {
        $mlCategory = $this->getMlCategory($description, $type);
        $keywordCategory = $this->keywordMatchCategory($description, $type, $categories);

        if ($mlCategory !== null && $mlCategory !== "Uncertain") {
            return $mlCategory;
        } elseif ($keywordCategory !== null) {
            // Optionally train ML with the keyword category.
            $this->trainMlCategory($description, $type, $keywordCategory);
            return $keywordCategory;
        }
        return 'Uncertain';
    }

    /**
     * Stores training data into the train_data table.
     *
     * @param string $description
     * @param string $type
     * @param string $category
     */
    protected function storeTrainingData($description, $type, $category)
    {
        TrainData::create([
            'description' => $description,
            'type'        => $type,
            'category'    => $category,
        ]);
    }

    /**
     * Trains the ML service with the provided category.
     *
     * @param string $description
     * @param string $type
     * @param string $category
     */
    protected function trainMlCategory($description, $type, $category)
    {
        // Store the training example.
        $this->storeTrainingData($description, $type, $category);

        try {
            $client = new Client(['timeout' => 5]);
            $client->post('http://ml:5000/train', [
                'json' => [
                    'text'     => $description,
                    'type'     => $type,
                    'category' => $category,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error("ML training error: " . $e->getMessage());
        }
    }

    /**
     * Automatically determines the "uncertain" flag based solely on the provided transaction data.
     *
     * The logic is as follows:
     *   - First, determine the final category using determineCategory().
     *   - If the final category is not "Uncertain", then return 0.
     *   - If the final category is "Uncertain", then:
     *       - If ML returns a confident category, return 1.
     *       - Else if keyword matching returns a category, return 2.
     *       - Otherwise, return 0.
     *
     * @param array $transactionData  The transaction data array (should include 'description' and 'type').
     * @param array $categories       Array of category definitions.
     * @return int
     */
    protected function autoDetermineUncertainFlag(array $transactionData, array $categories)
    {
        $description = $transactionData['description'] ?? '';
        $type = $transactionData['type'] ?? '';

        // First, get the final determined category.
        $finalCategory = $this->determineCategory($description, $type, $categories);

        // If a clear category (not "Uncertain") is determined, use flag 0.
        if ($finalCategory !== "Uncertain") {
            return 0;
        }

        // Otherwise (if finalCategory is "Uncertain"), try to differentiate:
        $mlCategory = $this->getMlCategory($description, $type);
        if ($mlCategory !== null && $mlCategory !== "Uncertain") {
            return 1;
        }

        $keywordCategory = $this->keywordMatchCategory($description, $type, $categories);
        if ($keywordCategory !== null) {
            return 2;
        }

        return 0;
    }

    /**
     * Saves transaction data into one or more tables based on the "uncertain" flag.
     *
     * Revised rules:
     *   - If $uncertain equals 0: Save into Transaction and store training data.
     *   - If $uncertain equals 1: Save into Transaction and VTable.
     *   - If $uncertain is greater than 1: Save into Transaction, VTable, and store training data.
     *
     * @param \Illuminate\Database\Eloquent\Model $bankStatement  The bank statement model instance.
     * @param array $data  Array of transaction data (must include keys: description, type, category, etc.).
     * @param int $uncertain  The flag that determines the saving strategy.
     * @return void
     */
    protected function saveTransactionData($bankStatement, array $data, $uncertain)
    {
        // Always save into Transaction (include uncertain_flag if your table has that column)
        $bankStatement->transactions()->create(array_merge($data, ['uncertain_flag' => $uncertain]));

        if ($uncertain === 0) {
            // If uncertain flag equals 0 → save training data.
            // $this->storeTrainingData($data['description'], $data['type'], $data['category']);
        } elseif ($uncertain === 1) {
            // If uncertain flag equals 1 → also save into VTable.
            $bankStatement->vtableRecords()->create($data);
        } elseif ($uncertain > 1) {
            // If uncertain flag is greater than 1 → save into VTable and store training data.
            $bankStatement->vtableRecords()->create($data);
            // $this->storeTrainingData($data['description'], $data['type'], $data['category']);
        }
    }
}
