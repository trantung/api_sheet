<?php

namespace App\Service;

use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

class SiteService
{
    public function setupTenantConnection($dbName)
    {
        $connectionName = 'tenant';

         // Set the configuration for the tenant connection
        Config::set("database.connections.$connectionName", [
            'driver'    => 'mysql',
            'host'      => env('DB_HOST', '127.0.0.1'),
            'port'      => env('DB_PORT', 3306),
            'database'  => $dbName,
            'username'  => env('DB_USERNAME'),
            'password'  => env('DB_PASSWORD'),
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'    => '',
        ]);

        // Clear the previous connection (if any) and reconnect
        DB::purge($connectionName);
        DB::reconnect($connectionName);

        return $connectionName;
    }

    public function getDataIndex($domain)
    {
        $site = Site::where('domain_name', $domain)->first();
        if(!$site) {
            return false;
        }
        $siteData = $site->toArray();
        $siteType = $site->type ?? 1; // Default to 1 (Blog) if not set
        $connectionName = $this->setupTenantConnection($site->db_name);
        $siteConfigs = $this->getConfigSite($connectionName, $siteData);
        $siteInformation =  $this->getSiteInformation($connectionName, $siteData);
        $header = $this->getHeader($connectionName, $siteType);
        $data = [
            'site_informations' => $siteInformation,
            'configs' => $siteConfigs,
            'categories' => $this->getProductsGroupedByCategory($connectionName, $siteType),
            'header' => $header
        ];
        return $data;
    }
    
    public function getNavBars($connectionName)
    {
        $res = [];
        $navBars = DB::connection($connectionName)->table('nav_bars')->where('position', 1)->get();
        foreach($navBars as $value)
        {
            $res[] = [
                'title' => $value->title,
                'nav_bar_id' => $value->id,
                'link' => $value->link,
                'target' => $value->target,
            ];
        }
        return $res;
    }

    public function getPages($connectionName, $siteType)
    {
        $res = [];
        $query = DB::connection($connectionName)->table('pages');

        // Site Type 1 = Blog, 2 = Ecommerce
        if ($siteType == 1) {
            // Blog logic: filter by show_in_header
            if (Schema::connection($connectionName)->hasColumn('pages', 'show_in_header')) {
                $query->where('show_in_header', 1);
            }
        } else {
            // Ecommerce logic: no show_in_header column, get all pages or default filter
            // Assuming we just want all pages for now or filter by another logic if needed
        }
        
        $pages = $query->get();
        
        foreach($pages as $value)
        {
            $res[] = [
                'page_id' => $value->id,
                'title' => $value->title,
                'content' => $value->content,
                'page_address' => $value->page_address,
                'page_width' => $value->page_width ?? null,
                'menu_title' => $value->menu_title ?? null,
                'menu_type' => $value->menu_type,
                'target' => $value->target,
                'meta_title' => $value->meta_title ?? null,
                'meta_description' => $value->meta_description ?? null,
                'image_share_url' => $value->image_share_url ?? ($value->content ?? null),
                'show_in_search' => $value->show_in_search,
                'show_in_header' => isset($value->show_in_header) ? $value->show_in_header : 1,
            ];
        }
        return $res;
    }

    public function getHeader($connectionName, $siteType)
    {
        $res = [
            'nar_bars' => $this->getNavBars($connectionName),
            'pages' => $this->getPages($connectionName, $siteType),
        ];
        return $res;
    }

