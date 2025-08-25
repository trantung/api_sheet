<?php

namespace App\Service;

use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\Temp;
use Revolution\Google\Sheets\Facades\Sheets;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Google\Client;
use Google\Service\Docs;
use Carbon\Carbon;

class DatabaseDefaultService
{

    public function getDataFromSheet($request)
    {
        $tempId = $request['temp_id'];
        $temp = Temp::findOrFail($request['temp_id']);
        // dd($temp);
        try {
            // Extract spreadsheet ID from URL
            preg_match('/\/spreadsheets\/d\/([a-zA-Z0-9-_]+)/', $temp->google_sheet, $matches);
            if (!$matches) {
                return response()->json(['error' => 'Invalid Google Sheets URL'], 400);
            }

            $spreadsheetId = $matches[1];
            $apiKey = env('GOOGLE_SHEETS_API_KEY');
            // dd($apiKey);
            // Step 1: Get list of sheets
            $metadataResponse = Http::get("https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}", [
                'key' => $apiKey,
            ]);

            if (!$metadataResponse->successful()) {
                return response()->json(['error' => 'Failed to retrieve sheet metadata'], 400);
            }

            $sheetData = [];
            $sheets = $metadataResponse->json()['sheets'] ?? [];

            foreach ($sheets as $sheet) {
                $title = $sheet['properties']['title'];

                // Step 2: Get values from each sheet
                $valueResponse = Http::get("https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values/" . urlencode($title), [
                    'key' => $apiKey,
                ]);

                if ($valueResponse->successful()) {
                    $values = $valueResponse->json()['values'] ?? [];
                    $sheetData[$title] = $values;
                } else {
                    $sheetData[$title] = ['error' => 'Failed to retrieve data'];
                }
            }
            // dd($sheetData);
            return response()->json([
                'connected' => true,
                'spreadsheet_id' => $spreadsheetId,
                'data' => $sheetData
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error connecting to Google Sheets: ' . $e->getMessage()
            ], 500);
        }
    }

    public function importFromGoogleSheetByApiKey($request)
    {
        try {
            $apiKey = env('GOOGLE_SHEETS_API_KEY');
            $defaultSheetId = env('GOOGLE_SHEETS_ID_DEFAULT');
            $sheetId = $request['spreadsheet_id'];
            $domainSite = $request['full_domain'];
            $db_name = $request['db_name'];
            $metaUrl = "https://sheets.googleapis.com/v4/spreadsheets/{$sheetId}?key={$apiKey}";
            $metaResponse = json_decode(file_get_contents($metaUrl), true);

            if (!isset($metaResponse['sheets'])) {
                return response()->json([
                    'status' => false,
                    'message' => "Không lấy được danh sách sheet từ Google Sheets."
                ]);
            }

            foreach ($metaResponse['sheets'] as $sheetInfo) {
                $sheetName = $sheetInfo['properties']['title'];
                $dataUrl = "https://sheets.googleapis.com/v4/spreadsheets/{$sheetId}/values/" . urlencode($sheetName) . "?key={$apiKey}";
                $response = json_decode(file_get_contents($dataUrl), true);

                if (!isset($response['values']) || count($response['values']) < 2) {
                    continue; // Skip empty or invalid sheet
                }

                $rows = $response['values'];

                if ($sheetName === 'Information') {
                    $this->saveInformationSheet($rows, $db_name, $apiKey, $defaultSheetId);
                }

                if ($sheetName === 'Content') {
                    $this->saveContentSheet($rows, $db_name, $apiKey, $defaultSheetId);
                }
            }

            return response()->json([
                'status' => true,
                'message' => 'Import dữ liệu từ Google Sheets thành công.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Lỗi khi import từ Google Sheets: ' . $e->getMessage()
            ], 500);
        }
    }

