<?php

namespace App\Http\Controllers;

use App\Models\Site;
use Illuminate\Http\Request;
// use Illuminate\Support\Facades\Auth;
// use Illuminate\Validation\ValidationException;
// use Illuminate\Support\Facades\Log; // Để ghi log lỗi
// use Illuminate\Support\Facades\Http; // Để gửi HTTP requests đến Google Sheets API
// use Carbon\Carbon; // Để làm việc với thời gian
// use App\Service\SiteService;
// use App\Service\SetupDefaultService;
use App\Service\SiteService;
use Illuminate\Support\Facades\DB;

class SiteController extends Controller
{

    public function __construct(SiteService $siteService) 
    {
        $this->siteService = $siteService;
    }

    public function index(Request $request)
    {
        $origin = request()->header('Origin');
        $domain = 'domain2f.microgem.io.vn';
        if ($origin) {
            $parsed = parse_url($origin);
            $domain = $parsed['host'] ?? null;
        }
        if($domain == 'localhost') {
            $domain = 'domain2f.microgem.io.vn';
        }
        if(empty($domain)) {
            return response()->json([
                'message' => 'error',
                'data' => 'ko co domain',
            ]);
        }
        $data = $this->siteService->getDataIndex($domain);

        return response()->json([
            'status' => true,
            'data' => $data
        ]);
        
    }

    public function productSearch(Request $request)
    {
        $origin = request()->header('Origin');
        $domain = 'domain2f.microgem.io.vn';
        if ($origin) {
            $parsed = parse_url($origin);
            $domain = $parsed['host'] ?? null;
        }
        if(empty($domain)) {
            return response()->json([
                'message' => 'error',
                'data' => 'ko co domain',
            ]);
        }
        if($domain == 'localhost') {
            $domain = 'domain2f.microgem.io.vn';
        }
        $search = $request->get('search');
        $data = $this->siteService->getProductSearch($domain, $request->all());
        return response()->json([
            'status' => true,
            'data' => $data
        ]);
    }

    public function productDetail(Request $request)
    {
        $origin = request()->header('Origin');
        if ($origin) {
            $parsed = parse_url($origin);
            $domain = $parsed['host'] ?? null;
        }
        if($domain == 'localhost') {
            $domain = 'domain2f.microgem.io.vn';
        }
        if(empty($domain)) {
            return response()->json([
                'message' => 'error',
                'data' => 'ko co domain',
            ]);
        }
        $data = $this->siteService->getProductDetail($domain, $request->all());
        return response()->json([
            'status' => true,
            'data' => $data
        ]);
    }

    public function pageDetail(Request $request)
    {
        $origin = request()->header('Origin');
        $domain = 'domain2f.microgem.io.vn';
        if ($origin) {
            $parsed = parse_url($origin);
            $domain = $parsed['host'] ?? null;
        }
        if($domain == 'localhost') {
            $domain = 'domain2f.microgem.io.vn';
        }
        if(empty($domain)) {
            return response()->json([
                'message' => 'error',
                'data' => 'ko co domain',
            ]);
        }
        $data = $this->siteService->getPageDetail($domain, $request->all());
        return response()->json([
            'status' => true,
            'data' => $data
        ]);
    }

}
