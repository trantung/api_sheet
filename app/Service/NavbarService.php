<?php

namespace App\Service;

use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\Site;
use Illuminate\Support\Facades\DB;

class NavbarService
{
    public function getData($connectionName)
    {
        $data = DB::connection($connectionName)->table('nav_bars')->get();
        return $data;
    }

    public function storeData($connectionName, $data)
    {
        DB::connection($connectionName)->table('nav_bars')->insertGetId([
            "title" => $data['title'],
            "link" => $data['link'],
            "position" => $data['position'],
            "target" => $data['target']
        ]);
        return true;
    }
}