    private function getFieldMapFromDefaultSheet($sheetId, $apiKey)
    {
        $fieldMap = [];
        // Gọi API để lấy danh sách sheet
        $sheetMetadataUrl = "https://sheets.googleapis.com/v4/spreadsheets/{$sheetId}?key={$apiKey}";
        $sheetMetadata = json_decode(file_get_contents($sheetMetadataUrl), true);

        if (!isset($sheetMetadata['sheets'])) return $fieldMap;

        foreach ($sheetMetadata['sheets'] as $sheetInfo) {
            $sheetName = $sheetInfo['properties']['title'];
            $dataUrl = "https://sheets.googleapis.com/v4/spreadsheets/{$sheetId}/values/" . urlencode($sheetName) . "?key={$apiKey}";

            $response = json_decode(file_get_contents($dataUrl), true);
            if (!isset($response['values']) || count($response['values']) < 2) continue;

            $rows = $response['values'];

            if (trim($rows[0][0]) === 'Property' && trim($rows[0][1]) === 'code') {
                foreach (array_slice($rows, 1) as $row) {
                    if (!isset($row[0]) || !isset($row[1])) continue;
                    $property = trim($row[0]);
                    $code = trim($row[1]);
                    $fieldMap[$property] = $code;
                }
            }
        }

        return $fieldMap;
    }

    private function getSlugCategory($array, $categoryNameField)
    {
        foreach($array as $key => $value) {
            if($value == $categoryNameField) {
                return Str::slug($value, '_');
            }
        }
        return false;
    }

    public function convertTextToProductField()
    {
        $res = [];
        $arrayFieldProduct = [
            0 => "Title",
            1 => "Slug",
            2 => "Excerpt",
            3 => "Thumbnail",
            4 => "Categories",
            5 => "Author",
            6 => "Content",
            7 => "Published Date",
            8 => "Status"
        ];

        foreach($arrayFieldProduct as $key => $value)
        {
            $k = Str::slug($value, '_');
            $res[$key] = $k; 
        }
        return $res;
    }

    // public function saveContentSheet($rows, $db_name, $apiKey, $defaultSheetId)
    // {
    //     $arrayCheck = [
    //         0 => "Title",
    //         1 => "Slug",
    //         2 => "Excerpt",
    //         3 => "Thumbnail",
    //         4 => "Categories",
    //         5 => "Author",
    //         6 => "Content",
    //         7 => "Published Date",
    //         8 => "Status"
    //     ];
    //     $checkContentSheetName = $this->checkColumnNameSheet($arrayCheck, $rows[0]);
        
    //     if(!$checkContentSheetName) {
    //         //todo: phải lấy default database về(lấy từ nội dung GOOGLE_SHEETS_ID_DEFAULT)
    //         return false;
    //     }
    //     $categorySlug = $this->getSlugCategory($rows[0], CATEGORY_SHEET_NAME);
    //     $productField = $this->convertTextToProductField();
    //     // dd($productField);
    //     unset($rows[0]);
    //     $res = [];
    //     foreach($rows as $key => $value) {
    //         foreach($value as $k => $v)
    //         {
    //             $keyRes = $productField[$k];
    //             $res[$key][$keyRes] = $v;
    //         }
    //     }
    //     $connectionName = setupTenantConnection($db_name);
    //     $this->saveProduct($connectionName, $res, $categorySlug);
    //     return true;
    // }

    public function saveContentSheet($rows, $db_name, $apiKey, $defaultSheetId)
    {
        $arrayCheck = [
            0 => "Title",
            1 => "Slug",
            2 => "Excerpt",
            3 => "Thumbnail",
            4 => "Categories",
            5 => "Author",
            6 => "Content",
            7 => "Published Date",
            8 => "Status"
        ];
        $checkContentSheetName = $this->checkColumnNameSheet($arrayCheck, $rows[0]);

        if(!$checkContentSheetName) {
            // TODO: Get data from default sheet when column validation fails
            $defaultSheetData = $this->getDefaultContentSheetData($defaultSheetId, $apiKey);
            if ($defaultSheetData) {
                $rows = $defaultSheetData;
            } else {
                return false;
            }
        }
        $categorySlug = $this->getSlugCategory($rows[0], CATEGORY_SHEET_NAME);
        $productField = $this->convertTextToProductField();
        
        unset($rows[0]);
        $res = [];
        foreach($rows as $key => $value) {
            foreach($value as $k => $v)
            {
                $keyRes = $productField[$k];
                $res[$key][$keyRes] = $v;
            }
        }
        $connectionName = setupTenantConnection($db_name);
        $this->saveProduct($connectionName, $res, $categorySlug);
        return true;
    }