    public function getProductsGroupedByCategory($connectionName, $siteType)
    {
        if ($siteType == 1) {
            // Schema cho BLOG
            $results = DB::connection($connectionName)->select("
                SELECT
                    c.id AS category_id,
                    c.name AS category_name,
                    p.id AS product_id,
                    p.title,
                    p.slug,
                    p.excerpt,
                    p.thumbnail,
                    p.author,
                    p.content,
                    p.published_date,
                    p.status,
                    cr.id AS relate_category_id,
                    cr.name AS relate_category_name
                FROM categories c
                JOIN product_category pc1 ON c.id = pc1.category_id
                JOIN products p ON pc1.product_id = p.id
                JOIN product_category pc2 ON p.id = pc2.product_id
                JOIN categories cr ON pc2.category_id = cr.id
                ORDER BY c.id, p.id
            ");
        } else {
            // Schema cho ECOMMERCE - format giống blog + thêm price, best_selling, new_arrival
            $results = DB::connection($connectionName)->select("
                SELECT
                    c.id AS category_id,
                    c.name AS category_name,
                    p.id AS product_id,
                    p.name AS title,
                    p.sku AS slug,
                    p.description AS excerpt,
                    p.images AS thumbnail,
                    '' AS author,
                    p.link AS content,
                    p.created_at AS published_date,
                    'Published' AS status,
                    p.price,
                    p.old_price,
                    p.best_selling,
                    p.new_arrival,
                    cr.id AS relate_category_id,
                    cr.name AS relate_category_name
                FROM categories c
                JOIN product_category pc1 ON c.id = pc1.category_id
                JOIN products p ON pc1.product_id = p.id
                JOIN product_category pc2 ON p.id = pc2.product_id
                JOIN categories cr ON pc2.category_id = cr.id
                ORDER BY c.id, p.id
            ");
        }
        
        $data = [];
        foreach ($results as $row) {
            $catId = $row->category_id;
            $productId = $row->product_id;
            if (!isset($data[$catId])) {
                $data[$catId] = [
                    'category_id' => $catId,
                    'category_name' => $row->category_name,
                    'products' => []
                ];
            }
            if (!isset($data[$catId]['products'][$productId])) {
                if ($siteType == 1) {
                    // Blog format
                    $thumbnail = $row->thumbnail;
                    $data[$catId]['products'][$productId] = [
                        'title' => $row->title,
                        'slug' => $row->slug,
                        'excerpt' => $row->excerpt,
                        'thumbnail' => $thumbnail ?? '',
                        'author' => $row->author ?? '',
                        'content' => $row->content,
                        'published_date' => $row->published_date,
                        'status' => $row->status,
                        'categories_relate' => []
                    ];
                } else {
                    // Ecommerce format - giống blog + thêm price, best_selling, new_arrival
                    $thumbnail = $row->thumbnail;
                    // Nếu thumbnail là json images, lấy ảnh đầu tiên
                    if (!empty($thumbnail)) {
                        $decodedImages = json_decode($thumbnail, true);
                        if (is_array($decodedImages) && count($decodedImages) > 0) {
                            $thumbnail = $decodedImages[0];
                        }
                    } else {
                        $thumbnail = '';
                    }
                    
                    $data[$catId]['products'][$productId] = [
                        'title' => $row->title,
                        'slug' => $row->slug,
                        'excerpt' => $row->excerpt,
                        'thumbnail' => $thumbnail,
                        'author' => $row->author ?? '',
                        'content' => $row->content,
                        'published_date' => $row->published_date,
                        'status' => $row->status,
                        'price' => $row->price,
                        'old_price' => $row->old_price ?? null,
                        'best_selling' => $row->best_selling ?? 1,
                        'new_arrival' => $row->new_arrival ?? 1,
                        'categories_relate' => []
                    ];
                }
            }
            $relateId = $row->relate_category_id;
            $relateName = $row->relate_category_name;

            $alreadyAdded = false;
            foreach ($data[$catId]['products'][$productId]['categories_relate'] as $relate) {
                if ($relate['category_id'] == $relateId) {
                    $alreadyAdded = true;
                    break;
                }
            }

            if (!$alreadyAdded) {
                $data[$catId]['products'][$productId]['categories_relate'][] = [
                    'category_id' => $relateId,
                    'category_name' => $relateName
                ];
            }
        }
        $output = [];
        foreach ($data as &$category) {
            $category['products'] = array_values($category['products']);
            $output[] = $category;
        }
        return $output;
    }

    public function getSiteInformation($connectionName, $siteData)
    {
        $check = DB::connection($connectionName)->table('site_informations')->get();
        $res = [];
        foreach($check as $value)
        {
            $res[] = [
                'property' => $value->property,
                'value' => $value->value,
                'code' => $value->code,
            ];
        }
        return $res;
    }

    public function getConfigSite($connectionName, $siteData)
    {
        $res['dark_mode'] = 1; // Show dark mode
        $res['hide_header'] = 1; // Hide header
        $res['hide_footer'] = 1; // Hide footer
        $res['disable_hero'] = 2; // Hide hero
        $res['collect_email'] = 2; // Show collected emails
        $res['about_us'] = 2; // Show the About Us page
        $res['disable_auto_sync'] = 2; // Disable auto-sync
        $res['feedback_form'] = 2; // Show feedback form
        $res['text_center'] = 2; // Text center
        $res['small_hero'] = 1; // Font size
        $res['grid_content'] = 2; // Grid content
        $res['pagination_size'] = 10; // Pagination Size
        $res['font_family'] = "Poppins"; // Font Family
        $res['published'] = 1; // Publish website
        $res['build_on'] = 1;
        $res['disable_detail_page'] = 2;
        $res['disable_toc'] = 2;
        $res['disable_index'] = 2;
        $res['disable_banner'] = 2;

        $check = DB::connection($connectionName)->table('configs')->first();
        if($check)
        {
            $res['name'] = $check->site_name;
            $res['dark_mode'] = $check->dark_mode; // Show dark mode
            $res['hide_header'] = $check->header_is_show; // Hide header
            $res['hide_footer'] = $check->footer_is_show; // Hide footer
            $res['disable_hero'] = $check->hero_section_is_show; // Hide hero
            $res['collect_email'] = $check->email_subscribed; // Show collected emails
            $res['about_us'] = $check->about_us_is_show; // Show the About Us page
            $res['disable_auto_sync'] = $check->disable_auto_sync ?? 2; // Disable auto-sync
            $res['feedback_form'] = $check->feedback_is_show; // Show feedback form
            $res['text_center'] = $check->text_center; // Text center
            $res['small_hero'] = $check->font_size; // Font size
            $res['grid_content'] = $check->grid_content; // Grid content
            $res['pagination_size'] = $check->paginate; // Pagination Size
            $res['font_family'] = $check->font_family; // Font Family
            $res['published'] = $check->site_publish; // Publish website
            $res['build_on'] = $check->remove_icon_build_on;
            $res['disable_detail_page'] = $check->disable_detail_page ?? 2;
            $res['disable_toc'] = $check->disable_toc ?? 2;
            $res['disable_index'] = $check->disable_index ?? 2;
            $res['disable_banner'] = $check->disable_banner ?? 2;
        }

        $siteType = $siteData['type'] ?? 1;
        if ($siteType == 2) { // Ecommerce
            unset($res['grid_content']);
        }

        return $res;
    }
    
    public function commonQuerySearchProduct($connectionName, $dataRequest, $arrayFieldSearch = null, $siteType = 1)
    {
        $keyword = $dataRequest['keyword'] ?? null;
        $slug = $dataRequest['slug'] ?? null;
        $categoryId = $dataRequest['category_id'] ?? null;
        $orderBy = $dataRequest['order_by'] ?? null; // price_asc, price_desc, name_asc, name_desc

        $query = DB::connection($connectionName)
            ->table('products')
            ->leftJoin('product_category', 'products.id', '=', 'product_category.product_id')
            ->leftJoin('categories', 'product_category.category_id', '=', 'categories.id')
            ->select(
                'products.*',
                'categories.id as category_id',
                'categories.name as category_name'
            );
        
        if (!empty($keyword)) {
            if ($siteType == 1) {
                // Blog: search by title
                $query->where('products.title', 'like', '%' . $keyword . '%');
            } else {
                // Ecommerce: search by name
                $query->where('products.name', 'like', '%' . $keyword . '%');
            }
        }

        if (!empty($categoryId)) {
            $query->where('categories.id', $categoryId);
        }

        if (!empty($slug)) {
            if ($siteType == 1) {
                // Blog: search by slug
                $query->where('products.slug', $slug);
            } else {
                // Ecommerce: search by sku (slug is mapped from sku)
                $query->where('products.sku', $slug);
            }
        }

        if($arrayFieldSearch) {
            foreach($arrayFieldSearch as $key => $value)
            {
                 $query->where('products.' . $key, $value);
            }
        }

        if (!empty($orderBy)) {
            switch ($orderBy) {
                case 'price_asc':
                    $query->orderByRaw('CAST(products.price AS DECIMAL(10,2)) ASC');
                    break;
                case 'price_desc':
                    $query->orderByRaw('CAST(products.price AS DECIMAL(10,2)) DESC');
                    break;
                case 'name_asc':
                    $query->orderBy($siteType == 1 ? 'products.title' : 'products.name', 'ASC');
                    break;
                case 'name_desc':
                    $query->orderBy($siteType == 1 ? 'products.title' : 'products.name', 'DESC');
                    break;
                default:
                    $query->orderBy('products.id', 'DESC');
                    break;
            }
        } else {
            $query->orderBy('products.id', 'DESC');
        }

        $rawData = $query->get();
        return $rawData;
    }
    
    public function formatDataProduct($rawData, $siteType = 1)
    {
        $products = [];
        foreach ($rawData as $item) {
            $productId = $item->id;
            if (!isset($products[$productId])) {
                if ($siteType == 1) {
                    // Blog format
                    $products[$productId] = [
                        'id' => $item->id,
                        'title' => $item->title,
                        'slug' => $item->slug,
                        'excerpt' => $item->excerpt,
                        'thumbnail' => $item->thumbnail,
                        'author' => $item->author,
                        'content' => $item->content,
                        'published_date' => $item->published_date,
                        'status' => $item->status,
                        'category_relate' => [],
                    ];
                } else {
                    // Ecommerce format
                    $thumbnail = $item->images ?? '';
                    // If thumbnail is json images, get first image
                    if (!empty($thumbnail)) {
                        $decodedImages = json_decode($thumbnail, true);
                        if (is_array($decodedImages) && count($decodedImages) > 0) {
                            $thumbnail = $decodedImages[0];
                        }
                    } else {
                        $thumbnail = '';
                    }
                    
                    $products[$productId] = [
                        'id' => $item->id,
                        'title' => $item->name ?? '',
                        'slug' => $item->sku ?? '',
                        'excerpt' => $item->description ?? '',
                        'thumbnail' => $thumbnail,
                        'author' => '',
                        'content' => $item->link ?? '',
                        'published_date' => $item->created_at ?? '',
                        'status' => 'Published',
                        'price' => $item->price ?? '',
                        'old_price' => $item->old_price ?? null,
                        'best_selling' => $item->best_selling ?? 1,
                        'new_arrival' => $item->new_arrival ?? 1,
                        'inventory' => $item->inventory ?? 0,
                        'size' => $item->size ?? '',
                        'color' => $item->color ?? '',
                        'material' => $item->material ?? '',
                        'rating' => $item->rating ?? '',
                        'images' => $item->images ?? '',
                        'category_relate' => [],
                    ];
                }
            }
            if ($item->category_id) {
                $products[$productId]['category_relate'][] = [
                    'category_id' => $item->category_id,
                    'category_name' => $item->category_name,
                ];
            }
        }

        $data = array_values($products);
        return $data;
    }

    public function getProductSearch($domain, $dataRequest)
    {
        $site = Site::where('domain_name', $domain)->first();
        if(!$site) {
            return false;
        }
        $siteData = $site->toArray();
        $siteType = $site->type ?? 1; // Default to 1 (Blog) if not set
        $connectionName = $this->setupTenantConnection($site->db_name);
        $rawData = $this->commonQuerySearchProduct($connectionName, $dataRequest, null, $siteType);
        $data = $this->formatDataProduct($rawData, $siteType);
        return $data;
    }

    public function getRelatedProductsFromTenant($productId, $connectionName, $limit = 6, $siteType = 1)
    {
        $categoryIds = DB::connection($connectionName)
            ->table('product_category')
            ->where('product_id', $productId)
            ->pluck('category_id')
            ->toArray();

        if (empty($categoryIds)) return [];

        $randomCategoryIds = collect($categoryIds)->shuffle()->take(2)->toArray();

        $relatedProductIds = DB::connection($connectionName)
            ->table('product_category')
            ->whereIn('category_id', $randomCategoryIds)
            ->where('product_id', '!=', $productId)
            ->pluck('product_id');

        $products = DB::connection($connectionName)
            ->table('products')
            ->whereIn('id', $relatedProductIds)
            ->inRandomOrder()
            ->limit($limit)
            ->get();

        $related = [];

        foreach ($products as $item) {
            $categoryIds = DB::connection($connectionName)
                ->table('product_category')
                ->where('product_id', $item->id)
                ->pluck('category_id')
                ->toArray();

            $categories = DB::connection($connectionName)
                ->table('categories')
                ->whereIn('id', $categoryIds)
                ->get();

            $categoriesFormat = [];
            foreach($categories as $category)
            {
                $categoriesFormat[] = [
                    'category_id' => $category->id,
                    'category_name' => $category->name,
                ];
            }
            
            if ($siteType == 1) {
                // Blog format
                $related[] = [
                    'id' => $item->id,
                    'title' => $item->title,
                    'slug' => $item->slug,
                    'excerpt' => $item->excerpt,
                    'thumbnail' => $item->thumbnail,
                    'author' => $item->author,
                    'content' => $item->content,
                    'published_date' => $item->published_date,
                    'status' => $item->status,
                    'category_relate' => $categoriesFormat
                ];
            } else {
                // Ecommerce format
                $thumbnail = $item->images ?? '';
                // If thumbnail is json images, get first image
                if (!empty($thumbnail)) {
                    $decodedImages = json_decode($thumbnail, true);
                    if (is_array($decodedImages) && count($decodedImages) > 0) {
                        $thumbnail = $decodedImages[0];
                    }
                } else {
                    $thumbnail = '';
                }
                
                $related[] = [
                    'id' => $item->id,
                    'title' => $item->name ?? '',
                    'slug' => $item->sku ?? '',
                    'excerpt' => $item->description ?? '',
                    'thumbnail' => $thumbnail,
                    'author' => '',
                    'content' => $item->link ?? '',
                    'published_date' => $item->created_at ?? '',
                    'status' => 'Published',
                    'price' => $item->price ?? '',
                    'old_price' => $item->old_price ?? null,
                    'best_selling' => $item->best_selling ?? 1,
                    'new_arrival' => $item->new_arrival ?? 1,
                    'category_relate' => $categoriesFormat
                ];
            }
        }

        return $related;
    }

    public function getProductDetail($domain, $dataRequest)
    {
        $site = Site::where('domain_name', $domain)->first();
        if(!$site || !isset($dataRequest['slug'])) {
            return false;
        }
        $siteData = $site->toArray();
        $siteType = $site->type ?? 1; // Default to 1 (Blog) if not set
        $connectionName = $this->setupTenantConnection($site->db_name);
        $rawData = $this->commonQuerySearchProduct($connectionName, $dataRequest, null, $siteType);
        $data = $this->formatDataProduct($rawData, $siteType);
        $result = $data[0];
        $siteInformation =  $this->getSiteInformation($connectionName, $siteData);
        return [
            'detail' => $data,
            'product_relate' => $this->getRelatedProductsFromTenant($result['id'], $connectionName, 6, $siteType),
            'site_informations' => $siteInformation,
        ];
    }
    public function getPageDetail($domain, $dataRequest)
    {
        $site = Site::where('domain_name', $domain)->first();
        if(!$site || !isset($dataRequest['page_address'])) {
            return false;
        }
        $siteData = $site->toArray();
        $connectionName = $this->setupTenantConnection($site->db_name);
        $page = DB::connection($connectionName)
            ->table('pages')
            ->where('page_address', $dataRequest['page_address'])
            ->first();
        if(!$page) {
            return false;
        }
        return (array) $page;
    }
}
