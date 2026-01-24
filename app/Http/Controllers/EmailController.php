<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Service\SiteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmailController extends Controller
{
    public function __construct(SiteService $siteService)
    {
        $this->siteService = $siteService;
    }

    public function store(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'site' => 'nullable|string',
            'content' => 'nullable|string',
            'type' => 'nullable|integer',
        ]);

        $site = $this->resolveSite($request);
        if (!$site) {
            return response()->json([
                'success' => false,
                'status' => 400,
                'message' => 'Site not found',
                'data' => null,
            ], 400);
        }

        if (empty($site->db_name)) {
            return response()->json([
                'success' => false,
                'status' => 400,
                'message' => 'Site database not configured',
                'data' => null,
            ], 400);
        }

        $connectionName = $this->siteService->setupTenantConnection($site->db_name);

        $type = (int) ($request->input('type') ?? 2); // 1: feedback, 2: subscribe
        if (!in_array($type, [1, 2], true)) {
            $type = 2;
        }

        $payload = [
            'email' => $request->input('email'),
            'content' => $request->input('content', ''),
            'status' => 1, // 1: success
            'type' => $type,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $id = DB::connection($connectionName)->table('emails')->insertGetId($payload);

        return response()->json([
            'success' => true,
            'status' => 200,
            'message' => null,
            'data' => [
                'id' => $id,
                'email' => $payload['email'],
                'content' => $payload['content'],
                'status' => $payload['status'],
                'type' => $payload['type'],
            ],
        ]);
    }

    private function resolveSite(Request $request): ?Site
    {
        $siteIdentifier = $request->input('site');
        if (!empty($siteIdentifier)) {
            $site = Site::where('id', $siteIdentifier)
                ->orWhere('code', $siteIdentifier)
                ->orWhere('domain_name', $siteIdentifier)
                ->first();
            if ($site) {
                return $site;
            }
        }

        $origin = $request->header('Origin');
        if ($origin) {
            $parsed = parse_url($origin);
            $domain = $parsed['host'] ?? null;
            if ($domain === 'localhost') {
                $domain = 'domain2f.microgem.io.vn';
            }
            if (!empty($domain)) {
                return Site::where('domain_name', $domain)->first();
            }
        }

        return null;
    }
}