    public function saveProduct($connectionName, $data, $categorySlug)
    {
        foreach($data as $value)
        {
            $categoryIds = $this->saveCategoryTable($connectionName, $value[$categorySlug]);
            // dd($categoryIds);
            unset($value[$categorySlug]);
            $productId = $this->saveProductTable($connectionName, $value);
            $this->saveProductCategoryTable($connectionName, $productId, $categoryIds);
        }
    }
    
    public function saveProductCategoryTable($connectionName, $productId, $categoryIds)
    {
        $res = [];
        foreach($categoryIds as $categoryId) {
            $res[] = DB::connection($connectionName)->table('product_category')->insertGetId([
                'product_id' => $productId,
                'category_id' => $categoryId,
            ]);
        }
        
        return $res;
    }

    public function getContentProduct($content)
    {
        if ($this->isGoogleDocUrl($content)) {
            if (preg_match('#/document/d/([a-zA-Z0-9_-]+)#', $content, $matches)) {
                $docId = $matches[1];
                return "https://docs.google.com/document/d/$docId";
            }
            return null;
        }

        return $content;
    }

    public function isGoogleDocUrl($url)
    {
        return preg_match('/^https:\/\/docs\.google\.com\/document\/d\/[a-zA-Z0-9_-]+/', $url);
    }

    public function getGoogleDocId($url)
    {
        preg_match('/document\/d\/([a-zA-Z0-9_-]+)/', $url, $matches);
        return $matches[1] ?? null;
    }

    public function getGoogleDocContentWithImages($documentId, $serviceAccountPath)
    {
        $client = new Client();
        $client->setAuthConfig($serviceAccountPath);
        $client->addScope([
            Docs::DOCUMENTS_READONLY,
            'https://www.googleapis.com/auth/drive.readonly'
        ]);

        $service = new Docs($client);
        $document = $service->documents->get($documentId);

        $body = $document->getBody()->getContent();
        $inlineObjects = $document->getInlineObjects();
        $html = '';
        $toc = '<div class="toc"><strong>Table of Contents</strong><ul>';

        $headingsMap = []; // Để lưu id cho heading

        // First pass: build TOC
        foreach ($body as $element) {
            if (!isset($element['paragraph'])) continue;
            $paragraph = $element['paragraph'];
            $style = $paragraph['paragraphStyle']['namedStyleType'] ?? null;

            if (in_array($style, ['HEADING_1', 'HEADING_2', 'HEADING_3', 'HEADING_4'])) {
                $text = '';
                foreach ($paragraph['elements'] as $e) {
                    $text .= trim($e['textRun']['content'] ?? '');
                }

                if ($text) {
                    $id = \Illuminate\Support\Str::slug($text);
                    $headingsMap[$text] = $id;
                    $toc .= "<li><a href=\"#$id\">$text</a></li>";
                }
            }
        }
        $toc .= '</ul></div>';
        $html .= $toc;

        // Second pass: render full HTML
        foreach ($body as $element) {
            if (!isset($element['paragraph'])) continue;

            $paragraph = $element['paragraph'];
            $elements = $paragraph['elements'] ?? [];
            $paragraphStyle = $paragraph['paragraphStyle'] ?? [];
            $headingTag = 'p';
            $text = '';

            if (isset($paragraphStyle['namedStyleType'])) {
                $style = $paragraphStyle['namedStyleType'];
                if ($style === 'HEADING_1') $headingTag = 'h1';
                elseif ($style === 'HEADING_2') $headingTag = 'h2';
                elseif ($style === 'HEADING_3') $headingTag = 'h3';
                elseif ($style === 'HEADING_4') $headingTag = 'h4';
            }

            // Collect all text first to generate id if it's a heading
            foreach ($elements as $elem) {
                if (isset($elem['textRun'])) {
                    $text .= trim($elem['textRun']['content'] ?? '');
                }
            }

            $id = null;
            if (isset($headingsMap[$text])) {
                $id = $headingsMap[$text];
                $html .= "<$headingTag id=\"$id\">";
            } else {
                $html .= "<$headingTag>";
            }

            foreach ($elements as $elem) {
                if (isset($elem['textRun'])) {
                    $content = htmlentities($elem['textRun']['content']);
                    $style = $elem['textRun']['textStyle'] ?? [];

                    // Format text
                    if (!empty($style)) {
                        if (!empty($style['bold'])) $content = '<strong>' . $content . '</strong>';
                        if (!empty($style['italic'])) $content = '<em>' . $content . '</em>';
                        if (!empty($style['underline'])) $content = '<u>' . $content . '</u>';
                        if (!empty($style['link']['url'])) {
                            $url = $style['link']['url'];
                            $content = '<a href="' . $url . '" target="_blank">' . $content . '</a>';
                        }
                        if (!empty($style['foregroundColor']['color']['rgbColor'])) {
                            $color = $style['foregroundColor']['color']['rgbColor'];
                            $hexColor = $this->rgbColorToHex($color);
                            $content = "<span style=\"color:$hexColor\">$content</span>";
                        }
                    }

                    $html .= nl2br($content);
                }

                // Image
                if (isset($elem['inlineObjectElement']['inlineObjectId'])) {
                    $inlineObjectId = $elem['inlineObjectElement']['inlineObjectId'];
                    if (isset($inlineObjects[$inlineObjectId])) {
                        $embeddedObj = $inlineObjects[$inlineObjectId]['inlineObjectProperties']['embeddedObject'];
                        if (isset($embeddedObj['imageProperties']['contentUri'])) {
                            $imgSrc = $embeddedObj['imageProperties']['contentUri'];
                            $html .= '<img src="' . $imgSrc . '" style="max-width:100%;margin:10px 0;" alt="Image">';
                        }
                    }
                }
            }

            $html .= "</$headingTag>\n";
        }

        return $html;
    }

    private function rgbColorToHex($rgbColor)
    {
        $r = isset($rgbColor['red']) ? intval($rgbColor['red'] * 255) : 0;
        $g = isset($rgbColor['green']) ? intval($rgbColor['green'] * 255) : 0;
        $b = isset($rgbColor['blue']) ? intval($rgbColor['blue'] * 255) : 0;

        return sprintf("#%02x%02x%02x", $r, $g, $b);
    }


    public function saveProductTable($connectionName, $data)
    {
        $productContentSlug = Str::slug(PRODUCT_CONTENT, '_');
        $productPublishedDateSlug = Str::slug(PRODUCT_PUBLISHED_DATE, '_');
        $content = $this->getContentProduct($data[$productContentSlug]);
        $data['content'] = $content;
        $data['published_date'] = Carbon::createFromFormat('m/d/Y', $data[$productPublishedDateSlug])->format('Y-m-d H:i:s');
        $data['created_at'] = Carbon::now();
        $productId = DB::connection($connectionName)->table('products')->insertGetId($data);
        return $productId;
    }

    public function getIdCategoryByName($connectionName, $categoryName)
    {
        $slug = Str::slug($categoryName, '_');
        $check = DB::connection($connectionName)->table('categories')->where('code', $slug)->first();
        if(!$check) {
            return DB::connection($connectionName)->table('categories')->insertGetId([
                'name' => $categoryName,
                'code' => $slug,
                'created_at' => Carbon::now(),
            ]);
        }
        return $check->id;
    }

    public function saveCategoryTable($connectionName, $stringCategories)
    {
        $res = [];
        $categoryNames = array_map('trim', explode(',', $stringCategories));
        foreach($categoryNames as $value)
        {
            $res[] = $this->getIdCategoryByName($connectionName, $value);
        }
        return $res;
    }

    // public function saveInformationSheet($rows, $db_name, $apiKey, $defaultSheetId)
    // {
    //     $arrayCheck = [
    //         0 => "Property",
    //         1 => "Value",
    //     ];
    //     $checkContentSheetName = $this->checkColumnNameSheet($arrayCheck, $rows[0]);
    //     if(!$checkContentSheetName) {
    //         //todo: phải lấy default database về(lấy từ nội dung GOOGLE_SHEETS_ID_DEFAULT)
    //         return false;
    //     }

    //     if (trim($rows[0][0]) === 'Property' && trim($rows[0][1]) === 'Value') {
    //         $fieldMap = $this->getFieldMapFromDefaultSheet($defaultSheetId, $apiKey);
    //         foreach (array_slice($rows, 1) as $row) {
    //             if (!isset($row[0]) || !isset($row[1])) continue;

    //             $property = trim($row[0]);
    //             $value = trim($row[1]);
    //             $code = $fieldMap[$property] ?? Str::slug($property, '_');
    //             // setupTenantConnection();
    //             $connectionName = setupTenantConnection($db_name);

    //             DB::connection($connectionName)->table('site_informations')->updateOrInsert(
    //                 ['code' => $code],
    //                 [
    //                     'property'   => $property,
    //                     'value'      => $value,
    //                     'updated_at' => Carbon::now(),
    //                     'created_at' => Carbon::now()
    //                 ]
    //             );
    //         }
    //     }

    //     return true;
    // }

    public function saveInformationSheet($rows, $db_name, $apiKey, $defaultSheetId)
    {
        $arrayCheck = [
            0 => "Property",
            1 => "Value",
        ];
        $checkColumnNameSheet = $this->checkColumnNameSheet($arrayCheck, $rows[0]);
        
        if(!$checkColumnNameSheet) {
            // TODO: Get data from default sheet when column validation fails
            $defaultSheetData = $this->getDefaultInformationSheetData($defaultSheetId, $apiKey);
            if ($defaultSheetData) {
                $rows = $defaultSheetData;
            } else {
                return false;
            }
        }

        if (trim($rows[0][0]) === 'Property' && trim($rows[0][1]) === 'Value') {
            $fieldMap = $this->getFieldMapFromDefaultSheet($defaultSheetId, $apiKey);
            foreach (array_slice($rows, 1) as $row) {
                if (!isset($row[0]) || !isset($row[1])) continue;

                $property = trim($row[0]);
                $value = trim($row[1]);
                $code = $fieldMap[$property] ?? Str::slug($property, '_');
                
                $connectionName = setupTenantConnection($db_name);

                DB::connection($connectionName)->table('site_informations')->updateOrInsert(
                    ['code' => $code],
                    [
                        'property'   => $property,
                        'value'      => $value,
                        'updated_at' => Carbon::now(),
                        'created_at' => Carbon::now()
                    ]
                );
            }
        }

        return true;
    }

    public function checkColumnNameSheet($arrayCheck, $arrayNeedCheck)
    {
        return empty(array_diff($arrayNeedCheck, $arrayCheck));
    }

    /**
     * Get default Information sheet data from default Google Sheet
     */
    private function getDefaultInformationSheetData($defaultSheetId, $apiKey)
    {
        try {
            $dataUrl = "https://sheets.googleapis.com/v4/spreadsheets/{$defaultSheetId}/values/" . urlencode('Information') . "?key={$apiKey}";
            $response = json_decode(file_get_contents($dataUrl), true);
            
            if (isset($response['values']) && count($response['values']) >= 2) {
                return $response['values'];
            }
            
            return false;
        } catch (\Exception $e) {
            Log::error('Error getting default Information sheet data: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get default Content sheet data from default Google Sheet
     */
    private function getDefaultContentSheetData($defaultSheetId, $apiKey)
    {
        try {
            $dataUrl = "https://sheets.googleapis.com/v4/spreadsheets/{$defaultSheetId}/values/" . urlencode('Content') . "?key={$apiKey}";
            $response = json_decode(file_get_contents($dataUrl), true);
            
            if (isset($response['values']) && count($response['values']) >= 2) {
                return $response['values'];
            }
            
            return false;
        } catch (\Exception $e) {
            Log::error('Error getting default Content sheet data: ' . $e->getMessage());
            return false;
        }
    }
}
